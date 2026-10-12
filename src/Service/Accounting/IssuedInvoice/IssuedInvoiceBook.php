<?php

namespace App\Service\Accounting\IssuedInvoice;

use App\Entity\IssuedInvoice;
use App\Repository\IssuedInvoiceRepository;
use App\Service\Accounting\Invoice\InvoiceQuarter;
use App\Service\Accounting\Invoice\LedgerSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Las facturas emitidas de un trimestre, como van a la gestoría: el libro de
 * facturas emitidas y el nombre de cada PDF dentro del ZIP. Es la pareja del libro
 * de recibidas de {@see \App\Service\Accounting\Invoice\AccountantPackage}, que es
 * quien lo mete en el paquete.
 */
class IssuedInvoiceBook
{
    /** Columnas del libro, en orden. */
    private const COLUMNS = [
        'Fecha', 'Nº factura', 'Cliente', 'CIF / NIF', 'Base imponible', '% IVA', 'Cuota IVA',
        'Total factura', 'Rectifica a', 'Fichero', 'Observaciones',
    ];

    /** Columnas con importes: llevan formato de euros y suma al final. */
    private const MONEY_COLUMNS = ['E', 'G', 'H'];

    /** Largo máximo del nombre del cliente dentro del nombre de un fichero. */
    private const MAX_CUSTOMER = 40;

    public function __construct(
        private readonly IssuedInvoiceRepository $invoices,
        private readonly IssuedInvoiceArchive $archive,
    ) {
    }

    /**
     * Las emitidas con fecha del trimestre.
     *
     * @return list<IssuedInvoice>
     */
    public function invoicesOf(InvoiceQuarter $quarter): array
    {
        return $this->invoices->findIssuedBetween($quarter->from(), $quarter->to());
    }

    /** Dónde está el PDF guardado de cada una; null si falta. */
    public function pdfPath(IssuedInvoice $invoice): ?string
    {
        return $this->archive->pathTo($invoice);
    }

    /**
     * El nombre de cada PDF dentro del ZIP, como se nombran hoy a mano:
     * «FRA 10-2026 IMIDRA 30.06.2026.pdf», «FRA X-02-2025 …», «FRA RECT-01-2026 …».
     *
     * @param list<IssuedInvoice> $invoices Emitidas.
     *
     * @return array<int, string> Por id de factura.
     */
    public static function fileNames(array $invoices): array
    {
        $names = [];
        foreach ($invoices as $invoice) {
            $name = sprintf(
                'FRA %s %s %s',
                str_replace('/', '-', $invoice->getNumberLabel()),
                mb_substr(self::customerShortName($invoice), 0, self::MAX_CUSTOMER),
                $invoice->getIssueDate()?->format('d.m.Y') ?? '',
            );
            $names[$invoice->getId()] = LedgerSheet::safeFileName($name) . '.pdf';
        }

        return $names;
    }

    /**
     * El libro de facturas emitidas: una fila por cada tipo de IVA de cada factura,
     * como el de recibidas y como lo pide el libro registro. El total va sólo en la
     * primera fila de cada factura, para que la suma de la columna sea la de verdad;
     * al final, los totales.
     *
     * @param list<IssuedInvoice>     $invoices Las del trimestre.
     * @param array<int, string>      $names    Nombre de cada PDF en el ZIP.
     * @param array<int, string|null> $paths    Ruta de cada PDF, null si falta.
     */
    public function ledger(InvoiceQuarter $quarter, array $invoices, array $names, array $paths): Spreadsheet
    {
        $book = LedgerSheet::start('Emitidas ' . $quarter->label(), self::COLUMNS);
        $sheet = $book->getActiveSheet();

        $row = 2;
        foreach ($invoices as $invoice) {
            foreach ($invoice->taxBreakdown() as $i => $tax) {
                $first = $i === 0;
                $sheet->fromArray([
                    ExcelDate::PHPToExcel($invoice->getIssueDate()),
                    $invoice->getNumberLabel(),
                    self::customerShortName($invoice),
                    $invoice->getCustomerTaxId(),
                    (float) $tax['base'],
                    (float) $tax['rate'],
                    (float) $tax['tax'],
                    $first ? (float) $invoice->getTotal() : null,
                    $first ? $invoice->getRectifies()?->getNumberLabel() : null,
                    $first ? $names[$invoice->getId()] : null,
                    $first ? implode('; ', self::warnings($invoice, $paths[$invoice->getId()] ?? null)) : null,
                ], null, 'A' . $row, true);
                ++$row;
            }
        }

        LedgerSheet::finish($sheet, $row, self::COLUMNS, self::MONEY_COLUMNS);

        return $book;
    }

    /** La primera línea de la razón social: la segunda, si la hay, es un añadido. */
    private static function customerShortName(IssuedInvoice $invoice): string
    {
        $first = trim(explode("\n", (string) $invoice->getCustomerName())[0]);

        return $first !== '' ? $first : 'Sin cliente';
    }

    /**
     * Lo que la gestoría tiene que saber de una emitida.
     *
     * @return list<string>
     */
    public static function warnings(IssuedInvoice $invoice, ?string $path): array
    {
        return $path === null ? ['falta el PDF'] : [];
    }
}
