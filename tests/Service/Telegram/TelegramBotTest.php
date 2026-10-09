<?php

namespace App\Tests\Service\Telegram;

use App\Entity\ReceivedInvoice;
use App\Entity\TelegramLink;
use App\Entity\User;
use App\Repository\TelegramLinkRepository;
use App\Service\Accounting\Invoice\InvoiceFileStore;
use App\Service\Ai\GeminiClient;
use App\Service\AppSettings;
use App\Service\Telegram\Destination\InvoiceDestination;
use App\Service\Telegram\Destination\TelegramDestination;
use App\Service\Telegram\TelegramBot;
use App\Service\Telegram\TelegramBotApi;
use App\Service\Telegram\TelegramChat;
use App\Service\Telegram\TelegramClassifier;
use App\Service\Telegram\TelegramInput;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * El bot contra la base de verdad: vincular con la invitación, no atender a
 * desconocidos, dejar las facturas en la bandeja y no guardar lo que no sabe qué es.
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

    /** @var list<string> Lo que el bot ha contestado. */
    private array $replies = [];

    /** @var list<string> Ficheros que ha pedido descargar. */
    private array $downloads = [];

    /** Lo que contesta Gemini al clasificar. */
    private string $classification = TelegramClassifier::NONE;

    /** @var list<int> */
    private array $invoices = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->settings = static::getContainer()->get(AppSettings::class);
        $this->accountingWasOn = $this->settings->getBool(AppSettings::FEATURE_CONTABILIDAD);
        $this->settings->setBool(AppSettings::FEATURE_CONTABILIDAD, true);

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
        $this->bot()->handle($this->update($this->pdfMessage(999000111)));

        $this->assertStringContainsString('sólo atiende a su gente', $this->lastReply());
        $this->assertSame([], $this->downloads);
        $this->assertSame([], $this->invoicesFrom(999000111));
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

        $invoices = $this->invoicesFrom(555000004);
        $this->assertCount(1, $invoices);
        $this->assertSame(ReceivedInvoice::SOURCE_TELEGRAM, $invoices[0]->getSource());
        $this->assertSame('Factura Movistar.pdf', $invoices[0]->getOriginalName());
        $this->assertSame('application/pdf', $invoices[0]->getMimeType());
        $this->assertSame($this->user->getId(), $invoices[0]->getUploadedBy()?->getId());
        $this->assertNotNull(static::getContainer()->get(InvoiceFileStore::class)->pathTo($invoices[0]->getFileName()));
        $this->assertStringContainsString('Recibido «Factura Movistar.pdf»', $this->lastReply());
    }

    /** Las mismas reglas que la subida web: un fichero que no es factura ni foto no se guarda. */
    public function testUnFormatoQueLaWebRechazaTampocoEntraPorTelegram(): void
    {
        $this->linkAs(555000005);

        $this->bot(fileContent: "#!/bin/sh\necho hola\n")->handle($this->update($this->pdfMessage(555000005), 555000005));

        $this->assertSame([], $this->invoicesFrom(555000005));
        $this->assertStringContainsString('formato', $this->lastReply());
    }

    public function testUnTextoContestaConLoQueSePuedeMandar(): void
    {
        $this->linkAs(555000006);

        $this->bot()->handle($this->update(['text' => 'hola'], 555000006));

        $this->assertStringContainsString('Eso no sé qué hacer con ello', $this->lastReply());
        $this->assertStringContainsString('Facturas y tickets', $this->lastReply());
    }

    /** Con contabilidad apagada, una foto no tiene destino: ni se descarga. */
    public function testConContabilidadApagadaNoHayDondeMandarLaFoto(): void
    {
        $this->linkAs(555000007);
        $this->settings->setBool(AppSettings::FEATURE_CONTABILIDAD, false);

        $this->bot()->handle($this->update($this->pdfMessage(555000007), 555000007));

        $this->assertSame([], $this->downloads);
        $this->assertSame([], $this->invoicesFrom(555000007));
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
        $this->assertSame([], $this->invoicesFrom(555000008));
    }

    /** Con varios destinos posibles decide Gemini; si dice que no es de ninguno, no se guarda. */
    public function testSiElClasificadorNoLoReconoceNoSeGuardaEnNingunSitio(): void
    {
        $this->linkAs(555000009);
        $this->classification = TelegramClassifier::NONE;

        $this->bot(withGallery: true)->handle($this->update($this->pdfMessage(555000009), 555000009));

        $this->assertSame([], $this->invoicesFrom(555000009));
        $this->assertStringContainsString('No sé qué es esto', $this->lastReply());
    }

    public function testSiElClasificadorLoReconoceComoFacturaVaALaBandeja(): void
    {
        $this->linkAs(555000010);
        $this->classification = 'invoice';

        $this->bot(withGallery: true)->handle($this->update($this->pdfMessage(555000010), 555000010));

        $this->assertCount(1, $this->invoicesFrom(555000010));
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
            if (str_contains($url, '/sendMessage')) {
                $this->replies[] = json_decode($options['body'], true)['text'];

                return new MockResponse('{"ok":true,"result":{}}');
            }
            if (str_contains($url, '/getFile')) {
                $this->downloads[] = json_decode($options['body'], true)['file_id'];

                return new MockResponse('{"ok":true,"result":{"file_path":"documents/file_1.pdf"}}');
            }
            if (str_contains($url, '/file/bot')) {
                return new MockResponse($fileContent);
            }

            return new MockResponse('{"ok":false,"description":"no simulado"}', ['http_code' => 400]);
        });
        $gemini = new MockHttpClient(fn (): MockResponse => new MockResponse(json_encode([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['destino' => $this->classification])]]]]],
        ])));

        $destinations = [static::getContainer()->get(InvoiceDestination::class)];
        if ($withGallery) {
            $destinations[] = new class implements TelegramDestination {
                public function key(): string
                {
                    return 'gallery';
                }

                public function description(): string
                {
                    return 'Fotos de la huerta para la galería.';
                }

                public function accepts(TelegramInput $input, User $user): bool
                {
                    return $input->hasFile();
                }

                public function handle(TelegramInput $input, TelegramLink $link, TelegramChat $chat): void
                {
                    $chat->say('Guardada en la galería.');
                }

                public function help(User $user): ?string
                {
                    return 'Fotos para la galería.';
                }
            };
        }

        return new TelegramBot(
            new TelegramBotApi($telegram, '123:TOKEN'),
            static::getContainer()->get(TelegramLinkRepository::class),
            $destinations,
            new TelegramClassifier(new GeminiClient($gemini, 'clave', ['modelo-a'])),
            $this->em,
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
     * Las facturas que ha dejado alguien vinculado con esa cuenta (y se apuntan para
     * borrarlas al terminar).
     *
     * @return list<ReceivedInvoice>
     */
    private function invoicesFrom(int $telegramId): array
    {
        $found = $this->em->getRepository(ReceivedInvoice::class)->findBy(['uploadedBy' => $this->user, 'source' => ReceivedInvoice::SOURCE_TELEGRAM]);
        foreach ($found as $invoice) {
            $this->invoices[] = (int) $invoice->getId();
        }

        return $found;
    }

    private function lastReply(): string
    {
        $this->assertNotSame([], $this->replies, 'El bot no ha contestado nada.');

        return (string) end($this->replies);
    }
}
