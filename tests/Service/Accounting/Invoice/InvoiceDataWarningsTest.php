<?php

namespace App\Tests\Service\Accounting\Invoice;

use App\Entity\ReceivedInvoice;
use App\Service\Accounting\Invoice\ExtractedInvoice;
use App\Service\Accounting\Invoice\InvoiceDataWarnings;
use App\Service\AppSettings;
use PHPUnit\Framework\TestCase;

/**
 * Los avisos de una factura recibida: no estar a nombre de la asociación y un CIF
 * de proveedor que no cuadra. Lo demás no avisa.
 */
class InvoiceDataWarningsTest extends TestCase
{
    /** El ajuste «quién emite las facturas» tal como está escrito en la base. */
    private const ISSUER = "ASOCIACION AGROECOLOGICA VEGA DE JARAMA\nCIF: G87587580\nC/OLIVAR 13\n28189 TORREMOCHA DE JARAMA\nMADRID";

    private const PROVIDER_TAX_ID = 'B45351178';

    /** A nombre de la asociación y con el CIF del proveedor bien: nada que decir. */
    public function testFacturaEnRegla(): void
    {
        $invoice = $this->read('factura', 'ASOC. AGROECOLOGICA VEGA JARAMA', 'G87587580');

        $this->assertSame([], $this->warnings(self::ISSUER)->for($invoice), 'El nombre mal escrito con el CIF bueno no avisa.');
    }

    /** Con el CIF de otro cliente, el aviso dice de quién es y cuál es el nuestro. */
    public function testFacturaAnombreDeOtro(): void
    {
        $invoice = $this->read('factura', 'HUERTA VECINA SL', 'B80902596');

        $warnings = $this->warnings(self::ISSUER)->for($invoice);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('HUERTA VECINA SL (B80902596)', $warnings[0]);
        $this->assertStringContainsString('(G87587580)', $warnings[0]);
    }

    /** Una factura completa sin cliente no le vale a la gestoría. */
    public function testFacturaSinDatosDeLaAsociacion(): void
    {
        $warnings = $this->warnings(self::ISSUER)->for($this->read('factura', null, null));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('no lleva el CIF de la asociación', $warnings[0]);
    }

    /** Un ticket o una factura simplificada no llevan cliente: no avisan por eso. */
    public function testTicketYSimplificadaNoLlevanCliente(): void
    {
        $this->assertSame([], $this->warnings(self::ISSUER)->for($this->read('ticket', null, null)));
        $this->assertSame([], $this->warnings(self::ISSUER)->for($this->read('factura_simplificada', null, null)));
    }

    /** Sin lectura no hay cliente leído con el que comparar: una factura a mano no avisa. */
    public function testFacturaSinLeerNoAvisaDelCliente(): void
    {
        $invoice = new ReceivedInvoice('f.pdf', 'f.pdf', 'application/pdf', ReceivedInvoice::SOURCE_EMAIL, null);

        $this->assertSame([], $this->warnings(self::ISSUER)->for($invoice));
    }

    /** Sin el CIF de la asociación en los ajustes no se compara (mejor callar que avisar en falso). */
    public function testSinAjusteDelEmisorNoSeCompara(): void
    {
        $this->assertSame([], $this->warnings('')->for($this->read('factura', 'OTRA', 'B80902596')));
        $this->assertSame([], $this->warnings("ASOCIACION\nSIN CIF ESCRITO")->for($this->read('factura', 'OTRA', 'B80902596')));
    }

    /** El CIF del ajuste se encuentra aunque esté escrito con guión. */
    public function testCifDelAjusteConGuion(): void
    {
        $issuer = "ASOCIACION AGROECOLOGICA VEGA DE JARAMA\nG-87587580";

        $this->assertSame([], $this->warnings($issuer)->for($this->read('factura', 'ASOCIACION', 'G87587580')));
    }

    /** Un CIF de proveedor que no cuadra avisa, también en un ticket. */
    public function testCifDelProveedorQueNoCuadra(): void
    {
        $invoice = $this->read('ticket', null, null, 'B45351278');

        $warnings = $this->warnings(self::ISSUER)->for($invoice);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('(B45351278) no es válido', $warnings[0]);
    }

    /** Sin CIF de proveedor leído no hay nada que validar. */
    public function testSinCifDelProveedorNoAvisa(): void
    {
        $this->assertSame([], $this->warnings(self::ISSUER)->for($this->read('ticket', null, null, null)));
    }

    /** Los dos avisos a la vez, en orden: primero el cliente, luego el proveedor. */
    public function testLosDosAvisos(): void
    {
        $warnings = $this->warnings(self::ISSUER)->for($this->read('factura', 'OTRA', 'B80902596', 'B45351278'));

        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('a nombre de OTRA', $warnings[0]);
        $this->assertStringContainsString('no es válido', $warnings[1]);
    }

    /**
     * El servicio con el ajuste del emisor dado.
     *
     * @param string $issuer Texto del ajuste «quién emite las facturas».
     */
    private function warnings(string $issuer): InvoiceDataWarnings
    {
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getString')->with(AppSettings::ISSUED_INVOICE_ISSUER)->willReturn($issuer);

        return new InvoiceDataWarnings($settings);
    }

    /**
     * Una factura tal como la deja la lectura, con los campos que no trae a null.
     *
     * @param string      $type          Tipo de documento leído.
     * @param string|null $customerName  Cliente leído.
     * @param string|null $customerTaxId CIF del cliente leído.
     * @param string|null $providerTaxId CIF del proveedor leído.
     */
    private function read(string $type, ?string $customerName, ?string $customerTaxId, ?string $providerTaxId = self::PROVIDER_TAX_ID): ReceivedInvoice
    {
        $invoice = new ReceivedInvoice('f.pdf', 'f.pdf', 'application/pdf', ReceivedInvoice::SOURCE_EMAIL, null);
        $invoice->markRead(new ExtractedInvoice(
            documentType: $type,
            date: new \DateTimeImmutable('2026-10-20'),
            providerName: 'Proveedor de prueba',
            providerTaxId: $providerTaxId,
            customerName: $customerName,
            customerTaxId: $customerTaxId,
            invoiceNumber: null,
            total: '42.00',
            taxLines: [],
            withholding: null,
            withholdingRate: null,
            providerAddress: null,
            providerPostalCode: null,
            providerTown: null,
            providerProvince: null,
            concept: null,
            paymentMethod: ReceivedInvoice::PAYMENT_UNKNOWN,
            confidence: null,
            categoryId: null,
        ), 'test', null, new \DateTimeImmutable('2026-10-20'));

        return $invoice;
    }
}
