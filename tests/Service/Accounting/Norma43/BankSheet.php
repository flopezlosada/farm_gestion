<?php

namespace App\Tests\Service\Accounting\Norma43;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Fabrica hojas de movimientos sintéticas como las que exporta la banca online, en
 * .xls o .xlsx de verdad (escritas con PhpSpreadsheet). Cifras y nombres inventados.
 */
final class BankSheet
{
    /**
     * Una hoja: filas de título, la cabecera y los movimientos. En cada movimiento, las
     * fechas van como 'Y-m-d' (se guardan como fecha de Excel) y los importes en euros.
     *
     * @param list<list<mixed>> $title    Filas antes de la cabecera.
     * @param list<string>      $header   Nombres de columna.
     * @param list<list<mixed>> $rows     Movimientos, en el orden de las columnas.
     * @param list<int>         $dateCols Índices de las columnas que son fechas.
     * @param string            $format   'Xls' o 'Xlsx'.
     */
    public static function build(array $title, array $header, array $rows, array $dateCols, string $format = 'Xls'): string
    {
        $book = new Spreadsheet();
        $data = [...$title, $header, ...array_map(static fn (array $row): array => array_map(
            static fn (mixed $cell, int $i): mixed => \in_array($i, $dateCols, true) && \is_string($cell) ? ExcelDate::PHPToExcel(new \DateTimeImmutable($cell)) : $cell,
            $row,
            array_keys($row),
        ), $rows)];
        $book->getActiveSheet()->fromArray($data, null, 'A1', true);

        $path = (string) tempnam(sys_get_temp_dir(), 'bank-sheet-test-');
        try {
            IOFactory::createWriter($book, $format)->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Como la de La Caixa: título con el IBAN, columnas Fecha / Fecha valor / Movimiento /
     * Más datos / Importe / Saldo, del más antiguo al más nuevo.
     *
     * @param list<array{0: string, 1: float, 2: string}> $movements Fecha, importe y concepto.
     */
    public static function caixaLike(string $iban, float $opening, array $movements, string $format = 'Xls'): string
    {
        $balance = $opening;
        $rows = [];
        foreach ($movements as [$date, $amount, $concept]) {
            $balance = round($balance + $amount, 2);
            $rows[] = [$date, $date, $concept, 'DATO EXTRA', $amount, $balance];
        }

        return self::build(
            [['Movimientos de la cuenta '.$iban], ['Importes expresados en euros']],
            ['Fecha', 'Fecha valor', 'Movimiento', 'Más datos', 'Importe', 'Saldo'],
            $rows,
            [0, 1],
            $format,
        );
    }

    /**
     * Como la de Fiare: sin título ni IBAN, columnas Fecha Ejecución / Fecha Valor /
     * Descripcion / Importe / Saldo, del más nuevo al más antiguo.
     *
     * @param list<array{0: string, 1: float, 2: string}> $movements Fecha, importe y concepto, del más antiguo al más nuevo.
     */
    public static function fiareLike(float $opening, array $movements, string $format = 'Xls'): string
    {
        $balance = $opening;
        $rows = [];
        foreach ($movements as [$date, $amount, $concept]) {
            $balance = round($balance + $amount, 2);
            $rows[] = [$date, $date, $concept, $amount, $balance];
        }

        return self::build([], ['Fecha Ejecución', 'Fecha Valor', 'Descripcion', 'Importe', 'Saldo'], array_reverse($rows), [0, 1], $format);
    }
}
