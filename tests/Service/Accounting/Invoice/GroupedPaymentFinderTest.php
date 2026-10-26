<?php

namespace App\Tests\Service\Accounting\Invoice;

use App\Entity\AccountEntry;
use App\Entity\ReceivedInvoice;
use App\Repository\AccountEntryRepository;
use App\Repository\ReceivedInvoiceRepository;
use App\Service\Accounting\Invoice\ExtractedInvoice;
use App\Service\Accounting\Invoice\GroupedPaymentFinder;
use PHPUnit\Framework\TestCase;

/**
 * El pago de varias facturas de un proveedor a la vez, sin base de datos: las otras
 * facturas abiertas y los pagos del libro los dan los repositorios.
 */
class GroupedPaymentFinderTest extends TestCase
{
    private const TAX_ID = 'B12345678';

    /** @var list<string> Los importes por los que se preguntó al libro. */
    private array $asked = [];

    /**
     * A igual suma, la explicación más sencilla: 100 + 30 antes que 100 + 10 + 20, y
     * el pago llega con las otras facturas que paga.
     */
    public function testAIgualSumaLaDeMenosFacturas(): void
    {
        $thirty = $this->invoice('30', '2091-03-10');
        $payment = $this->payment('-130.00');
        $finder = $this->finder([$this->invoice('10', '2091-03-12'), $thirty, $this->invoice('20', '2091-03-15')], [$payment]);

        $found = $finder->for($this->invoice('100', '2091-03-05'));

        $this->assertCount(1, $found);
        $this->assertSame($payment, $found[0]->payment);
        $this->assertSame([$thirty], $found[0]->others);
        $this->assertContains('-130.00', $this->asked);
        $this->assertContains('-160.00', $this->asked, 'Todas juntas también es una suma posible.');
    }

    /**
     * Un abono entre las otras puede sumar lo mismo que la factura sola: ese pago ya
     * sale entre los de importe exacto, no se pregunta como agrupado.
     */
    public function testLaSumaDeLaFacturaSolaNoEsUnPagoAgrupado(): void
    {
        $finder = $this->finder([$this->invoice('50', '2091-03-10'), $this->invoice('-50', '2091-03-11')], []);

        $finder->for($this->invoice('100', '2091-03-05'));

        $this->assertNotContains('-100.00', $this->asked);
        $this->assertContains('-150.00', $this->asked);
        $this->assertContains('-50.00', $this->asked);
    }

    /** Sin otras facturas del proveedor en la bandeja no se pregunta al libro. */
    public function testSinOtrasFacturasNoHayPagoAgrupado(): void
    {
        $entries = $this->createMock(AccountEntryRepository::class);
        $entries->expects($this->never())->method('findPaymentsAmong');
        $invoices = $this->createStub(ReceivedInvoiceRepository::class);
        $invoices->method('findOpenFromProvider')->willReturn([]);

        $this->assertSame([], (new GroupedPaymentFinder($invoices, $entries))->for($this->invoice('100', '2091-03-05')));
    }

    /**
     * @param list<ReceivedInvoice> $others   Las otras facturas abiertas del proveedor.
     * @param list<AccountEntry>    $payments Lo que devuelve el libro.
     */
    private function finder(array $others, array $payments): GroupedPaymentFinder
    {
        $invoices = $this->createStub(ReceivedInvoiceRepository::class);
        $invoices->method('findOpenFromProvider')->willReturn($others);
        $entries = $this->createStub(AccountEntryRepository::class);
        $entries->method('findPaymentsAmong')->willReturnCallback(function (array $amounts) use ($payments): array {
            $this->asked = $amounts;

            return $payments;
        });

        return new GroupedPaymentFinder($invoices, $entries);
    }

    private function invoice(string $total, string $date): ReceivedInvoice
    {
        $invoice = new ReceivedInvoice('f.pdf', 'f.pdf', 'application/pdf', ReceivedInvoice::SOURCE_WEB, null);
        $invoice->markRead(ExtractedInvoice::fromArray(['fecha' => $date, 'total' => (float) $total, 'cif_proveedor' => self::TAX_ID]), 'test', null, new \DateTimeImmutable());

        return $invoice;
    }

    private function payment(string $amount): AccountEntry
    {
        return (new AccountEntry())->setDate(new \DateTimeImmutable('2091-04-02'))->setConcept('trf.')->setAmount($amount);
    }
}
