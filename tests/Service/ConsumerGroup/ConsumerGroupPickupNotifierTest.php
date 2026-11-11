<?php

namespace App\Tests\Service\ConsumerGroup;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupPaymentMethod;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\ConsumerGroupUnit;
use App\Entity\Node;
use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\Producer;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\PartnerAccessPolicy;
use App\Service\AppSettings;
use App\Service\ConsumerGroup\ConsumerGroupEmailPhotos;
use App\Service\ConsumerGroup\ConsumerGroupPickupNotifier;
use App\Service\ConsumerGroup\NodeConsumerGroupDeliveries;
use App\Service\Cron\EffectLedger;
use App\Service\Delivery\DeliverySheetSchedule;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\NotificationPreferences;
use App\Service\Notification\NotificationTopic;
use App\Service\Push\PushSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Unit test del aviso de recogida de los pedidos del grupo de consumo.
 *
 * Lo que se protege: que el aviso sale del MISMO sitio que la hoja del reparto
 * (nodo y día), que llega a toda la familia por las tres vías, que respeta lo que
 * cada persona ha silenciado y que el correo apagado no se lleva por delante la
 * bandeja ni el móvil.
 */
class ConsumerGroupPickupNotifierTest extends TestCase
{
    private const FRIDAY = '2026-11-27';

    private Node $node;

    protected function setUp(): void
    {
        $this->node = (new Node())->setName('Torremocha');
    }

    public function testRecogeLosPedidosDeCadaNodoQueRepartaEseDia(): void
    {
        $friday = new \DateTimeImmutable(self::FRIDAY);
        $other = (new Node())->setName('Cascorro');
        $order = $this->order();

        $schedule = $this->createMock(DeliverySheetSchedule::class);
        $schedule->expects(self::once())->method('pending')->with($friday)->willReturn([
            ['node' => $this->node, 'physical_date' => $friday],
            ['node' => $other, 'physical_date' => $friday],
        ]);
        $deliveries = $this->createMock(NodeConsumerGroupDeliveries::class);
        $deliveries->method('ordersForNodeAndDate')->willReturnCallback(
            fn (Node $node): array => $node === $this->node ? [$order] : [],
        );

        $pickups = $this->notifier(schedule: $schedule, deliveries: $deliveries)->pickupsOn($friday);

        self::assertCount(1, $pickups);
        self::assertSame($order, $pickups[0]['order']);
        self::assertSame($this->node, $pickups[0]['node']);
    }

    public function testAvisaATodaLaFamiliaPorLasTresVias(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::exactly(2))->method('send');

        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->expects(self::once())->method('deliver')
            ->with(self::countOf(2), Notification::KIND_CONSUMER_GROUP_PICKUP)
            ->willReturn(2);

        $push = $this->createMock(PushSender::class);
        $push->expects(self::once())->method('sendToMany')->willReturn(2);

        $result = $this->notifier(mailer: $mailer, inbox: $inbox, push: $push)->notify([$this->pickup($this->order())]);

        self::assertSame(['inbox' => 2, 'email' => 2, 'push' => 2], $result);
    }

    public function testConElCorreoApagadoSiguenSaliendoLaBandejaYElMovil(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->method('deliver')->willReturn(2);
        $push = $this->createMock(PushSender::class);
        $push->method('sendToMany')->willReturn(2);

        $result = $this->notifier(mailer: $mailer, inbox: $inbox, push: $push, emailEnabled: false)
            ->notify([$this->pickup($this->order())]);

        self::assertSame(['inbox' => 2, 'email' => 0, 'push' => 2], $result);
    }

    public function testNoSeEscribeAQuienHaSilenciadoElCorreo(): void
    {
        $preferences = $this->createMock(NotificationPreferences::class);
        $preferences->method('filter')->willReturnCallback(
            static fn (array $partners, string $topic, string $channel): array => NotificationTopic::CHANNEL_EMAIL === $channel ? [] : $partners,
        );
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $result = $this->notifier(mailer: $mailer, preferences: $preferences)->notify([$this->pickup($this->order())]);

        self::assertSame(0, $result['email']);
    }

    /**
     * El cuerpo es lo único que se lee en el móvil: tiene que llevar día, nodo,
     * importe y si falta pagar.
     */
    public function testElAvisoDiceDiaNodoImporteYQueFaltaPagar(): void
    {
        $body = $this->bodyOf($this->order());

        self::assertSame('El viernes 27 de noviembre en Torremocha. Importe: 7,50 €, pendiente de pago.', $body);
    }

    public function testUnPedidoPagadoLoDice(): void
    {
        $order = $this->order()->markPaid(ConsumerGroupPaymentMethod::Transfer, new \DateTime('2026-11-20'));

        self::assertStringEndsWith('Importe: 7,50 €, pagado.', $this->bodyOf($order));
    }

    public function testElCorreoLlevaNodoDiaYPedido(): void
    {
        $sent = [];
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(static function (TemplatedEmail $email) use (&$sent): void {
            $sent[] = $email;
        });

        $order = $this->order();
        $this->notifier(mailer: $mailer)->notify([$this->pickup($order)]);

        self::assertSame('Recoge tu pedido: Naranjas de noviembre', $sent[0]->getSubject());
        $context = $sent[0]->getContext();
        self::assertSame($this->node, $context['node']);
        self::assertSame($order, $context['order']);
        self::assertSame(self::FRIDAY, $context['pickup_date']->format('Y-m-d'));
    }

    /**
     * La fecha del apunte es la de la RECOGIDA: con la de hoy, el mismo aviso se
     * podría repetir entero al día siguiente.
     */
    public function testElApunteSeFechaConElDiaDeRecogida(): void
    {
        $seen = [];
        $ledger = $this->createMock(EffectLedger::class);
        $ledger->method('once')->willReturnCallback(
            static function (string $kind, string $reference, \DateTimeInterface $on) use (&$seen): bool {
                $seen[] = $on->format('Y-m-d');

                return true;
            },
        );

        $this->notifier(ledger: $ledger)->notify([$this->pickup($this->order())]);

        self::assertSame([self::FRIDAY], array_values(array_unique($seen)));
    }

    public function testLoQueYaConstaEnviadoNoSeRepite(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        $ledger = $this->createMock(EffectLedger::class);
        $ledger->method('once')->willReturn(false);

        $result = $this->notifier(mailer: $mailer, ledger: $ledger)->notify([$this->pickup($this->order())]);

        self::assertSame(['inbox' => 0, 'email' => 0, 'push' => 0], $result);
    }

    /**
     * El texto que llega a la bandeja para ese pedido.
     */
    private function bodyOf(ConsumerGroupOrder $order): string
    {
        $body = null;
        $inbox = $this->createMock(NotificationInbox::class);
        $inbox->method('deliver')->willReturnCallback(
            static function (array $users, string $kind, string $title, ?string $text) use (&$body): int {
                $body = $text;

                return 1;
            },
        );
        $this->notifier(inbox: $inbox)->notify([$this->pickup($order)]);

        return (string) $body;
    }

    /**
     * @return array{order: ConsumerGroupOrder, node: Node, date: \DateTimeImmutable}
     */
    private function pickup(ConsumerGroupOrder $order): array
    {
        return ['order' => $order, 'node' => $this->node, 'date' => new \DateTimeImmutable(self::FRIDAY)];
    }

    /**
     * El pedido de una familia de dos con correo propio cada una, sin mandato (pago
     * pendiente): 3 kg de naranjas a 2,50 €.
     */
    private function order(): ConsumerGroupOrder
    {
        $producer = new Producer();
        $round = new ConsumerGroupRound();
        $round->setProducer($producer)->setTitle('Naranjas de noviembre');
        $product = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit((new ConsumerGroupUnit())->setName('kg'));
        $producer->addProduct($product);
        $item = new ConsumerGroupRoundItem($round, $product, '2.50');
        $round->addItem($item);

        $head = (new Partner())->setName('Ana')->setEmail('ana@example.com');
        $head->addRelative((new Partner())->setName('Luis')->setEmail('luis@example.com'));
        $order = new ConsumerGroupOrder($round, $head);
        $order->addLine(new ConsumerGroupOrderLine($order, $item, '3'));

        return $order;
    }

    /**
     * El notificador con todo mockeado y valores por defecto razonables: correo
     * encendido, nadie silenciado y un ledger que deja pasar todos los efectos.
     */
    private function notifier(
        ?DeliverySheetSchedule $schedule = null,
        ?NodeConsumerGroupDeliveries $deliveries = null,
        ?MailerInterface $mailer = null,
        ?NotificationInbox $inbox = null,
        ?PushSender $push = null,
        ?NotificationPreferences $preferences = null,
        ?EffectLedger $ledger = null,
        bool $emailEnabled = true,
    ): ConsumerGroupPickupNotifier {
        $users = $this->createMock(UserRepository::class);
        $users->method('findByPartners')->willReturnCallback(
            static fn (array $list): array => array_map(static fn (): User => new User(), $list),
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
                },
            );
        }

        $settings = $this->createMock(AppSettings::class);
        $settings->method('getBool')->willReturn($emailEnabled);
        $settings->method('getString')->willReturn('');

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://csa-vega.example/panel/consumer-group/1');

        $accessPolicy = $this->createMock(PartnerAccessPolicy::class);
        $accessPolicy->method('canUseActionLinks')->willReturn(true);

        return new ConsumerGroupPickupNotifier(
            $schedule ?? $this->createMock(DeliverySheetSchedule::class),
            $deliveries ?? $this->createMock(NodeConsumerGroupDeliveries::class),
            $users,
            $inbox ?? $this->createMock(NotificationInbox::class),
            $this->createMock(NotificationLink::class),
            $preferences,
            $push ?? $this->createMock(PushSender::class),
            $ledger,
            $mailer ?? $this->createMock(MailerInterface::class),
            $settings,
            $accessPolicy,
            $urls,
            $this->createMock(LoggerInterface::class),
            $this->createMock(ConsumerGroupEmailPhotos::class),
        );
    }
}
