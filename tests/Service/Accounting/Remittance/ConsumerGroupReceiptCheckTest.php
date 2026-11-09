<?php

namespace App\Tests\Service\Accounting\Remittance;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupPaymentMethod;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Service\Accounting\Remittance\ConsumerGroupReceiptCheck;
use App\Service\Accounting\Remittance\RemittanceFromConsumerGroup;
use PHPUnit\Framework\TestCase;

/**
 * Antes de enviar la remesa del grupo de consumo, cada recibo tiene que cobrar lo que
 * deben hoy sus pedidos: si alguno se pagó a mano, cambió o se canceló después del
 * borrador, se avisa en vez de cobrar de más.
 */
class ConsumerGroupReceiptCheckTest extends TestCase
{
    private ConsumerGroupRound $round;

    private ConsumerGroupRoundItem $item;

    protected function setUp(): void
    {
        $this->round = (new ConsumerGroupRound())->setStatus(ConsumerGroupRound::STATUS_CONFIRMED)->setConfirmed(true)->setDeliveryDate(new \DateTime('2026-11-27'));
        $this->item = new ConsumerGroupRoundItem($this->round, (new ConsumerGroupProduct())->setName('Naranjas'), '2.50');
    }

    private function order(string $name, string $quantity): ConsumerGroupOrder
    {
        $partner = (new Partner())->setname($name)->setSurname('López')
            ->setIban('ES91 2100 0418 4502 0005 1332')->setMandateReference('CUOTA MENSUAL ' . $name)->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'));
        $order = new ConsumerGroupOrder($this->round, $partner);
        $order->addLine(new ConsumerGroupOrderLine($order, $this->item, $quantity));

        return $order;
    }

    /**
     * @param list<ConsumerGroupOrder> $orders
     */
    private function draft(array $orders): Remittance
    {
        return (new RemittanceFromConsumerGroup())->build(new \DateTimeImmutable('2026-11-01'), $orders, null);
    }

    public function testRecienPreparadaCuadra(): void
    {
        $orders = [$this->order('Ana', '4'), $this->order('Bea', '2')];

        $this->assertSame([], (new ConsumerGroupReceiptCheck())->problems($this->draft($orders), $orders));
    }

    public function testPagadoAManoDespuesDelBorradorNoCuadra(): void
    {
        $ana = $this->order('Ana', '4');
        $remittance = $this->draft([$ana]);
        $ana->markPaid(ConsumerGroupPaymentMethod::Cash);

        $problems = (new ConsumerGroupReceiptCheck())->problems($remittance, [$ana]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('ANA LÓPEZ: el recibo cobra 10,00 € y sus pedidos deben ahora 0,00 €', $problems[0]);
    }

    public function testCantidadCambiadaDespuesDelBorradorNoCuadra(): void
    {
        $ana = $this->order('Ana', '4');
        $remittance = $this->draft([$ana]);
        $ana->getLines()->first()->setQuantity('2');

        $this->assertStringContainsString('deben ahora 5,00 €', (new ConsumerGroupReceiptCheck())->problems($remittance, [$ana])[0]);
    }

    public function testRondaCanceladaDespuesDelBorradorNoCuadra(): void
    {
        $ana = $this->order('Ana', '4');
        $remittance = $this->draft([$ana]);
        $this->round->setStatus(ConsumerGroupRound::STATUS_CANCELLED);

        $this->assertCount(1, (new ConsumerGroupReceiptCheck())->problems($remittance, [$ana]));
    }

    public function testUnReciboAnadidoAManoNoSeComprueba(): void
    {
        $ana = $this->order('Ana', '4');
        $remittance = $this->draft([$ana]);
        $remittance->addLine((new RemittanceLine())->setDebtorName('A MANO')->setAmount('7.00'));

        $this->assertSame([], (new ConsumerGroupReceiptCheck())->problems($remittance, [$ana]));
    }
}
