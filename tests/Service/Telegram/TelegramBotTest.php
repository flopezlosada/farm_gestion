<?php

namespace App\Tests\Service\Telegram;

use App\Entity\ReceivedInvoice;
use App\Entity\TelegramLink;
use App\Entity\TelegramSubmission;
use App\Entity\User;
use App\Repository\TelegramLinkRepository;
use App\Repository\TelegramSubmissionRepository;
use App\Service\Accounting\Invoice\InvoiceFileStore;
use App\Service\Ai\GeminiClient;
use App\Service\AppSettings;
use App\Service\Telegram\Destination\InvoiceDestination;
use App\Service\Telegram\Destination\TelegramDestination;
use App\Service\Telegram\Destination\TelegramOutcome;
use App\Service\Telegram\TelegramBot;
use App\Service\Telegram\TelegramBotApi;
use App\Service\Telegram\TelegramClassifier;
use App\Service\Telegram\TelegramFileStore;
use App\Service\Telegram\TelegramInput;
use App\Service\Telegram\TelegramSubmissionRouter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * El bot contra la base de verdad: vincular con la invitación, no atender a
 * desconocidos, dejar las facturas en la bandeja, preguntar cuando duda y dejar
 * corregirlo con los botones («Es otra cosa», otro apartado, descartar).
 *
 * Telegram y Gemini están simulados; los mensajes tienen la forma real de los de
 * Telegram (fotos en varios tamaños, documentos con su nombre, chats privados y de
 * grupo).
 */
class TelegramBotTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AppSettings $settings;
    private bool $accountingWasOn;
    private User $user;

    /** @var list<string> Lo que el bot ha contestado (mensajes nuevos y cambiados). */
    private array $replies = [];

    /** @var list<list<string>> Los botones de cada respuesta, por su texto. */
    private array $buttons = [];

    /** @var list<string|null> Lo contestado a cada pulsación de botón. */
    private array $answers = [];

    /** Si Gemini dice que está seguro. */
    private bool $sure = true;

    /** Si el apartado de mentira se niega a deshacer. */
    private bool $galleryRefusesUndo = false;

    /** @var list<string> Ficheros que ha pedido descargar. */
    private array $downloads = [];

    /** Lo que contesta Gemini al clasificar. */
    private string $classification = TelegramClassifier::NONE;

    /** @var list<int> */
    private array $invoices = [];

    /** Mensajes ya atendidos: compartido entre los bots de un mismo test, como en la vida real. */
    private ArrayAdapter $seen;

    /** Mensajes por cuenta y hora. */
    private int $limit = 30;

    /** Si el envío a Telegram de la descarga debe fallar con un error nuestro. */
    private bool $breakDownload = false;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->settings = static::getContainer()->get(AppSettings::class);
        $this->accountingWasOn = $this->settings->getBool(AppSettings::FEATURE_CONTABILIDAD);
        $this->settings->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $this->seen = new ArrayAdapter();

        $suffix = bin2hex(random_bytes(4));
        $this->user = (new User())
            ->setUsername('telegram-' . $suffix)
            ->setEmail('telegram-' . $suffix . '@test.org')
            ->setPassword('x')
            ->setEnabled(true)
            ->setPasswordSet(true);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $files = static::getContainer()->get(InvoiceFileStore::class);
        foreach ($this->invoices as $id) {
            $invoice = $this->em->find(ReceivedInvoice::class, $id);
            if ($invoice !== null) {
                $files->discard($invoice->getFileName());
                $this->em->remove($invoice);
            }
        }
        $this->em->flush();
        $telegramFiles = static::getContainer()->get(TelegramFileStore::class);
        foreach ($this->em->getRepository(TelegramSubmission::class)->findBy(['user' => $this->user]) as $submission) {
            $telegramFiles->discard($submission->getFileName());
            $this->em->remove($submission);
        }
        $this->em->flush();
        // La fila del vínculo cae con la persona (ON DELETE CASCADE), pero el
        // EntityManager no lo sabe: se quita a mano para no dejarla gestionada.
        foreach ($this->em->getRepository(TelegramLink::class)->findBy(['user' => $this->user]) as $link) {
            $this->em->remove($link);
        }
        $this->em->remove($this->em->find(User::class, $this->user->getId()));
        $this->em->flush();
        $this->settings->setBool(AppSettings::FEATURE_CONTABILIDAD, $this->accountingWasOn);

        parent::tearDown();
    }

    /** A un bot le puede escribir cualquiera: a un desconocido ni se le descarga lo que mande. */
    public function testAQuienNoEstaVinculadoNoSeLeGuardaNiDescargaNada(): void
    {
        $before = $this->telegramInvoiceCount();

        $this->bot()->handle($this->update($this->pdfMessage(999000111)));

        $this->assertStringContainsString('sólo atiende a su gente', $this->lastReply());
        $this->assertSame([], $this->downloads);
        $this->assertSame($before, $this->telegramInvoiceCount());
    }

    /** Telegram repite la entrega si no le contestan a tiempo: el mismo update_id se atiende una vez. */
    public function testElMismoMensajeRepetidoSeAtiendeUnaSolaVez(): void
    {
        $this->linkAs(555000012);
        $update = $this->update($this->pdfMessage(555000012), 555000012);

        $this->bot()->handle($update);
        $this->bot()->handle($update);

        $this->assertCount(1, $this->invoicesFrom());
        $this->assertCount(1, $this->replies);
    }

    /** Un fallo nuestro al atender no revienta ni se pierde en silencio: se le pide que lo reenvíe. */
    public function testUnFalloAlAtenderSeLeDiceAQuienEscribio(): void
    {
        $this->linkAs(555000013);
        $this->breakDownload = true;

        $this->bot()->handle($this->update($this->pdfMessage(555000013), 555000013));

        $this->assertSame([], $this->invoicesFrom());
        $this->assertStringContainsString('Algo ha fallado', $this->lastReply());
    }

    /** Pasado el límite por hora, no se descarga nada más y se le avisa. */
    public function testPasadoElLimiteNoSeAtiendeMas(): void
    {
        $this->linkAs(555000014);
        $this->limit = 1;
        $bot = $this->bot();

        $bot->handle($this->update($this->pdfMessage(555000014), 555000014));
        $bot->handle($this->update($this->pdfMessage(555000014), 555000014));

        $this->assertCount(1, $this->invoicesFrom());
        $this->assertCount(1, $this->downloads);
        $this->assertStringContainsString('muchas cosas en poco rato', $this->lastReply());
    }

    /** Una cuenta de la web desactivada deja de poder usar el bot aunque siga vinculada. */
    public function testUnaCuentaDesactivadaNoPuedeMandarNada(): void
    {
        $this->linkAs(555000015);
        $this->user->setEnabled(false);
        $this->em->flush();

        $this->bot()->handle($this->update($this->pdfMessage(555000015), 555000015));

        $this->assertSame([], $this->downloads);
        $this->assertSame([], $this->invoicesFrom());
        $this->assertStringContainsString('sólo atiende a su gente', $this->lastReply());
    }

    /** Quitar a alguien del bot surte efecto en el siguiente mensaje. */
    public function testQuitarElVinculoCortaElAccesoAlMomento(): void
    {
        $this->linkAs(555000016);
        $this->em->remove($this->link());
        $this->em->flush();

        $this->bot()->handle($this->update($this->pdfMessage(555000016), 555000016));

        $this->assertSame([], $this->downloads);
        $this->assertStringContainsString('sólo atiende a su gente', $this->lastReply());
    }

    public function testLaInvitacionVinculaLaCuentaDeTelegramYSeGasta(): void
    {
        $code = $this->invite();

        $this->bot()->handle($this->update(['text' => '/start ' . $code], 555000001, 'Ana', 'Pérez'));

        $link = $this->link();
        $this->assertTrue($link->isLinked());
        $this->assertSame('555000001', $link->getTelegramUserId());
        $this->assertSame('Ana Pérez', $link->getTelegramName());
        $this->assertNull($link->getInvitationCode());
        $this->assertStringContainsString('Ya estás dado de alta', $this->lastReply());
        $this->assertStringContainsString('Facturas y tickets', $this->lastReply());

        // Usada, ya no vale para otra cuenta.
        $this->bot()->handle($this->update(['text' => '/start ' . $code], 555000002));
        $this->assertStringContainsString('ya no vale', $this->lastReply());
        $this->assertSame('555000001', $this->link()->getTelegramUserId());
    }

    public function testUnaInvitacionCaducadaNoVincula(): void
    {
        $link = new TelegramLink($this->user);
        $code = $link->invite(new \DateTimeImmutable('-' . (TelegramLink::INVITATION_DAYS + 1) . ' days'));
        $this->em->persist($link);
        $this->em->flush();

        $this->bot()->handle($this->update(['text' => '/start ' . $code], 555000003));

        $this->assertFalse($this->link()->isLinked());
        $this->assertStringContainsString('ya no vale', $this->lastReply());
    }

    /** Con un solo destino posible, va directo y sin preguntar a Gemini. */
    public function testUnPdfDeAlguienVinculadoAcabaEnLaBandejaDeFacturas(): void
    {
        $this->linkAs(555000004);

        $this->bot()->handle($this->update($this->pdfMessage(555000004), 555000004));

        $invoices = $this->invoicesFrom();
        $this->assertCount(1, $invoices);
        $this->assertSame(ReceivedInvoice::SOURCE_TELEGRAM, $invoices[0]->getSource());
        $this->assertSame('Factura Movistar.pdf', $invoices[0]->getOriginalName());
        $this->assertSame('application/pdf', $invoices[0]->getMimeType());
        $this->assertSame($this->user->getId(), $invoices[0]->getUploadedBy()?->getId());
        $this->assertNotNull(static::getContainer()->get(InvoiceFileStore::class)->pathTo($invoices[0]->getFileName()));
        // Sin Gemini en los tests no se lee: lo dice, y ofrece corregirlo.
        $this->assertStringContainsString('Factura «Factura Movistar.pdf» guardada', $this->lastReply());
        $this->assertSame(['✓ Correcto', 'Es otra cosa'], $this->lastButtons());

        $submission = $this->onlySubmission();
        $this->assertTrue($submission->isRouted());
        $this->assertSame('invoice', $submission->getDestination());
        $this->assertSame((string) $invoices[0]->getId(), $submission->getTargetRef());
        $this->assertNotNull(static::getContainer()->get(TelegramFileStore::class)->pathTo($submission->getFileName()), 'el envío guarda su propia copia');
    }

    /** Las mismas reglas que la subida web: un fichero que no es factura ni foto no se guarda. */
    public function testUnFormatoQueLaWebRechazaTampocoEntraPorTelegram(): void
    {
        $this->linkAs(555000005);

        $this->bot(fileContent: "#!/bin/sh\necho hola\n")->handle($this->update($this->pdfMessage(555000005), 555000005));

        $this->assertSame([], $this->invoicesFrom());
        $this->assertStringContainsString('formato', $this->lastReply());
        $this->assertSame([], $this->submissionsFrom(), 'ni se guarda una copia: a un bot le escribe cualquiera vinculado');
    }

    public function testUnTextoContestaConLoQueSePuedeMandar(): void
    {
        $this->linkAs(555000006);

        $this->bot()->handle($this->update(['text' => 'hola'], 555000006));

        $this->assertStringContainsString('Eso no sé qué hacer con ello', $this->lastReply());
        $this->assertStringContainsString('Facturas y tickets', $this->lastReply());
        $this->assertSame([], $this->submissionsFrom(), 'lo que nadie puede recibir no se guarda');
    }

    /** Con contabilidad apagada, una foto no tiene destino: ni se descarga. */
    public function testConContabilidadApagadaNoHayDondeMandarLaFoto(): void
    {
        $this->linkAs(555000007);
        $this->settings->setBool(AppSettings::FEATURE_CONTABILIDAD, false);

        $this->bot()->handle($this->update($this->pdfMessage(555000007), 555000007));

        $this->assertSame([], $this->downloads);
        $this->assertSame([], $this->invoicesFrom());
        $this->assertStringContainsString('no tengo nada encendido', $this->lastReply());
    }

    /** En un grupo, el bot no atiende a nadie, ni contesta. */
    public function testEnUnGrupoNoHaceNada(): void
    {
        $this->linkAs(555000008);
        $update = $this->update($this->pdfMessage(555000008), 555000008);
        $update['message']['chat'] = ['id' => -100123, 'type' => 'group'];

        $this->bot()->handle($update);

        $this->assertSame([], $this->replies);
        $this->assertSame([], $this->invoicesFrom());
    }

    /** Si el clasificador no sabe qué es, no lo guarda en ningún apartado: pregunta, y queda pendiente. */
    public function testSiElClasificadorNoLoReconocePregunta(): void
    {
        $this->linkAs(555000009);
        $this->classification = TelegramClassifier::NONE;

        $this->bot(withGallery: true)->handle($this->update($this->pdfMessage(555000009), 555000009));

        $this->assertSame([], $this->invoicesFrom());
        $this->assertStringContainsString('No sé qué es esto', $this->lastReply());
        $this->assertSame(['Factura recibida', 'Galería', 'Nada de esto: descártalo'], $this->lastButtons());
        $this->assertTrue($this->onlySubmission()->isPending());
    }

    public function testSiElClasificadorLoTieneClaroVaALaBandeja(): void
    {
        $this->linkAs(555000010);
        $this->classification = 'invoice';

        $this->bot(withGallery: true)->handle($this->update($this->pdfMessage(555000010), 555000010));

        $this->assertCount(1, $this->invoicesFrom());
    }

    /** Si cree que es una factura pero duda, no la guarda: pregunta, con su apuesta primero. */
    public function testSiElClasificadorDudaPreguntaConSuApuestaPrimero(): void
    {
        $this->linkAs(555000017);
        $this->classification = 'gallery';
        $this->sure = false;

        $this->bot(withGallery: true)->handle($this->update($this->pdfMessage(555000017), 555000017));

        $this->assertSame([], $this->invoicesFrom());
        $this->assertStringContainsString('Creo que es «Galería»', $this->lastReply());
        $this->assertSame(['Galería', 'Factura recibida', 'Nada de esto: descártalo'], $this->lastButtons());
        $this->assertTrue($this->onlySubmission()->isPending());
    }

    /** Un pendiente se coloca con su botón. */
    public function testElBotonDeUnPendienteLoColoca(): void
    {
        $this->linkAs(555000018);
        $this->classification = TelegramClassifier::NONE;
        $bot = $this->bot(withGallery: true);
        $bot->handle($this->update($this->pdfMessage(555000018), 555000018));
        $id = (int) $this->onlySubmission()->getId();

        $bot->handle($this->press(555000018, 's:' . $id . ':r:invoice'));

        $this->assertCount(1, $this->invoicesFrom());
        $this->assertSame('invoice', $this->onlySubmission()->getDestination());
        $this->assertSame([null], $this->answers);
        $this->assertSame(['✓ Correcto', 'Es otra cosa'], $this->lastButtons());
    }

    /** «Es otra cosa» ofrece los otros apartados; elegir uno saca la factura de la bandeja. */
    public function testEsOtraCosaLoCambiaDeApartado(): void
    {
        $this->linkAs(555000019);
        $this->classification = 'invoice';
        $bot = $this->bot(withGallery: true);
        $bot->handle($this->update($this->pdfMessage(555000019), 555000019));
        $id = (int) $this->onlySubmission()->getId();
        $this->assertCount(1, $this->invoicesFrom());

        $bot->handle($this->press(555000019, 's:' . $id . ':o'));
        $this->assertSame(['Galería', 'Nada de esto: descártalo', '« Estaba bien'], $this->lastButtons());

        $bot->handle($this->press(555000019, 's:' . $id . ':r:gallery'));

        $this->assertSame([], $this->invoicesFrom(), 'la factura sale de la bandeja');
        $submission = $this->onlySubmission();
        $this->assertSame('gallery', $submission->getDestination());
        $this->assertSame('Guardada en la galería.', $submission->getSummary());
    }

    /** «Correcto» quita los botones y deja lo colocado donde estaba. */
    public function testCorrectoQuitaLosBotonesSinTocarLoColocado(): void
    {
        $this->linkAs(555000023);
        $bot = $this->bot();
        $bot->handle($this->update($this->pdfMessage(555000023), 555000023));
        $submission = $this->onlySubmission();
        $id = (int) $submission->getId();

        $bot->handle($this->press(555000023, 's:' . $id . ':c'));

        $this->assertSame([], $this->lastButtons());
        $this->assertSame($submission->getSummary(), $this->lastReply(), 'el mensaje sigue diciendo lo entendido');
        $this->assertSame([null], $this->answers);
        $this->assertTrue($this->onlySubmission()->isRouted());
        $this->assertCount(1, $this->invoicesFrom());
    }

    /** Descartar algo ya colocado lo deshace allí. */
    public function testDescartarLoQueYaEstabaColocadoLoDeshace(): void
    {
        $this->linkAs(555000020);
        $bot = $this->bot();
        $bot->handle($this->update($this->pdfMessage(555000020), 555000020));
        $id = (int) $this->onlySubmission()->getId();

        $bot->handle($this->press(555000020, 's:' . $id . ':x'));

        $this->assertSame([], $this->invoicesFrom());
        $this->assertSame(TelegramSubmission::STATUS_DISCARDED, $this->onlySubmission()->getStatus());
        $this->assertStringContainsString('no lo guardo', $this->lastReply());
    }

    /** Si el apartado ya no deja deshacerlo, no se mueve y se dice por qué. */
    public function testSiNoSePuedeDeshacerNoSeMueve(): void
    {
        $this->linkAs(555000021);
        $this->classification = 'gallery';
        $this->galleryRefusesUndo = true;
        $bot = $this->bot(withGallery: true);
        $bot->handle($this->update($this->pdfMessage(555000021), 555000021));
        $id = (int) $this->onlySubmission()->getId();

        $bot->handle($this->press(555000021, 's:' . $id . ':r:invoice'));

        $this->assertSame(['Ya está publicada.'], $this->answers);
        $this->assertSame('gallery', $this->onlySubmission()->getDestination());
        $this->assertSame([], $this->invoicesFrom());
    }

    /** Los botones de un envío sólo valen para quien lo mandó. */
    public function testLosBotonesDeOtraPersonaNoHacenNada(): void
    {
        $this->linkAs(555000022);
        $bot = $this->bot();
        $bot->handle($this->update($this->pdfMessage(555000022), 555000022));
        $id = (int) $this->onlySubmission()->getId();

        $bot->handle($this->press(999000222, 's:' . $id . ':x'));

        $this->assertSame(['Este botón ya no sirve.'], $this->answers);
        $this->assertTrue($this->onlySubmission()->isRouted());
        $this->assertCount(1, $this->invoicesFrom());
    }

    /** Una cuenta de Telegram es de una persona: la invitación nueva se la lleva. */
    public function testUnaCuentaYaVinculadaPasaAQuienLaInviteDeNuevo(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $other = (new User())->setUsername('telegram-otra-' . $suffix)->setEmail('telegram-otra-' . $suffix . '@test.org')->setPassword('x')->setEnabled(true)->setPasswordSet(true);
        $this->em->persist($other);
        $previous = new TelegramLink($other);
        $previous->link('555000011', null, new \DateTimeImmutable());
        $this->em->persist($previous);
        $this->em->flush();

        try {
            $this->bot()->handle($this->update(['text' => '/start ' . $this->invite()], 555000011));

            $this->assertSame('555000011', $this->link()->getTelegramUserId());
            $this->assertNull(static::getContainer()->get(TelegramLinkRepository::class)->findForUser($other));
        } finally {
            $this->em->remove($this->em->find(User::class, $other->getId()));
            $this->em->flush();
        }
    }

    /**
     * El bot con Telegram y Gemini simulados y los destinos reales (más, si se
     * pide, uno de mentira para obligar a clasificar).
     */
    private function bot(string $fileContent = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n", bool $withGallery = false): TelegramBot
    {
        $telegram = new MockHttpClient(function (string $method, string $url, array $options) use ($fileContent): MockResponse {
            if (str_contains($url, '/sendMessage') || str_contains($url, '/editMessageText')) {
                $body = json_decode($options['body'], true);
                $this->replies[] = $body['text'];
                $this->buttons[] = array_merge(...array_map(
                    static fn (array $row): array => array_column($row, 'text'),
                    $body['reply_markup']['inline_keyboard'] ?? [],
                ));

                return new MockResponse('{"ok":true,"result":{"message_id":' . \count($this->replies) . '}}');
            }
            if (str_contains($url, '/answerCallbackQuery')) {
                $this->answers[] = json_decode($options['body'], true)['text'] ?? null;

                return new MockResponse('{"ok":true,"result":true}');
            }
            if (str_contains($url, '/getFile')) {
                $this->downloads[] = json_decode($options['body'], true)['file_id'];

                return new MockResponse('{"ok":true,"result":{"file_path":"documents/file_1.pdf"}}');
            }
            if (str_contains($url, '/file/bot')) {
                if ($this->breakDownload) {
                    // Un fallo que no es de Telegram ni de la red: lo que no se espera.
                    throw new \LogicException('disco lleno');
                }

                return new MockResponse($fileContent);
            }

            return new MockResponse('{"ok":false,"description":"no simulado"}', ['http_code' => 400]);
        });
        $gemini = new MockHttpClient(fn (): MockResponse => new MockResponse(json_encode([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['destino' => $this->classification, 'seguro' => $this->sure])]]]]],
        ])));

        $destinations = [static::getContainer()->get(InvoiceDestination::class)];
        if ($withGallery) {
            $destinations[] = new class($this->galleryRefusesUndo) implements TelegramDestination {
                public function __construct(private readonly bool $refusesUndo)
                {
                }

                public function key(): string
                {
                    return 'gallery';
                }

                public function label(): string
                {
                    return 'Galería';
                }

                public function description(): string
                {
                    return 'Fotos de la huerta para la galería.';
                }

                public function accepts(TelegramInput $input, User $user): bool
                {
                    return $input->hasFile();
                }

                public function commit(TelegramInput $input, User $user): TelegramOutcome
                {
                    return new TelegramOutcome('g1', 'Guardada en la galería.');
                }

                public function undo(string $ref): ?string
                {
                    return $this->refusesUndo ? 'Ya está publicada.' : null;
                }

                public function help(User $user): ?string
                {
                    return 'Fotos para la galería.';
                }
            };
        }

        $api = new TelegramBotApi($telegram, '123:TOKEN');

        return new TelegramBot(
            $api,
            static::getContainer()->get(TelegramLinkRepository::class),
            $destinations,
            new TelegramClassifier(new GeminiClient($gemini, 'clave', ['modelo-a'])),
            new TelegramSubmissionRouter($destinations, static::getContainer()->get(TelegramFileStore::class), $api, $this->em, new NullLogger()),
            static::getContainer()->get(TelegramSubmissionRepository::class),
            $this->em,
            $this->seen,
            new RateLimiterFactory(['id' => 'telegram_test', 'policy' => 'sliding_window', 'limit' => $this->limit, 'interval' => '1 hour'], new InMemoryStorage()),
            new NullLogger(),
        );
    }

    /**
     * Un update de Telegram de un chat privado.
     *
     * @param array<string, mixed> $message Contenido del mensaje.
     *
     * @return array<string, mixed>
     */
    private function update(array $message, int $from = 999000111, string $firstName = 'Prueba', ?string $lastName = null): array
    {
        return [
            'update_id' => random_int(1, 1_000_000),
            'message' => $message + [
                'message_id' => random_int(1, 1_000_000),
                'date' => time(),
                'from' => array_filter(['id' => $from, 'is_bot' => false, 'first_name' => $firstName, 'last_name' => $lastName], static fn ($v) => $v !== null),
                'chat' => ['id' => $from, 'type' => 'private', 'first_name' => $firstName],
            ],
        ];
    }

    /**
     * Una pulsación de botón en el chat privado de `$from`.
     *
     * @return array<string, mixed>
     */
    private function press(int $from, string $data): array
    {
        return [
            'update_id' => random_int(1, 1_000_000),
            'callback_query' => [
                'id' => 'cb' . random_int(1, 1_000_000),
                'from' => ['id' => $from, 'is_bot' => false, 'first_name' => 'Prueba'],
                'message' => ['message_id' => 1, 'chat' => ['id' => $from, 'type' => 'private']],
                'data' => $data,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function pdfMessage(int $from): array
    {
        return ['document' => ['file_id' => 'BQACAgQAAxkBAAI' . $from, 'file_unique_id' => 'AgAD' . $from, 'file_name' => 'Factura Movistar.pdf', 'mime_type' => 'application/pdf', 'file_size' => 60]];
    }

    private function invite(): string
    {
        $link = static::getContainer()->get(TelegramLinkRepository::class)->findForUser($this->user) ?? new TelegramLink($this->user);
        $code = $link->invite(new \DateTimeImmutable());
        $this->em->persist($link);
        $this->em->flush();

        return $code;
    }

    private function linkAs(int $telegramId): void
    {
        $link = new TelegramLink($this->user);
        $link->link((string) $telegramId, 'Prueba', new \DateTimeImmutable());
        $this->em->persist($link);
        $this->em->flush();
    }

    private function link(): TelegramLink
    {
        $this->em->clear();
        $this->user = $this->em->find(User::class, $this->user->getId());

        return static::getContainer()->get(TelegramLinkRepository::class)->findForUser($this->user);
    }

    /**
     * Las facturas que ha dejado por Telegram la persona del test (y se apuntan para
     * borrarlas al terminar).
     *
     * @return list<ReceivedInvoice>
     */
    private function invoicesFrom(): array
    {
        $found = $this->em->getRepository(ReceivedInvoice::class)->findBy(['uploadedBy' => $this->user, 'source' => ReceivedInvoice::SOURCE_TELEGRAM]);
        foreach ($found as $invoice) {
            $this->invoices[] = (int) $invoice->getId();
        }

        return $found;
    }

    /** Todas las facturas entradas por Telegram, de quien sea. */
    private function telegramInvoiceCount(): int
    {
        return $this->em->getRepository(ReceivedInvoice::class)->count(['source' => ReceivedInvoice::SOURCE_TELEGRAM]);
    }

    /**
     * Los envíos de la persona del test, releídos de la base.
     *
     * @return list<TelegramSubmission>
     */
    private function submissionsFrom(): array
    {
        $this->em->clear();
        $this->user = $this->em->find(User::class, $this->user->getId());

        return $this->em->getRepository(TelegramSubmission::class)->findBy(['user' => $this->user]);
    }

    private function onlySubmission(): TelegramSubmission
    {
        $found = $this->submissionsFrom();
        $this->assertCount(1, $found);

        return $found[0];
    }

    /** @return list<string> */
    private function lastButtons(): array
    {
        $this->assertNotSame([], $this->buttons, 'El bot no ha contestado nada.');

        return end($this->buttons);
    }

    private function lastReply(): string
    {
        $this->assertNotSame([], $this->replies, 'El bot no ha contestado nada.');

        return (string) end($this->replies);
    }
}
