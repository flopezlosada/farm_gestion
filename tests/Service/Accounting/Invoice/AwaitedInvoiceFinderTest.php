<?php

namespace App\Tests\Service\Accounting\Invoice;

use App\Entity\BankStatementLine;
use App\Entity\BudgetCategory;
use App\Entity\FinancialAccount;
use App\Entity\Provider;
use App\Entity\ReceivedInvoice;
use App\Service\Accounting\Invoice\AwaitedInvoiceFinder;
use App\Service\Accounting\Invoice\ExtractedInvoice;
use App\Service\Accounting\Norma43\Norma43Movement;
use PHPUnit\Framework\TestCase;

/**
 * Qué factura paga un movimiento del banco cuando varias esperan el mismo importe:
 * la del proveedor que el banco nombra y, si ninguna, la más antigua.
 */
class AwaitedInvoiceFinderTest extends TestCase
{
    public function testSinNingunaQueEspereEseImporteNoHayFactura(): void
    {
        $this->assertNull(AwaitedInvoiceFinder::pick($this->line('TRF FITOGARDEN'), null, []));
    }

    public function testUnaSolaEsLaQueSePaga(): void
    {
        $only = $this->awaiting('Fitogarden', null);

        $this->assertSame($only, AwaitedInvoiceFinder::pick($this->line('TRANSFERENCIA'), null, [$only]));
    }

    public function testEntreVariasGanaLaQueElBancoNombraAunqueSeaMasNueva(): void
    {
        $older = $this->awaiting('Ferretería Torrelaguna', null);
        $named = $this->awaiting('Fitogarden S.L.', null);

        $this->assertSame($named, AwaitedInvoiceFinder::pick($this->line('TRF. FITOGARDEN'), null, [$older, $named]));
    }

    /** El banco escribe sin tildes y en mayúsculas; la factura, como venga. */
    public function testElNombreSeReconoceSinTildesNiMayusculas(): void
    {
        $older = $this->awaiting('Fitogarden', null);
        $named = $this->awaiting('Ferretería Torrelaguna', null);

        $this->assertSame($named, AwaitedInvoiceFinder::pick($this->line('TRF FERRETERIA TORRELAGUNA'), null, [$older, $named]));
    }

    /** El proveedor que se casó otras veces con ese concepto del banco cuenta como nombrado. */
    public function testGanaLaDelProveedorDeOtrasVeces(): void
    {
        $provider = (new Provider())->setName('Agrosuministros');
        $older = $this->awaiting('Fitogarden', null);
        $usual = $this->awaiting('Agrosuministros', $provider);

        $this->assertSame($usual, AwaitedInvoiceFinder::pick($this->line('RECIBO 0042'), $provider, [$older, $usual]));
    }

    /** Las palabras cortas («SL», «DE») no distinguen a nadie: no cuentan. */
    public function testSinNombreReconocibleVaLaMasAntigua(): void
    {
        $older = $this->awaiting('Fitogarden SL', null);
        $newer = $this->awaiting('El Huerto de Ana SL', null);

        $this->assertSame($older, AwaitedInvoiceFinder::pick($this->line('TRF DE SL'), null, [$older, $newer]));
    }

    private function line(string $text): BankStatementLine
    {
        $account = (new FinancialAccount())->setName('Fiare')->setKind(FinancialAccount::KIND_BANK);
        // La huella del movimiento lleva el id de la cuenta: tiene que estar guardada.
        (new \ReflectionProperty($account, 'id'))->setValue($account, 1);
        $day = new \DateTimeImmutable('2026-10-08');

        return new BankStatementLine($account, new Norma43Movement($day, $day, '99', '000', -26553, '', '', '', [$text]), 1);
    }

    private function awaiting(string $providerName, ?Provider $provider): ReceivedInvoice
    {
        $invoice = new ReceivedInvoice('f.pdf', 'f.pdf', 'application/pdf', ReceivedInvoice::SOURCE_WEB, null);
        $invoice->markRead(ExtractedInvoice::fromArray(['fecha' => '2026-10-06', 'total' => 265.53, 'proveedor' => $providerName]), 'test', null, new \DateTimeImmutable());
        $invoice->acceptUnpaid(new BudgetCategory(), '265.53', $provider, $providerName, null);

        return $invoice;
    }
}
