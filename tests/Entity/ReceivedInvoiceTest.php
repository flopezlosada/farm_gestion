<?php

namespace App\Tests\Entity;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\FinancialAccount;
use App\Entity\Provider;
use App\Entity\ReceivedInvoice;
use App\Service\Accounting\Invoice\ExtractedInvoice;
use PHPUnit\Framework\TestCase;

/**
 * Aceptar una factura y pagarla son cosas distintas: pagada por banco se acepta sin
 * apunte y espera a que el pago llegue con el extracto.
 */
class ReceivedInvoiceTest extends TestCase
{
    public function testAceptadaSinPagoEsperaElPagoYGuardaLoRevisado(): void
    {
        $category = (new BudgetCategory())->setName('Estiércol y biopreparados');
        $invoice = $this->read();

        $invoice->acceptUnpaid($category, '265.53', null, 'FITOGARDEN', '2931');

        $this->assertSame(ReceivedInvoice::STATUS_CONFIRMED, $invoice->getStatus());
        $this->assertTrue($invoice->isAwaitingPayment());
        $this->assertFalse($invoice->isOpen());
        $this->assertSame($category, $invoice->getCategory());
        $this->assertSame($category, $invoice->ledgerCategory(), 'Sin apunte, el libro de facturas lleva su partida.');
        $this->assertSame('265.53', $invoice->getTotal());
    }

    public function testUnaYaAceptadaNoSeAceptaOtraVez(): void
    {
        $invoice = $this->read();
        $invoice->acceptUnpaid(new BudgetCategory(), '265.53', null, null, null);

        $this->expectException(\LogicException::class);
        $invoice->acceptUnpaid(new BudgetCategory(), '265.53', null, null, null);
    }

    /** El apunte del banco no sabe partida, proveedor ni número: los toma de la factura. */
    public function testElPagoCompletaElApunteConLaFactura(): void
    {
        $category = (new BudgetCategory())->setName('Estiércol y biopreparados');
        $provider = (new Provider())->setName('Fitogarden');
        $invoice = $this->read();
        $invoice->acceptUnpaid($category, '265.53', $provider, 'FITOGARDEN', '2931');
        $payment = $this->payment('-265.53');

        $invoice->paidBy($payment);

        $this->assertSame($payment, $invoice->getAccountEntry());
        $this->assertFalse($invoice->isAwaitingPayment());
        $this->assertSame($category, $payment->getCategory());
        $this->assertSame($provider, $payment->getProvider());
        $this->assertSame('Fitogarden', $payment->getProviderName(), 'El nombre, el de su ficha.');
        $this->assertSame('2931', $payment->getInvoiceNumber());
    }

    /** Lo que eligió quien anotó el movimiento manda: la factura sólo rellena huecos. */
    public function testElPagoNoPisaLaPartidaElegidaAlAnotar(): void
    {
        $chosen = (new BudgetCategory())->setName('Semillas');
        $invoice = $this->read();
        $invoice->acceptUnpaid((new BudgetCategory())->setName('Estiércol y biopreparados'), '265.53', null, 'FITOGARDEN', null);
        $payment = $this->payment('-265.53')->setCategory($chosen);

        $invoice->paidBy($payment);

        $this->assertSame($chosen, $payment->getCategory());
        $this->assertSame($chosen, $invoice->ledgerCategory(), 'Con apunte, la del apunte: es la que cuenta en el libro.');
        $this->assertSame('FITOGARDEN', $payment->getProviderName());
    }

    public function testUnPagoDeOtroImporteNoEsElSuyo(): void
    {
        $invoice = $this->read();
        $invoice->acceptUnpaid(new BudgetCategory(), '265.53', null, null, null);

        $this->expectException(\DomainException::class);
        $invoice->paidBy($this->payment('-265.50'));
    }

    /** Un abono (total negativo) se cobra: el movimiento entra en la cuenta. */
    public function testUnAbonoSePagaConUnIngreso(): void
    {
        $invoice = $this->read();
        $invoice->acceptUnpaid(new BudgetCategory(), '-40.00', null, null, null);

        $invoice->paidBy($this->payment('40.00'));

        $this->assertFalse($invoice->isAwaitingPayment());
    }

    public function testSinEsperarElPagoNoSeEngancha(): void
    {
        $this->expectException(\LogicException::class);
        $this->read()->paidBy($this->payment('-265.53'));
    }

    /** Borrar el pago del banco no deshace lo revisado: vuelve a esperar el pago. */
    public function testSinSuPagoDelBancoVuelveAEsperarlo(): void
    {
        $invoice = $this->read();
        $invoice->acceptUnpaid(new BudgetCategory(), '265.53', null, null, null);
        $invoice->paidBy($this->payment('-265.53'));

        $this->assertFalse($invoice->losePayment());
        $this->assertTrue($invoice->isAwaitingPayment());
    }

    /** El apunte de caja se creó al aceptarla: sin él, vuelve a la bandeja. */
    public function testSinSuApunteDeCajaVuelveALaBandeja(): void
    {
        $invoice = $this->read();
        $cash = (new FinancialAccount())->setName('Caja')->setKind(FinancialAccount::KIND_CASH);
        $invoice->confirm($this->payment('-265.53')->setAccount($cash), null);

        $this->assertTrue($invoice->losePayment());
        $this->assertTrue($invoice->isOpen());
    }

    private function read(): ReceivedInvoice
    {
        $invoice = new ReceivedInvoice('f.pdf', 'f.pdf', 'application/pdf', ReceivedInvoice::SOURCE_WEB, null);
        $invoice->markRead(ExtractedInvoice::fromArray(['fecha' => '2026-10-06', 'total' => 265.53, 'proveedor' => 'FITOGARDEN']), 'test', null, new \DateTimeImmutable());

        return $invoice;
    }

    private function payment(string $amount): AccountEntry
    {
        $bank = (new FinancialAccount())->setName('Fiare')->setKind(FinancialAccount::KIND_BANK);

        return (new AccountEntry())->setAccount($bank)->setDate(new \DateTimeImmutable('2026-10-08'))->setAmount($amount)->setConcept('TRF FITOGARDEN');
    }
}
