<?php

namespace App\Service\Accounting\Invoice;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Lo común a los libros que van a la gestoría (facturas recibidas y emitidas): la
 * hoja con su cabecera, el pie con los totales y formatos, y los nombres de fichero
 * que un sistema de ficheros admite.
 */
final class LedgerSheet
{
    /**
     * Un libro nuevo con la cabecera en negrita y fija al desplazarse.
     *
     * @param list<string> $columns Títulos de las columnas, en orden.
     */
    public static function start(string $title, array $columns): Spreadsheet
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($title);
        $sheet->fromArray($columns, null, 'A1');
        $sheet->getStyle('A1:' . self::lastColumn($columns) . '1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        return $book;
    }

    /**
     * Cierra la hoja: fila de totales en `$row` (la primera libre), euros en las
     * columnas de importes, fecha en la A y anchos ajustados.
     *
     * @param list<string> $columns      Títulos de las columnas.
     * @param list<string> $moneyColumns Letras de las columnas con importes.
     */
    public static function finish(Worksheet $sheet, int $row, array $columns, array $moneyColumns): void
    {
        $lastColumn = self::lastColumn($columns);
        $last = $row - 1;
        $sheet->setCellValue('A' . $row, 'Total');
        $sheet->getStyle('A' . $row . ':' . $lastColumn . $row)->getFont()->setBold(true);
        foreach ($moneyColumns as $col) {
            $sheet->setCellValue($col . $row, $last >= 2 ? sprintf('=SUM(%1$s2:%1$s%2$d)', $col, $last) : 0);
            $sheet->getStyle($col . '2:' . $col . $row)->getNumberFormat()->setFormatCode('#,##0.00 €');
        }
        $sheet->getStyle('A2:A' . $last)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        foreach (range('A', $lastColumn) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * Un nombre que vale como fichero: fuera lo que un sistema de ficheros no admite
     * («194/26» sería una carpeta), espacios de más y puntos al final.
     */
    public static function safeFileName(string $name): string
    {
        $name = (string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '-', $name);

        return trim((string) preg_replace('/\s+/u', ' ', $name), ' .-');
    }

    /** @param list<string> $columns */
    private static function lastColumn(array $columns): string
    {
        return chr(ord('A') + \count($columns) - 1);
    }
}
