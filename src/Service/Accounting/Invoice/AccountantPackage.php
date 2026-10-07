<?php

namespace App\Service\Accounting\Invoice;

use App\Entity\ReceivedInvoice;
use App\Repository\ReceivedInvoiceRepository;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipStream\ZipStream;

/**
 * Lo que se le entrega a la gestoría cada trimestre: las facturas recibidas, cada
 * una renombrada como se hace hoy a mano («<Proveedor> Fact <nº> pago tarjeta»), y el
 * libro de facturas recibidas en una hoja de cálculo.
 *
 * Sustituye a copiar y renombrar a mano las facturas de una carpeta del Dropbox a la
 * de «Envíos gestoría». La subida a la carpeta compartida de la gestoría sigue siendo
 * a mano: es un ZIP que se descomprime y se arrastra.
 *
 * El ZIP se escribe en un flujo (ZipStream, PHP puro) sin montarlo entero en memoria
 * ni en disco, y sin depender de la extensión zip, que el hosting puede no tener.
 */
class AccountantPackage
{
    /** Columnas del libro, en orden. */
    private const COLUMNS = [
        'Fecha', 'Nº factura', 'Proveedor', 'CIF / NIF', 'Base imponible', '% IVA', 'Cuota IVA',
        'Retención IRPF', '% retención', 'Total factura', 'Forma de pago', 'Partida', 'Fichero', 'Observaciones',
    ];

    /** Columnas con importes: llevan formato de euros y suma al final. */
    private const MONEY_COLUMNS = ['E', 'G', 'H', 'J'];

    /** Longitud máxima del nombre de un fichero dentro del ZIP, sin la extensión. */
    private const MAX_NAME = 120;

    /**
     * @param ReceivedInvoiceRepository $invoices Para las facturas del trimestre.
     * @param InvoiceFileStore          $files    Donde están los documentos.
     */
    public function __construct(
        private readonly ReceivedInvoiceRepository $invoices,
        private readonly InvoiceFileStore $files,
    ) {
    }

    /**
     * Las facturas anotadas del trimestre, en orden de fecha.
     *
     * @param InvoiceQuarter $quarter El trimestre.
     *
     * @return list<ReceivedInvoice>
     */
    public function invoicesOf(InvoiceQuarter $quarter): array
    {
        return $this->invoices->findConfirmedBetween($quarter->from(), $quarter->to());
    }

    /**
     * Escribe el ZIP del trimestre en un flujo abierto (la salida de la respuesta).
     *
     * @param InvoiceQuarter $quarter El trimestre.
     * @param resource       $output  Flujo donde se escribe.
     */
    public function writeZip(InvoiceQuarter $quarter, $output): void
    {
        $invoices = $this->invoicesOf($quarter);
        $names = self::fileNames($invoices);
        $folder = 'Facturas recibidas '.$quarter->label();

        $paths = [];
        foreach ($invoices as $invoice) {
            $paths[$invoice->getId()] = $this->files->pathTo($invoice->getFileName());
        }

        $zip = new ZipStream(outputStream: $output, sendHttpHeaders: false);

        $ledger = tempnam(sys_get_temp_dir(), 'libro');
        try {
            (new Xlsx($this->ledger($quarter, $invoices, $names, $paths)))->save($ledger);
            $zip->addFileFromPath(fileName: sprintf('Libro facturas recibidas %s.xlsx', $quarter->label()), path: $ledger);

            foreach ($invoices as $invoice) {
                $path = $paths[$invoice->getId()];
                if ($path !== null) {
                    $zip->addFileFromPath(fileName: $folder.'/'.$names[$invoice->getId()], path: $path);
                }
            }

            $zip->finish();
        } finally {
            @unlink($ledger);
        }
    }

    /**
     * El libro de facturas recibidas: una fila por cada tipo de IVA de cada factura,
     * que es como lo pide el libro registro y como se rellena el modelo 303. La
     * retención y el total van sólo en la primera fila de cada factura, para que la
     * suma de cada columna sea la de verdad; al final, los totales.
     *
     * @param InvoiceQuarter          $quarter  El trimestre.
     * @param list<ReceivedInvoice>   $invoices Sus facturas.
     * @param array<int, string>      $names    Nombre de cada fichero en el ZIP.
     * @param array<int, string|null> $paths    Ruta de cada documento, null si falta.
     */
    public function ledger(InvoiceQuarter $quarter, array $invoices, array $names, array $paths): Spreadsheet
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Recibidas '.$quarter->label());
        $sheet->fromArray(self::COLUMNS, null, 'A1');
        $sheet->getStyle('A1:N1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        $row = 2;
        foreach ($invoices as $invoice) {
            $lines = $invoice->getTaxLines()->toArray() ?: [null];
            foreach (array_values($lines) as $i => $line) {
                $first = $i === 0;
                $date = $invoice->getInvoiceDate() ?? $invoice->getAccountEntry()?->getDate();
                $sheet->fromArray([
                    $date !== null ? ExcelDate::PHPToExcel($date) : null,
                    $invoice->getInvoiceNumber(),
                    $invoice->getProvider()?->getName() ?? $invoice->getProviderName(),
                    $invoice->getProvider()?->getTaxId() ?? $invoice->getProviderTaxId(),
                    self::number($line?->getBase()),
                    self::number($line?->getRate()),
                    self::number($line?->getTaxAmount()),
                    $first ? self::number($invoice->getWithholding()) : null,
                    $first ? self::number($invoice->getWithholdingRate()) : null,
                    $first ? self::number($invoice->getTotal()) : null,
                    $first ? $invoice->getPaymentLabel() : null,
                    $first ? $invoice->getAccountEntry()?->getCategory()?->getName() : null,
                    $first ? $names[$invoice->getId()] : null,
                    $first ? implode('; ', self::warnings($invoice, $paths[$invoice->getId()] ?? null)) : null,
                ], null, 'A'.$row, true);
                ++$row;
            }
        }

        $last = $row - 1;
        $sheet->setCellValue('A'.$row, 'Total');
        $sheet->getStyle('A'.$row.':N'.$row)->getFont()->setBold(true);
        foreach (self::MONEY_COLUMNS as $col) {
            $sheet->setCellValue($col.$row, $last >= 2 ? sprintf('=SUM(%1$s2:%1$s%2$d)', $col, $last) : 0);
            $sheet->getStyle($col.'2:'.$col.$row)->getNumberFormat()->setFormatCode('#,##0.00 €');
        }
        $sheet->getStyle('A2:A'.$last)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return $book;
    }

    /**
     * El nombre de cada factura dentro del ZIP, como se renombran hoy a mano:
     * «<Proveedor> Fact <nº>», con « pago tarjeta» si se pagó con tarjeta. Sin
     * número, la fecha. Dos que darían el mismo nombre se distinguen con « (2)».
     *
     * @param list<ReceivedInvoice> $invoices Las facturas.
     *
     * @return array<int, string> Por id de factura.
     */
    public static function fileNames(array $invoices): array
    {
        $names = [];
        $taken = [];
        foreach ($invoices as $invoice) {
            $base = self::baseName($invoice);
            $extension = strtolower(pathinfo($invoice->getFileName(), \PATHINFO_EXTENSION));
            $suffix = $extension !== '' ? '.'.$extension : '';

            $name = $base.$suffix;
            for ($n = 2; isset($taken[mb_strtolower($name)]); ++$n) {
                $name = sprintf('%s (%d)%s', $base, $n, $suffix);
            }
            $taken[mb_strtolower($name)] = true;
            $names[$invoice->getId()] = $name;
        }

        return $names;
    }

    /**
     * Lo que la gestoría tiene que saber de una factura: que su desglose no cuadra
     * con el total, que no lo tiene, o que su documento no está.
     *
     * @param ReceivedInvoice $invoice La factura.
     * @param string|null     $path    Ruta de su documento, null si falta.
     *
     * @return list<string>
     */
    public static function warnings(ReceivedInvoice $invoice, ?string $path): array
    {
        $warnings = [];
        $breakdown = $invoice->totalFromBreakdown();
        if ($breakdown === null) {
            $warnings[] = 'sin desglose de IVA';
        } elseif ($invoice->getTotal() !== null && abs($breakdown - (float) $invoice->getTotal()) >= 0.01) {
            $warnings[] = sprintf('el desglose suma %s y el total es %s', number_format($breakdown, 2, ',', '.'), number_format((float) $invoice->getTotal(), 2, ',', '.'));
        }
        if ($path === null) {
            $warnings[] = 'falta el documento';
        }

        return $warnings;
    }

    private static function baseName(ReceivedInvoice $invoice): string
    {
        $provider = $invoice->getProvider()?->getName() ?? $invoice->getProviderName() ?? 'Sin proveedor';
        $number = $invoice->getInvoiceNumber();
        $date = $invoice->getInvoiceDate() ?? $invoice->getAccountEntry()?->getDate();

        $name = $number !== null && trim($number) !== ''
            ? sprintf('%s Fact %s', $provider, $number)
            : sprintf('%s %s', $provider, $date?->format('Y-m-d') ?? 'sin fecha');
        if ($invoice->getPaymentMethod() === ReceivedInvoice::PAYMENT_CARD) {
            $name .= ' pago tarjeta';
        }

        // Fuera lo que un sistema de ficheros no admite: «194/26» sería una carpeta.
        $name = (string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '-', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), ' .-');

        return mb_substr($name !== '' ? $name : 'Factura', 0, self::MAX_NAME);
    }

    private static function number(?string $value): ?float
    {
        return $value !== null && $value !== '' ? (float) $value : null;
    }
}
