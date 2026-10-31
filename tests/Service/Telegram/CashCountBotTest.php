<?php

namespace App\Tests\Service\Telegram;

use App\Entity\CashCount;
use App\Entity\FinancialAccount;
use App\Entity\TelegramLink;
use App\Entity\TelegramSubmission;
use App\Entity\User;
use App\Repository\TelegramLinkRepository;
use App\Repository\TelegramSubmissionRepository;
use App\Service\Ai\GeminiClient;
use App\Service\AppSettings;
use App\Service\Telegram\Destination\CashCountDestination;
use App\Service\Telegram\Destination\TelegramDestination;
use App\Service\Telegram\Destination\TelegramOutcome;
use App\Service\Telegram\TelegramBot;
use App\Service\Telegram\TelegramBotApi;
use App\Service\Telegram\TelegramClassifier;
use App\Service\Telegram\TelegramFileStore;
use App\Service\Telegram\TelegramInput;
use App\Service\Telegram\TelegramPermissions;
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
 * El arqueo de caja por el bot, de punta a punta contra la base de verdad: se
 * reconoce por cómo está escrito (sin pasar por Gemini aunque haya otro apartado que
 * acepte textos), contesta si cuadra, rechaza lo dudoso sin ofrecer otros apartados, y
 * sólo lo usa quien tiene el permiso.
 *
 * Telegram y Gemini están simulados, con mensajes de la forma real.
 */
class CashCountBotTest extends KernelTestCase
{
    private const TELEGRAM_ID = 555000777;

    private EntityManagerInterface $em;
    private AppSettings $settings;
    private bool $accountingWasOn;
    private User $user;
    private FinancialAccount $garden;
    private string $tag;

    /** @var list<string> */
    private array $replies = [];

    /** @var list<list<string>> */
    private array $buttons = [];

    /** Cuántas veces se le ha preguntado a Gemini. */
    private int $geminiCalls = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->settings = static::getContainer()->get(AppSettings::class);
        $this->accountingWasOn = $this->settings->getBool(AppSettings::FEATURE_CONTABILIDAD);
        $this->settings->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $this->tag = 't' . bin2hex(random_bytes(3));

        $this->user = (new User())
            ->setUsername('arqueo-' . $this->tag)
            ->setEmail('arqueo-' . $this->tag . '@test.org')
            ->setPassword('x')
            ->setEnabled(true)
            ->setPasswordSet(true)
            ->setRoles(['ROLE_BOT_CASH_COUNT', 'ROLE_BOT_NOTES']);
        $this->garden = (new FinancialAccount())->setName('Caja huerta ' . $this->tag)->setKind(FinancialAccount::KIND_CASH)
            ->setOpeningBalance('185.30')->setOpeningDate(new \DateTimeImmutable('2020-01-01'))->setActive(true)->setSortOrder(99);
        $this->em->persist($this->user);
        $this->em->persist($this->garden);
        $this->em->flush();

        $link = new TelegramLink($this->user);
        $link->link((string) self::TELEGRAM_ID, 'Prueba', new \DateTimeImmutable());
        $this->em->persist($link);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach ($this->em->getRepository(CashCount::class)->findBy(['account' => $this->garden->getId()]) as $count) {
            $this->em->remove($count);
        }
        foreach ($this->em->getRepository(TelegramSubmission::class)->findBy(['user' => $this->user->getId()]) as $submission) {
            $this->em->remove($submission);
        }
        foreach ($this->em->getRepository(TelegramLink::class)->findBy(['user' => $this->user->getId()]) as $link) {
            $this->em->remove($link);
        }
        $this->em->flush();
        $this->em->remove($this->em->find(FinancialAccount::class, $this->garden->getId()));
        $this->em->remove($this->em->find(User::class, $this->user->getId()));
        $this->em->flush();
        $this->settings->setBool(AppSettings::FEATURE_CONTABILIDAD, $this->accountingWasOn);

        parent::tearDown();
    }

    /** «caja huerta 185,30» cuadra con una caja sin apuntes: se registra y se dice, sin preguntar a Gemini. */
    public function testUnArqueoQueCuadraSeRegistraSinClasificar(): void
    {
        $this->bot()->handle($this->update(sprintf('caja huerta %s 185,30', $this->tag)));

        $this->assertSame(0, $this->geminiCalls);
        $this->assertStringContainsString('contados 185,30 €. En el libro: 185,30 €. Cuadra: queda registrado.', $this->lastReply());
        $counts = $this->counts();
        $this->assertCount(1, $counts);
        $this->assertSame('185.30', $counts[0]->getCounted());
        $this->assertSame($this->user->getId(), $counts[0]->getCountedBy()?->getId());
        $this->assertSame(sprintf('caja huerta %s 185,30', $this->tag), $counts[0]->getMessageText());
    }

    /** Si no cuadra, queda registrado igual y se dice cuánto falta. */
    public function testUnArqueoQueNoCuadraTambienSeRegistra(): void
    {
        $this->bot()->handle($this->update(sprintf('Caja Huerta %s 170 €', strtoupper($this->tag))));

        $this->assertStringContainsString('No cuadra: faltan 15,30 €.', $this->lastReply());
        $this->assertCount(1, $this->counts());
        $this->assertSame('-15.30', $this->counts()[0]->getDifference());
    }

    /**
     * Telegram repite el mensaje y el bot ya no lo recuerda (la caché se vació con un
     * despliegue): el `update_id` impide un arqueo duplicado.
     */
    public function testElMismoMensajeRepetidoNoDuplicaElArqueo(): void
    {
        $update = $this->update(sprintf('caja huerta %s 185,30', $this->tag));

        $this->bot()->handle($update);
        $this->bot()->handle($update);

        $this->assertCount(1, $this->counts());
        $this->assertSame($update['update_id'], $this->counts()[0]->getTelegramUpdateId());
    }

    /** Un importe dudoso se rechaza diciendo cómo escribirlo, sin ofrecer otros apartados ni guardar nada. */
    public function testUnImporteDudosoSeRechazaSinOfrecerOtrosApartados(): void
    {
        $this->bot()->handle($this->update(sprintf('caja huerta %s 1,853', $this->tag)));

        $this->assertStringContainsString('coma decimal', $this->lastReply());
        $this->assertSame([], end($this->buttons), 'sin botones de otros apartados');
        $this->assertSame([], $this->counts());
        $this->assertSame(0, $this->geminiCalls);
    }

    /** Una caja que no existe: se contesta con las abiertas. */
    public function testUnaCajaQueNoExisteListaLasAbiertas(): void
    {
        $this->bot()->handle($this->update(sprintf('caja %s inventada 10', $this->tag)));

        $this->assertStringContainsString('No tengo ninguna caja que se llame', $this->lastReply());
        $this->assertStringContainsString('«Caja huerta ' . $this->tag . '»', $this->lastReply());
    }

    /** Un mensaje editado no es un mensaje nuevo: no se atiende. */
    public function testUnMensajeEditadoSeIgnora(): void
    {
        $update = $this->update(sprintf('caja huerta %s 185,30', $this->tag));
        $update['edited_message'] = $update['message'];
        unset($update['message']);

        $this->bot()->handle($update);

        $this->assertSame([], $this->replies);
        $this->assertSame([], $this->counts());
    }

    /** Sin el permiso del arqueo, el bot no lo reconoce ni lo registra. */
    public function testSinPermisoNoSeRegistra(): void
    {
        $this->user->setRoles([]);
        $this->em->flush();

        $this->bot()->handle($this->update(sprintf('caja huerta %s 185,30', $this->tag)));

        $this->assertStringContainsString('Eso no sé qué hacer con ello', $this->lastReply());
        $this->assertSame([], $this->counts());
    }

    /** La ayuda lo explica a quien puede usarlo. */
    public function testLaAyudaExplicaComoMandarlo(): void
    {
        $this->bot()->handle($this->update('/start'));

        $this->assertStringContainsString('Arqueo de caja: cuenta el dinero', $this->lastReply());
    }

    private function bot(): TelegramBot
    {
        $telegram = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_contains($url, '/sendMessage') || str_contains($url, '/editMessageText')) {
                $body = json_decode($options['body'], true);
                $this->replies[] = $body['text'];
                $this->buttons[] = array_merge([], ...array_map(
                    static fn (array $row): array => array_column($row, 'text'),
                    $body['reply_markup']['inline_keyboard'] ?? [],
                ));

                return new MockResponse('{"ok":true,"result":{"message_id":' . \count($this->replies) . '}}');
            }

            return new MockResponse('{"ok":true,"result":true}');
        });
        $gemini = new MockHttpClient(function (): MockResponse {
            ++$this->geminiCalls;

            return new MockResponse(json_encode([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['destino' => 'notes', 'seguro' => true])]]]]],
            ]));
        });

        // Otro apartado que acepta cualquier texto, como los traspasos dictados: el
        // arqueo no tiene que competir con él.
        $notes = new class() implements TelegramDestination {
            public function key(): string
            {
                return 'notes';
            }

            public function section(): string
            {
                return 'contabilidad';
            }

            public function label(): string
            {
                return 'Notas';
            }

            public function description(): string
            {
                return 'Cualquier nota escrita.';
            }

            public function accepts(TelegramInput $input, User $user): bool
            {
                return $input->kind === TelegramInput::TEXT && !str_starts_with($input->text, '/');
            }

            public function commit(TelegramInput $input, User $user): TelegramOutcome
            {
                return new TelegramOutcome('n1', 'Nota guardada.');
            }

            public function undo(string $ref): ?string
            {
                return null;
            }

            public function help(User $user): ?string
            {
                return null;
            }
        };

        $c = static::getContainer();
        $api = new TelegramBotApi($telegram, '123:TOKEN');

        return new TelegramBot(
            $api,
            $c->get(TelegramLinkRepository::class),
            new TelegramClassifier(new GeminiClient($gemini, 'clave', ['modelo-a'])),
            new TelegramSubmissionRouter([$c->get(CashCountDestination::class), $notes], $c->get(TelegramFileStore::class), $api, $this->em, new NullLogger(), $c->get(TelegramPermissions::class)),
            $c->get(TelegramSubmissionRepository::class),
            $this->em,
            // Cada bot con su caché: como si se hubiera vaciado entre una entrega y otra.
            new ArrayAdapter(),
            new RateLimiterFactory(['id' => 'telegram_cash_test', 'policy' => 'sliding_window', 'limit' => 30, 'interval' => '1 hour'], new InMemoryStorage()),
            new NullLogger(),
        );
    }

    /**
     * Un mensaje de texto del chat privado de la persona del test.
     *
     * @return array<string, mixed>
     */
    private function update(string $text): array
    {
        return [
            'update_id' => random_int(800_000_000, 899_999_999),
            'message' => [
                'message_id' => random_int(1, 1_000_000),
                'date' => time(),
                'from' => ['id' => self::TELEGRAM_ID, 'is_bot' => false, 'first_name' => 'Prueba'],
                'chat' => ['id' => self::TELEGRAM_ID, 'type' => 'private', 'first_name' => 'Prueba'],
                'text' => $text,
            ],
        ];
    }

    /** @return list<CashCount> Los arqueos de la caja del test, releídos de la base. */
    private function counts(): array
    {
        $this->em->clear();

        return $this->em->getRepository(CashCount::class)->findBy(['account' => $this->garden->getId()]);
    }

    private function lastReply(): string
    {
        $this->assertNotSame([], $this->replies, 'El bot no ha contestado nada.');

        return (string) end($this->replies);
    }
}
