<?php

namespace App\Tests\Service\ConsumerGroup;

use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\Producer;
use App\Entity\User;
use App\Repository\PartnerRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\PartnerAccessPolicy;
use App\Service\AppSettings;
use App\Service\ConsumerGroup\ConsumerGroupAnnouncer;
use App\Service\Cron\EffectLedger;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\NotificationPreferences;
use App\Service\Notification\NotificationTopic;
use App\Service\Push\PushSender;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Unit test del aviso de apertura de un pedido del grupo de consumo.
 *
 * Lo que se protege aquí es lo que decide si el módulo sirve para algo: que el
 * aviso salga por las tres vías, que respete lo que cada socix ha silenciado,
 * que el interruptor general de correo no tumbe también la bandeja y el móvil, y
 * que no se pueda repetir sin pedirlo.
 */
class ConsumerGroupAnnouncerTest extends TestCase
{
    private ConsumerGroupRound $round;

    /** @var list<Partner> */
    private array $active = [];

    protected function setUp(): void
    {
        $producer = new Producer();
        $round = new ConsumerGroupRound();
        $round->setProducer($producer);
        $round->setTitle('Aceite de la cooperativa');
        $round->setOrdersCloseAt(new \DateTime('2026-10-01 23:59'));

        $product = (new ConsumerGroupProduct())->setName('Aceite')->setUnit('garrafa de 5 L');
        $producer->addProduct($product);
        $round->addItem(new ConsumerGroupRoundItem($round, $product, '25.00'));

        $this->round = $round;
        $this->active = [$this->partner('una@example.com'), $this->partner('otra@example.com')];
    }

    public function testNoSeAvisaDeUnPedidoSinProductos(): void
    {
        $round = new ConsumerGroupRound();
        $round->setProducer(new Producer());

        self::assertFalse($this->announcer()->canAnnounce($round));
    }

    public function testNoSeAvisaDeUnPedidoQueYaNoAdmiteApuntes(): void
    {
        $this->round->setStatus(ConsumerGroupRound::STATUS_CLOSED);

        self::assertFalse($this->announcer()->canAnnounce($this->round));
    }

    public function testAvisaPorLasTresViasYDejaConstanciaEnElPedido(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::exactly(2))->method('send');

        $push = $this->createMock(PushSender::class);
        $push->expects(self::once())->method('sendToMany')->willReturn(2);

        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects(self::once())->method('deliver')
            ->with(self::anything(), Notification::KIND_CONSUMER_GROUP_OPEN)
            ->willReturn(2);

        $result = $this->announcer(mailer: $mailer, push: $push, inbox: $inbox)->announce($this->round);

        self::assertSame(['inbox' => 2, 'email' => 2, 'push' => 2], $result);
        self::assertNotNull($this->round->getAnnouncedAt(), 'El pedido tiene que quedar marcado como avisado.');
    }

    public function testConElCorreoApagadoSiguenSaliendoLaBandejaYElMovil(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $push = $this->createMock(PushSender::class);
        $push->expects(self::once())->method('sendToMany')->willReturn(2);

        $result = $this->announcer(mailer: $mailer, push: $push, emailEnabled: false)->announce($this->round);

        self::assertSame(0, $result['email']);
        self::assertSame(2, $result['push']);
    }

    public function testNoSeEscribeAQuienHaSilenciadoElTema(): void
    {
        // Silenciado el correo, no el móvil: son dos listas distintas a propósito.
        $preferences = $this->createMock(NotificationPreferences::class);
        $preferences->method('filter')->willReturnCallback(
            fn (array $partners, string $topic, string $channel): array => NotificationTopic::CHANNEL_EMAIL === $channel ? [] : $partners
        );

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $push = $this->createMock(PushSender::class);
        $push->expects(self::once())->method('sendToMany')->willReturn(2);

        $result = $this->announcer(mailer: $mailer, push: $push, preferences: $preferences)->announce($this->round);

        self::assertSame(0, $result['email']);
        self::assertSame(2, $result['push']);
    }

    public function testUnCorreoQueFallaNoAbortaElResto(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $calls = 0;
        $mailer->expects(self::exactly(2))->method('send')->willReturnCallback(function () use (&$calls): void {
            if (1 === ++$calls) {
                throw new TransportException('SMTP caído');
            }
        });

        $result = $this->announcer(mailer: $mailer)->announce($this->round);

        self::assertSame(1, $result['email'], 'El segundo correo tiene que salir aunque el primero falle.');
    }

    public function testLoQueYaConstaEnviadoNoSeRepite(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        // Un ledger que dice "esto ya se emitió": ni ejecuta el efecto ni lo cuenta.
        $ledger = $this->createMock(EffectLedger::class);
        $ledger->method('once')->willReturn(false);

        $result = $this->announcer(mailer: $mailer, ledger: $ledger)->announce($this->round);

        self::assertSame(['inbox' => 0, 'email' => 0, 'push' => 0], $result);
    }

    /**
     * La fecha de negocio del apunte es la de CREACIÓN del pedido y no la de hoy.
     *
     * Forma parte de la clave del registro de efectos: con la de hoy, el mismo
     * aviso del mismo pedido se podría mandar entero otra vez mañana, que es
     * justo lo que el registro tiene que impedir.
     */
    public function testElApunteSeFechaConLaCreacionDelPedidoYNoConHoy(): void
    {
        $created = new \DateTime('2026-09-01 10:00');
        $this->setCreated($this->round, $created);

        $seen = [];
        $ledger = $this->createMock(EffectLedger::class);
        $ledger->method('once')->willReturnCallback(
            function (string $kind, string $reference, \DateTimeInterface $occurredOn) use (&$seen): bool {
                $seen[] = $occurredOn->format('Y-m-d');

                return true;
            }
        );

        $this->announcer(ledger: $ledger)->announce($this->round);

        self::assertNotEmpty($seen);
        self::assertSame(['2026-09-01'], array_values(array_unique($seen)));
    }

    /**
     * Un socix de prueba con correo.
     */
    private function partner(string $email): Partner
    {
        return (new Partner())->setEmail($email);
    }

    /**
     * Fija la fecha de creación, que en producción rellena Gedmo al persistir y
     * aquí no tiene setter propio (no es un dato que nadie deba poder cambiar).
     */
    private function setCreated(ConsumerGroupRound $round, \DateTime $created): void
    {
        $property = new \ReflectionProperty(ConsumerGroupRound::class, 'created');
        $property->setAccessible(true);
        $property->setValue($round, $created);
    }

    /**
     * El announcer con todo mockeado y valores por defecto razonables: correo
     * encendido, nadie silenciado, y un ledger que deja pasar todos los efectos.
     */
    private function announcer(
        ?MailerInterface $mailer = null,
        ?PushSender $push = null,
        ?NotificationInbox $inbox = null,
        ?NotificationPreferences $preferences = null,
        ?EffectLedger $ledger = null,
        bool $emailEnabled = true,
    ): ConsumerGroupAnnouncer {
        $partners = $this->createMock(PartnerRepository::class);
        $partners->method('findActive')->willReturn($this->active);
        $partners->method('findActiveWithEmail')->willReturn($this->active);

        $users = $this->createMock(UserRepository::class);
        $users->method('findByPartners')->willReturnCallback(
            static fn (array $list): array => array_map(static fn (): User => new User(), $list)
        );

        if (null === $preferences) {
            $preferences = $this->createMock(NotificationPreferences::class);
            $preferences->method('filter')->willReturnCallback(static fn (array $list): array => $list);
        }

        if (null === $ledger) {
            $ledger = $this->createMock(EffectLedger::class);
            $ledger->method('once')->willReturnCallback(
                static function (string $kind, string $reference, \DateTimeInterface $on, callable $effect): bool {
                    $effect();

                    return true;
                }
            );
        }

        $settings = $this->createMock(AppSettings::class);
        $settings->method('getBool')->willReturn($emailEnabled);

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://csa-vega.example/panel/consumer-group');

        $accessPolicy = $this->createMock(PartnerAccessPolicy::class);
        $accessPolicy->method('canUseActionLinks')->willReturn(true);

        return new ConsumerGroupAnnouncer(
            $partners,
            $users,
            $inbox ?? $this->createMock(NotificationInbox::class),
            $this->createMock(NotificationLink::class),
            $preferences,
            $push ?? $this->createMock(PushSender::class),
            $this->createMock(PushSubscriptionRepository::class),
            $ledger,
            $this->createMock(EntityManagerInterface::class),
            $mailer ?? $this->createMock(MailerInterface::class),
            $settings,
            $accessPolicy,
            $urls,
            $this->createMock(LoggerInterface::class),
        );
    }
}
