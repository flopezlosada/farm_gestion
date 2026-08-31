<?php

namespace App\Tests\Service\ConsumerGroup;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\Partner;
use App\Entity\Producer;
use App\Service\AppSettings;
use App\Service\ConsumerGroup\ConsumerGroupNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Unit test del aviso de confirmación del grupo de consumo: respeta el interruptor
 * general, solo envía a socias apuntadas con email, y un fallo de envío no aborta
 * el resto.
 */
class ConsumerGroupNotifierTest extends TestCase
{
    private ConsumerGroupRound $round;
    private ConsumerGroupRoundItem $item;

    protected function setUp(): void
    {
        $producer = new Producer();
        $round = new ConsumerGroupRound();
        $round->setProducer($producer);
        $product = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit('kg');
        $producer->addProduct($product);
        $this->item = new ConsumerGroupRoundItem($round, $product, '2.50');
        $round->addItem($this->item);
        $this->round = $round;
    }

    private function partner(?string $email): Partner
    {
        $partner = new Partner();
        if ($email !== null) {
            $partner->setEmail($email);
        }
        return $partner;
    }

    private function orderFor(Partner $partner, bool $withLine = true): ConsumerGroupOrder
    {
        $order = new ConsumerGroupOrder($this->round, $partner);
        if ($withLine) {
            $order->addLine(new ConsumerGroupOrderLine($order, $this->item, '2'));
        }
        $this->round->addOrder($order);
        return $order;
    }

    private function notifier(MailerInterface $mailer, bool $emailEnabled): ConsumerGroupNotifier
    {
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getBool')->with(AppSettings::EMAIL_ENABLED)->willReturn($emailEnabled);

        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://csa-vega.example/panel/consumer-group');

        return new ConsumerGroupNotifier($mailer, $settings, $urls, $this->createMock(LoggerInterface::class));
    }

    public function testNoEnviaSiElInterruptorEstaApagado(): void
    {
        $this->orderFor($this->partner('socia@example.com'));

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $result = $this->notifier($mailer, false)->notifyConfirmed($this->round);

        self::assertFalse($result['enabled']);
        self::assertSame(0, $result['sent']);
    }

    public function testEnviaSoloAApuntadasConEmail(): void
    {
        $this->orderFor($this->partner('con@example.com'));      // recibe
        $this->orderFor($this->partner(null));                    // sin email → skip
        $this->orderFor($this->partner('vacia@example.com'), false); // pedido vacío → no cuenta

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $result = $this->notifier($mailer, true)->notifyConfirmed($this->round);

        self::assertTrue($result['enabled']);
        self::assertSame(1, $result['sent']);
        self::assertSame(1, $result['skippedNoEmail']);
        self::assertSame(0, $result['failed']);
    }

    public function testUnFalloDeEnvioNoAbortaElResto(): void
    {
        $this->orderFor($this->partner('a@example.com'));
        $this->orderFor($this->partner('b@example.com'));

        $mailer = $this->createMock(MailerInterface::class);
        $calls = 0;
        $mailer->expects(self::exactly(2))->method('send')->willReturnCallback(function () use (&$calls): void {
            if (++$calls === 1) {
                throw new TransportException('SMTP caído');
            }
        });

        $result = $this->notifier($mailer, true)->notifyConfirmed($this->round);

        self::assertSame(1, $result['sent']);
        self::assertSame(1, $result['failed']);
    }

    public function testRecipientStats(): void
    {
        $this->orderFor($this->partner('con@example.com'));
        $this->orderFor($this->partner(null));
        $this->orderFor($this->partner('vacia@example.com'), false);

        $stats = $this->notifier($this->createMock(MailerInterface::class), true)->recipientStats($this->round);

        self::assertSame(2, $stats['total']);
        self::assertSame(1, $stats['withEmail']);
    }
}
