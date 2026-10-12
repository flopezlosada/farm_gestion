<?php

namespace App\Tests\Entity;

use App\Entity\AccountEntry;
use App\Entity\Customer;
use App\Entity\IssuedInvoice;
use App\Entity\IssuedInvoiceLine;
use PHPUnit\Framework\TestCase;

/**
 * La factura emitida sin base de datos: las cuentas del pie, la numeración que se
 * imprime, lo que impide emitir, que una emitida no se toca y cómo nace una
 * rectificativa. Los importes son los de facturas reales de la asociación.
 */
class IssuedInvoiceTest extends TestCase
{
    /** La 10/2026 de IMIDRA: formación exenta + estancia al 21 %, en la misma factura. */
    public function testElPieMezclaExentaYVeintiunoComoEnElPapel(): void
    {
        $invoice = $this->imidra();

        $this->assertSame([
            ['rate' => '0', 'base' => '1600.00', 'tax' => '0.00'],
            ['rate' => '21', 'base' => '1272.00', 'tax' => '267.12'],
        ], $invoice->taxBreakdown());
        $this->assertSame('2872.00', $invoice->getSubtotal());
        $this->assertSame('267.12', $invoice->getTaxTotal());
        $this->assertSame('3139.12', $invoice->getTotal());
    }

    /** La cuota se calcula sobre la suma de bases del tipo, no línea a línea. */
    public function testLaCuotaVaSobreLaSumaDeBasesDelTipo(): void
    {
        $invoice = new IssuedInvoice();
        // 0,05 € al 21 % = 0,0105 → 0,01 cada una; juntas 0,15 al 21 % = 0,0315 → 0,03.
        $invoice->addLine(new IssuedInvoiceLine('a', '0.05', '21'));
        $invoice->addLine(new IssuedInvoiceLine('b', '0.05', '21'));
        $invoice->addLine(new IssuedInvoiceLine('c', '0.05', '21'));

        $this->assertSame('0.03', $invoice->getTaxTotal());
    }

    public function testElNumeroSeImprimeComoLoLlevaLaAsociacion(): void
    {
        $ordinary = $this->issued(new IssuedInvoice(IssuedInvoice::SERIES_ORDINARY), 10, '2026-06-30');
        $x = $this->issued(new IssuedInvoice(IssuedInvoice::SERIES_X), 2, '2025-10-18');

        $this->assertSame('10/2026', $ordinary->getNumberLabel());
        $this->assertSame('X-02/2025', $x->getNumberLabel());
        $this->assertSame('Borrador', (new IssuedInvoice())->getNumberLabel());
    }

    /** Un borrador vacío dice todo lo que le falta, de una vez. */
    public function testUnBorradorVacioDiceLoQueLeFalta(): void
    {
        $this->assertSame(['Falta el cliente.', 'Falta la fecha.', 'No tiene ninguna línea.'], (new IssuedInvoice())->issueProblems());
    }

    public function testSinCifNoSeEmite(): void
    {
        $invoice = $this->imidra();
        $invoice->getCustomer()->setTaxId(null);

        $this->assertSame(['«IMIDRA» no tiene CIF: sin él no se le puede emitir una factura.'], $invoice->issueProblems());
    }

    /** Con una línea exenta hace falta el texto legal que lo explica. */
    public function testUnaLineaExentaPideElMotivo(): void
    {
        $invoice = $this->imidra();
        $invoice->setExemptionNote('  ');

        $this->assertSame(['Hay líneas exentas de IVA: falta el texto que explica por qué.'], $invoice->issueProblems());
    }

    /** Al emitir, copia el cliente: cambiarlo después no reescribe la factura. */
    public function testAlEmitirCopiaElClienteYLaCierra(): void
    {
        $invoice = $this->imidra();

        $invoice->issue(10, null, new \DateTimeImmutable('2026-07-01 10:00'));
        $invoice->getCustomer()->setAddress('Otra calle');

        $this->assertTrue($invoice->isIssued());
        $this->assertSame(2026, $invoice->getYear());
        $this->assertSame('C/ Leganitos, 47, 3ª planta', $invoice->getCustomerAddress());
        $this->assertSame('S7800035C', $invoice->getCustomerTaxId());
        $this->assertSame("Códigos DIR3:\nOG A13003089", $invoice->getCustomerNote());
    }

    /** Una emitida no se toca, ni ella ni sus líneas: se rectifica. */
    public function testUnaEmitidaNoSeCambia(): void
    {
        $invoice = $this->imidra();
        $invoice->issue(10, null, new \DateTimeImmutable());

        foreach ([
            static fn () => $invoice->setDescription('otra'),
            static fn () => $invoice->addLine(new IssuedInvoiceLine('más', '1.00')),
            static fn () => $invoice->getLines()->first()->setAmount('1.00'),
        ] as $change) {
            try {
                $change();
                $this->fail('Una factura emitida no debería poder cambiarse.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('se rectifica', $e->getMessage());
            }
        }
        $this->assertSame('3139.12', $invoice->getTotal());

        $this->expectException(\LogicException::class);
        $invoice->issue(11, null, new \DateTimeImmutable());
    }

    /** La rectificativa nace al mismo cliente, con las líneas en negativo y en su serie. */
    public function testLaRectificativaAnulaPorDefecto(): void
    {
        $original = $this->imidra();
        $original->issue(10, null, new \DateTimeImmutable());

        $rect = IssuedInvoice::rectificationOf($original);

        $this->assertTrue($rect->isRectifying());
        $this->assertSame($original, $rect->getRectifies());
        $this->assertSame($original->getCustomer(), $rect->getCustomer());
        $this->assertSame('-3139.12', $rect->getTotal());
        // La referencia a la original la pone el PDF siempre; la descripción queda para el motivo.
        $this->assertNull($rect->getDescription());

        $rect->setIssueDate(new \DateTimeImmutable('2026-07-10'));
        $rect->issue(1, null, new \DateTimeImmutable());
        $this->assertSame('RECT-01/2026', $rect->getNumberLabel());
    }

    /** Una rectificativa que no resta no es una rectificativa. */
    public function testUnaRectificativaTieneQueRestar(): void
    {
        $original = $this->imidra();
        $original->issue(10, null, new \DateTimeImmutable());
        $rect = IssuedInvoice::rectificationOf($original);
        $rect->setIssueDate(new \DateTimeImmutable('2026-07-10'));
        foreach ($rect->getLines()->toArray() as $line) {
            $line->setAmount(ltrim($line->getAmount(), '-'));
        }

        $this->assertSame(['Una rectificativa resta: el total tiene que ser negativo.'], $rect->issueProblems());
    }

    public function testUnBorradorNoSeRectifica(): void
    {
        $this->expectException(\LogicException::class);
        IssuedInvoice::rectificationOf($this->imidra());
    }

    /** La serie de rectificativas no se elige a mano. */
    public function testLaSerieDeRectificativasNoSeElige(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IssuedInvoice(IssuedInvoice::SERIES_RECTIFYING);
    }

    /** La cabecera del cliente: una línea cada dato, y la provincia sólo si no es el municipio. */
    public function testLaCabeceraDelClienteNoRepiteMadrid(): void
    {
        $invoice = $this->imidra();
        $invoice->getCustomer()->setProvince('Madrid');

        $this->assertSame(['IMIDRA', 'CIF: S7800035C', 'C/ Leganitos, 47, 3ª planta', '28013 Madrid'], $invoice->customerLines());

        $invoice->getCustomer()->setTown('Alcalá de Henares');
        $this->assertSame('Madrid', $invoice->customerLines()[4]);
    }

    /** Emitida, la cabecera sale de la copia: cambiar la ficha no la toca. */
    public function testLaCabeceraDeUnaEmitidaSaleDeLaCopia(): void
    {
        $invoice = $this->imidra();
        $invoice->issue(10, null, new \DateTimeImmutable());
        $invoice->getCustomer()->setName('Otro nombre')->setInvoiceNote('otra nota');

        $this->assertSame('IMIDRA', $invoice->customerLines()[0]);
        $this->assertSame("Códigos DIR3:\nOG A13003089", $invoice->customerNoteForPrint());
    }

    /** La base de datos devuelve «21.00»; se trata como «21». */
    public function testElTipoDeIvaDeLaBaseSeNormaliza(): void
    {
        $line = new IssuedInvoiceLine('x', '10.00', '21.00');

        $this->assertSame('21', $line->getVatRate());
        $this->assertSame('0', (new IssuedInvoiceLine('x', '10.00', '0.00'))->getVatRate());
        $this->assertTrue((new IssuedInvoiceLine('x', '10.00', '0.00'))->isExempt());
    }

    public function testSeCobraConElApunteDeSuImporteExacto(): void
    {
        $invoice = $this->imidra();
        $invoice->issue(10, null, new \DateTimeImmutable());
        $entry = (new AccountEntry())->setAmount('3139.12');

        $invoice->markPaid($entry);

        $this->assertTrue($invoice->isPaid());
        $this->assertSame($entry, $invoice->getAccountEntry());

        $invoice->unmarkPaid();
        $this->assertFalse($invoice->isPaid());
    }

    public function testUnBorradorNoSeCobra(): void
    {
        $this->expectException(\LogicException::class);
        $this->imidra()->markPaid((new AccountEntry())->setAmount('3139.12'));
    }

    public function testNoSeCobraConUnApunteDeOtroImporte(): void
    {
        $invoice = $this->imidra();
        $invoice->issue(10, null, new \DateTimeImmutable());

        $this->expectException(\LogicException::class);
        $invoice->markPaid((new AccountEntry())->setAmount('3139.11'));
    }

    public function testUnaRectificativaCasaConLaDevolucionNoConUnCobro(): void
    {
        $original = $this->imidra();
        $original->issue(10, null, new \DateTimeImmutable());
        $rect = IssuedInvoice::rectificationOf($original);
        $rect->setIssueDate(new \DateTimeImmutable('2026-07-01'));
        $rect->issue(1, null, new \DateTimeImmutable());

        $rect->markPaid((new AccountEntry())->setAmount('-3139.12'));
        $this->assertTrue($rect->isPaid());

        $this->expectException(\LogicException::class);
        $rect->markPaid((new AccountEntry())->setAmount('3139.12'));
    }

    private function imidra(): IssuedInvoice
    {
        $customer = (new Customer())
            ->setName('IMIDRA')
            ->setTaxId('S-7800035-C')
            ->setAddress('C/ Leganitos, 47, 3ª planta')
            ->setPostalCode('28013')
            ->setTown('Madrid')
            ->setInvoiceNote("Códigos DIR3:\nOG A13003089");

        return (new IssuedInvoice())
            ->setCustomer($customer)
            ->setIssueDate(new \DateTimeImmutable('2026-06-30'))
            ->setDescription('Inmersión formativa agroecológica, 23 a 26 de junio de 2026')
            ->setExemptionNote('Esta formación está exenta en virtud del art. 20.Uno.9º de la Ley 37/1992 del IVA.')
            ->addLine(new IssuedInvoiceLine('20 horas de formación (exenta de IVA)', '1600.00', '0'))
            ->addLine(new IssuedInvoiceLine('Gastos de estancia y manutención', '1272.00', '21'));
    }

    private function issued(IssuedInvoice $invoice, int $number, string $date): IssuedInvoice
    {
        $invoice->setCustomer((new Customer())->setName('Cliente')->setTaxId('B12345678'))
            ->setIssueDate(new \DateTimeImmutable($date))
            ->addLine(new IssuedInvoiceLine('Algo', '100.00', '21'));
        $invoice->issue($number, null, new \DateTimeImmutable());

        return $invoice;
    }
}
