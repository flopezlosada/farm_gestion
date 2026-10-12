<?php

namespace App\Tests\Service\Accounting\IssuedInvoice;

use App\Entity\Customer;
use App\Entity\IssuedInvoice;
use App\Entity\IssuedInvoiceLine;
use App\Repository\IssuedInvoiceRepository;
use App\Service\Accounting\Invoice\InvoiceQuarter;
use App\Service\Accounting\IssuedInvoice\IssuedInvoiceArchive;
use App\Service\Accounting\IssuedInvoice\IssuedInvoiceBook;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Lo que va de las emitidas a la gestoría: cómo se llama cada PDF dentro del ZIP
 * (como se nombran hoy a mano) y el libro, con una fila por tipo de IVA.
 */
class IssuedInvoiceBookTest extends TestCase
{
    private int $nextId = 1;

    /** «FRA <nº con guion> <cliente> <dd.mm.aaaa>», sin la segunda línea de la razón social. */
    public function testElNombreSigueElPatronDeLaAsociacion(): void
    {
        $ordinary = $this->issued(IssuedInvoice::SERIES_ORDINARY, 10, '2026-06-30', "IMIDRA\nInstituto madrileño");
        $x = $this->issued(IssuedInvoice::SERIES_X, 2, '2025-10-18', 'RESILVESTRA / Iniciativas');

        $names = IssuedInvoiceBook::fileNames([$ordinary, $x]);

        $this->assertSame('FRA 10-2026 IMIDRA 30.06.2026.pdf', $names[$ordinary->getId()]);
        $this->assertSame('FRA X-02-2025 RESILVESTRA - Iniciativas 18.10.2025.pdf', $names[$x->getId()], 'la barra sería una carpeta');
    }

    /**
     * Una fila por tipo de IVA; el total sólo en la primera, para que la columna
     * sume de verdad; la rectificativa dice a cuál rectifica.
     */
    public function testElLibroLlevaUnaFilaPorTipoDeIva(): void
    {
        $imidra = $this->issued(IssuedInvoice::SERIES_ORDINARY, 10, '2026-06-30', 'IMIDRA', [
            ['20 horas de formación', '1600.00', '0'],
            ['Estancia', '1272.00', '21'],
        ]);
        $rect = IssuedInvoice::rectificationOf($imidra);
        foreach ($rect->getLines()->toArray() as $line) {
            if ($line->isExempt()) {
                $rect->removeLine($line);
            }
        }
        $rect->setIssueDate(new \DateTimeImmutable('2026-06-30'));
        $rect->issue(1, null, new \DateTimeImmutable());
        $this->setId($rect);

        $book = new IssuedInvoiceBook($this->createStub(IssuedInvoiceRepository::class), new IssuedInvoiceArchive(sys_get_temp_dir(), new Filesystem()));
        $invoices = [$imidra, $rect];
        $sheet = $book->ledger(InvoiceQuarter::of(2026, 2), $invoices, IssuedInvoiceBook::fileNames($invoices), [$imidra->getId() => '/x.pdf', $rect->getId() => null])->getActiveSheet();

        $this->assertSame(['10/2026', 'IMIDRA', 'S7800035C', 1600.0, 0.0, 0.0, 3139.12], $this->row($sheet, 2, ['B', 'C', 'D', 'E', 'F', 'G', 'H']));
        $this->assertSame(['10/2026', 1272.0, 21.0, 267.12, null], $this->row($sheet, 3, ['B', 'E', 'F', 'G', 'H']));
        $this->assertSame(['RECT-01/2026', -1272.0, -267.12, -1539.12, '10/2026', 'falta el PDF'], $this->row($sheet, 4, ['B', 'E', 'G', 'H', 'I', 'K']));
        $this->assertSame('Total', $sheet->getCell('A5')->getValue());
        $this->assertSame('=SUM(H2:H4)', $sheet->getCell('H5')->getValue());
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $lines
     */
    private function issued(string $series, int $number, string $date, string $customerName, array $lines = [['Algo', '100.00', '21']]): IssuedInvoice
    {
        $invoice = (new IssuedInvoice($series))
            ->setCustomer((new Customer())->setName($customerName)->setTaxId('S7800035C'))
            ->setIssueDate(new \DateTimeImmutable($date))
            ->setExemptionNote('Exenta.');
        foreach ($lines as [$concept, $amount, $rate]) {
            $invoice->addLine(new IssuedInvoiceLine($concept, $amount, $rate));
        }
        $invoice->issue($number, null, new \DateTimeImmutable());
        $this->setId($invoice);

        return $invoice;
    }

    private function setId(IssuedInvoice $invoice): void
    {
        (new \ReflectionProperty(IssuedInvoice::class, 'id'))->setValue($invoice, $this->nextId++);
    }

    /**
     * @param list<string> $columns
     *
     * @return list<mixed>
     */
    private function row($sheet, int $row, array $columns): array
    {
        return array_map(static fn (string $c) => $sheet->getCell($c . $row)->getValue(), $columns);
    }
}
