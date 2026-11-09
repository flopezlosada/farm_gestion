<?php

namespace App\Tests\Entity;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupPaymentMethod;
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
}
