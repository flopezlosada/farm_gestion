<?php

namespace App\Tests\Entity;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupPaymentMethod;
use App\Entity\ConsumerGroupPaymentState;
use App\Entity\Remittance;
use App\Entity\ConsumerGroupRound;
use App\Entity\RemittanceLine;
use App\Entity\Partner;
use App\Entity\PartnerPaymentMethod;
use PHPUnit\Framework\TestCase;

/**
 * El pago de un pedido del grupo de consumo: pagado siempre de una forma concreta, y
 * «va en el recibo» sólo para quien paga por recibo con el mandato completo.
 */
class ConsumerGroupOrderPaymentTest extends TestCase
{
    private static function withMandate(): Partner
    {
        return (new Partner())->setname('Ana')->setSurname('López')
            ->setIban('ES91 2100 0418 4502 0005 1332')
            ->setMandateReference('CUOTA MENSUAL 200')
            ->setMandateSignedOn(new \DateTimeImmutable('2022-11-01'));
    }

    public function testNacePendienteYSinFormaDePago(): void
    {
        $order = new ConsumerGroupOrder(null, new Partner());

        $this->assertFalse($order->isPaid());
        $this->assertNull($order->getPaidVia());
        $this->assertNull($order->getPaidAt());
    }

    public function testPagadoGuardaLaFormaYLaFecha(): void
    {
        $order = new ConsumerGroupOrder(null, new Partner());
        $at = new \DateTime('2026-11-20 10:00');

        $order->markPaid(ConsumerGroupPaymentMethod::Cash, $at);

        $this->assertTrue($order->isPaid());
        $this->assertSame(ConsumerGroupPaymentMethod::Cash, $order->getPaidVia());
        $this->assertSame($at, $order->getPaidAt());
    }

    public function testPagadoSinFechaEsAhora(): void
    {
        $order = (new ConsumerGroupOrder(null, new Partner()))->markPaid(ConsumerGroupPaymentMethod::Transfer);

        $this->assertNotNull($order->getPaidAt());
        $this->assertLessThan(5, abs(time() - $order->getPaidAt()->getTimestamp()));
    }

    public function testVolverAPendienteBorraFormaYFecha(): void
    {
        $order = (new ConsumerGroupOrder(null, new Partner()))->markPaid(ConsumerGroupPaymentMethod::Transfer)->markUnpaid();

        $this->assertFalse($order->isPaid());
        $this->assertNull($order->getPaidVia());
        $this->assertNull($order->getPaidAt());
    }

    public function testVaEnElReciboQuienPagaPorReciboConMandatoCompleto(): void
    {
        $this->assertTrue((new ConsumerGroupOrder(null, self::withMandate()))->willBeChargedByReceipt());
    }

    public function testNoVaEnElReciboSiYaEstaPagado(): void
    {
        $order = (new ConsumerGroupOrder(null, self::withMandate()))->markPaid(ConsumerGroupPaymentMethod::Cash);

        $this->assertFalse($order->willBeChargedByReceipt());
    }

    public function testNoVaEnElReciboSinMandatoCompleto(): void
    {
        // Cuenta y referencia, pero sin fecha de firma: el banco no lo acepta.
        $partner = (new Partner())->setIban('ES91 2100 0418 4502 0005 1332')->setMandateReference('CUOTA MENSUAL 200');

        $this->assertFalse((new ConsumerGroupOrder(null, $partner))->willBeChargedByReceipt());
        $this->assertFalse((new ConsumerGroupOrder(null, new Partner()))->willBeChargedByReceipt());
    }

    public function testNoVaEnElReciboQuienPagaPorTransferencia(): void
    {
        $partner = self::withMandate()->setPaymentMethod(PartnerPaymentMethod::Transfer);

        $this->assertFalse((new ConsumerGroupOrder(null, $partner))->willBeChargedByReceipt());
    }

    public function testLaComisionSoloMarcaTransferenciaOMetalico(): void
    {
        $this->assertSame([ConsumerGroupPaymentMethod::Transfer, ConsumerGroupPaymentMethod::Cash], ConsumerGroupPaymentMethod::manual());
    }

    public function testVolverAPendienteSueltaSuRecibo(): void
    {
        // La comisión lo desmarca a mano estando en un borrador: entra en el siguiente.
        $order = (new ConsumerGroupOrder(null, self::withMandate()))->setRemittanceLine(new RemittanceLine())
            ->markPaid(ConsumerGroupPaymentMethod::Receipt)
            ->markUnpaid();

        $this->assertNull($order->getRemittanceLine());
    }

    public function testSeLePuedeCobrarHoySoloSinPagarYDeRondaConfirmadaYNoCancelada(): void
    {
        $confirmed = (new ConsumerGroupRound())->setStatus(ConsumerGroupRound::STATUS_CONFIRMED)->setConfirmed(true);
        $open = (new ConsumerGroupRound())->setStatus(ConsumerGroupRound::STATUS_OPEN);
        $cancelled = (new ConsumerGroupRound())->setStatus(ConsumerGroupRound::STATUS_CANCELLED)->setConfirmed(true);

        $this->assertTrue((new ConsumerGroupOrder($confirmed, new Partner()))->isChargeableByReceiptNow());
        $this->assertFalse((new ConsumerGroupOrder($open, new Partner()))->isChargeableByReceiptNow());
        $this->assertFalse((new ConsumerGroupOrder($cancelled, new Partner()))->isChargeableByReceiptNow());
        $this->assertFalse((new ConsumerGroupOrder($confirmed, new Partner()))->markPaid(ConsumerGroupPaymentMethod::Cash)->isChargeableByReceiptNow());
        $this->assertFalse((new ConsumerGroupOrder(null, new Partner()))->isChargeableByReceiptNow());
    }

    /** Un recibo de una remesa del grupo de consumo, en borrador o ya enviada. */
    private static function receipt(bool $sent): RemittanceLine
    {
        $remittance = new Remittance(Remittance::KIND_CONSUMER_GROUP, new \DateTimeImmutable('2026-10-01'), 'Grupo de consumo OCTUBRE 2026', Remittance::SOURCE_APP, null);
        $line = RemittanceLine::forPayer(self::withMandate(), '10.00', 'GC 202610 CUOTA MENSUAL 200');
        $remittance->addLine($line);
        if ($sent) {
            $remittance->markSent(null, new \DateTimeImmutable('2026-11-05'));
        }

        return $line;
    }

    public function testElEstadoDePagoSaleDelPedidoYDeSuRecibo(): void
    {
        $order = static fn (): ConsumerGroupOrder => new ConsumerGroupOrder(null, self::withMandate());

        $this->assertSame(ConsumerGroupPaymentState::InReceipt, $order()->getPaymentState());
        $this->assertSame(ConsumerGroupPaymentState::InReceipt, $order()->setRemittanceLine(self::receipt(false))->getPaymentState(), 'En un borrador todavía va en el recibo.');
        $this->assertSame(ConsumerGroupPaymentState::AwaitingBank, $order()->setRemittanceLine(self::receipt(true))->getPaymentState());
        $this->assertSame(ConsumerGroupPaymentState::Paid, $order()->setRemittanceLine(self::receipt(true))->settleReceipt(ConsumerGroupPaymentMethod::Receipt, new \DateTimeImmutable('2026-11-05'), false)->getPaymentState());
        $this->assertSame(ConsumerGroupPaymentState::Returned, $order()->setRemittanceLine(self::receipt(true))->settleReceipt(null, null, true)->getPaymentState());
        $this->assertSame(ConsumerGroupPaymentState::Pending, (new ConsumerGroupOrder(null, new Partner()))->getPaymentState(), 'Sin mandato: lo paga ella.');
    }

    public function testDevueltoNoVaEnElReciboAunqueTengaMandato(): void
    {
        // Ya fue y volvió: no vuelve sola a la remesa, lo decide la devolución.
        $order = (new ConsumerGroupOrder(null, self::withMandate()))->setRemittanceLine(self::receipt(true))->settleReceipt(null, null, true);

        $this->assertFalse($order->willBeChargedByReceipt());
        $this->assertTrue($order->isReceiptReturned());
        $this->assertNotNull($order->getRemittanceLine(), 'Conserva el recibo: lo liga a su devolución.');
    }

    public function testCobradoPorElBancoGuardaElDiaDelAbono(): void
    {
        $order = (new ConsumerGroupOrder(null, self::withMandate()))->setRemittanceLine(self::receipt(true))
            ->settleReceipt(ConsumerGroupPaymentMethod::Receipt, new \DateTimeImmutable('2026-11-06'), false);

        $this->assertSame(ConsumerGroupPaymentMethod::Receipt, $order->getPaidVia());
        $this->assertSame('2026-11-06', $order->getPaidAt()?->format('Y-m-d'));
        $this->assertFalse($order->isReceiptReturned());
    }

    public function testPagadoYDevueltoALaVezNoSePuede(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ConsumerGroupOrder())->settleReceipt(ConsumerGroupPaymentMethod::Receipt, new \DateTimeImmutable(), true);
    }

    public function testPagadoSinFechaNoSePuede(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ConsumerGroupOrder())->settleReceipt(ConsumerGroupPaymentMethod::Receipt, null, false);
    }

    public function testDesmarcarAManoBorraTambienElDevuelto(): void
    {
        $order = (new ConsumerGroupOrder(null, self::withMandate()))->setRemittanceLine(self::receipt(true))->settleReceipt(null, null, true)->markUnpaid();

        $this->assertFalse($order->isReceiptReturned());
    }
}
