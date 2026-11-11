<?php

namespace App\Tests\Service\Accounting\Remittance;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\Partner;
use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Service\Accounting\Remittance\RemittanceFromConsumerGroup;
use PHPUnit\Framework\TestCase;

/**
 * El borrador de la remesa del grupo de consumo: un recibo por socia con la suma de sus
 * pedidos del mes, y cada pedido enlazado a su recibo para darlo por cobrado al enviarla.
 */
class RemittanceFromConsumerGroupTest extends TestCase
{
    private static function partner(string $name, string $mandate): Partner
    {
        return (new Partner())->setname($name)->setSurname('López García')
            ->setIban('ES91 2100 0418 4502 0005 1332')
            ->setMandateReference($mandate)
            ->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'));
    }

    /** Un pedido de `$quantity` unidades a `$price` € en una ronda nueva. */
    private static function order(Partner $partner, string $price, string $quantity): ConsumerGroupOrder
    {
        $round = (new ConsumerGroupRound())->setDeliveryDate(new \DateTime('2026-11-27'));
        $item = new ConsumerGroupRoundItem($round, (new ConsumerGroupProduct())->setName('Naranjas'), $price);
        $order = new ConsumerGroupOrder($round, $partner);
        $order->addLine(new ConsumerGroupOrderLine($order, $item, $quantity));

        return $order;
    }

    public function testUnReciboPorSociaConLaSumaDeSusPedidos(): void
    {
        $ana = self::partner('Ana', 'CUOTA MENSUAL 200');
        $bea = self::partner('Bea', 'CUOTA MENSUAL 201');
        // Dos rondas de Ana (4 kg a 2,50 y 3 kg a 1,10) y una de Bea.
        $anaNaranjas = self::order($ana, '2.50', '4');
        $anaLimones = self::order($ana, '1.10', '3');
        $beaNaranjas = self::order($bea, '2.50', '2');

        $remittance = (new RemittanceFromConsumerGroup())->build(new \DateTimeImmutable('2026-11-15'), [$anaNaranjas, $beaNaranjas, $anaLimones], null);

        $this->assertTrue($remittance->isDraft());
        $this->assertTrue($remittance->isConsumerGroup());
        $this->assertSame('2026-11-01', $remittance->getPeriod()->format('Y-m-d'));
        $this->assertSame('Grupo de consumo NOVIEMBRE 2026', $remittance->getLabel());
        $this->assertCount(2, $remittance->getLines());
        /** @var RemittanceLine $anaLine */
        $anaLine = $remittance->getLines()->first();
        $this->assertSame($ana, $anaLine->getPartner());
        $this->assertSame('13.30', $anaLine->getAmount(), '10,00 + 3,30 sin errores de coma flotante.');
        $this->assertSame('CUOTA MENSUAL 200', $anaLine->getMandateReference());
        $this->assertSame('GC 202611 CUOTA MENSUAL 200', $anaLine->getDebitReference(), 'Distinta de la del recibo de cuotas del mismo mes («CUOTA MENSUAL 200 202611»).');
        $this->assertSame('5.00', $remittance->getLines()->last()->getAmount());
        $this->assertSame($anaLine, $anaNaranjas->getRemittanceLine());
        $this->assertSame($anaLine, $anaLimones->getRemittanceLine());
        $this->assertSame($remittance->getLines()->last(), $beaNaranjas->getRemittanceLine());
        $this->assertSame([], $remittance->sendProblems(), 'El borrador sale listo para enviar.');
    }

    public function testQuienNoTieneNadaQueCobrarNoLlevaRecibo(): void
    {
        $ana = self::partner('Ana', 'CUOTA MENSUAL 200');
        $empty = self::order($ana, '2.50', '0');

        $remittance = (new RemittanceFromConsumerGroup())->build(new \DateTimeImmutable('2026-11-01'), [$empty], null);

        $this->assertCount(0, $remittance->getLines());
        $this->assertNull($empty->getRemittanceLine(), 'Sin recibo, el pedido no queda enlazado a nada.');
    }

    public function testSinPedidosSaleVacia(): void
    {
        $remittance = (new RemittanceFromConsumerGroup())->build(new \DateTimeImmutable('2026-11-01'), [], null);

        $this->assertSame(Remittance::KIND_CONSUMER_GROUP, $remittance->getKind());
        $this->assertCount(0, $remittance->getLines());
    }
}
