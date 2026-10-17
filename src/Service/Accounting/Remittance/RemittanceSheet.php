<?php

namespace App\Service\Accounting\Remittance;

use App\Entity\Remittance;
use App\Entity\RemittanceLine;
use App\Service\Accounting\Amount;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * El Excel de recibos, en los dos sentidos: leer los que ya se enviaron y escribir el
 * nuevo con las mismas columnas, que son las que Fiare pide al importar (cuaderno 19).
 *
 * Al leer, las columnas se buscan por su TÍTULO y no por su posición: el Excel se hace
 * a mano cada mes y una columna de más o en otro orden no debe colar un IBAN en el
 * importe. La fila de títulos puede no ser la primera.
 */
final class RemittanceSheet
{
    public const COL_NAME = 'NOMBRE DEUDOR';
    public const COL_IBAN = 'CUENTA CARGO';
    public const COL_MANDATE = 'REFERENCIA MANDATO';
    public const COL_SIGNED_ON = 'FECHA FIRMA MANDATO';
    public const COL_DEBIT_REFERENCE = 'REFERENCIA ADEUDO';
    public const COL_SEQUENCE = 'TIPO DE ADEUDO';
    public const COL_AMOUNT = 'IMPORTE';

    /** Las columnas, en el orden del Excel que se hace hoy. */
    public const COLUMNS = [
        self::COL_NAME, self::COL_IBAN, self::COL_MANDATE, self::COL_SIGNED_ON,
        self::COL_DEBIT_REFERENCE, self::COL_SEQUENCE, self::COL_AMOUNT,
    ];

    /** Los formatos que se aceptan; un CSV o un HTML con extensión de Excel, no. */
    private const READERS = ['Xlsx', 'Xls', 'Ods'];

    /** Más filas que esto no es un Excel de recibos de una asociación. */
    private const MAX_ROWS = 2000;

    /** Hasta qué fila se busca la de títulos. */
    private const HEADER_SEARCH_ROWS = 15;

    /**
     * El tipo de adeudo escrito con palabras, por si no viene el código, con los
     * rótulos del banco. El orden importa: «Primer adeudo de una serie de adeudos
     * recurrentes» lleva también «recurrentes».
     */
    private const SEQUENCE_WORDS = [
        'PRIMER' => RemittanceLine::SEQUENCE_FIRST,
        'ULTIMO' => RemittanceLine::SEQUENCE_FINAL,
        'UNICO' => RemittanceLine::SEQUENCE_ONE_OFF,
        'RECURRENTE' => RemittanceLine::SEQUENCE_RECURRING,
    ];

    /**
     * Lee los recibos de un Excel. No guarda nada: devuelve los recibos sin socix
     * asignado y la lista de lo que no se ha entendido. Quien lo usa no debe guardar
     * nada si hay problemas, porque una remesa a medias es peor que ninguna; si falta
     * alguna columna, ni siquiera se devuelven recibos.
     *
     * @return array{lines: list<RemittanceLine>, problems: list<string>}
     */
    public function read(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            if (!\in_array((new \ReflectionClass($reader))->getShortName(), self::READERS, true)) {
                return ['lines' => [], 'problems' => ['Tiene que ser un Excel (.xlsx, .xls) u hoja de LibreOffice (.ods).']];
            }
            $sheet = $reader->load($path)->getActiveSheet();
            if ($sheet->getHighestDataRow() > self::MAX_ROWS) {
                return ['lines' => [], 'problems' => [sprintf('Tiene más de %d filas: no parece un Excel de recibos.', self::MAX_ROWS)]];
            }
            $rows = $sheet->toArray(null, true, false, false);
        } catch (\Throwable) {
            return ['lines' => [], 'problems' => ['No se puede leer el fichero como hoja de cálculo.']];
        }

        $header = $this->findHeader($rows);
        if ($header === null) {
            return ['lines' => [], 'problems' => [sprintf('No encuentro la fila de títulos: tiene que llevar «%s».', implode('», «', self::COLUMNS))]];
        }
        [$headerRow, $columns] = $header;
        $missing = array_values(array_diff(self::COLUMNS, array_keys($columns)));
        if ($missing !== []) {
            return ['lines' => [], 'problems' => [sprintf('Faltan columnas: «%s».', implode('», «', $missing))]];
        }

        $lines = [];
        $problems = [];
        foreach (\array_slice($rows, $headerRow + 1, null, true) as $index => $row) {
            $cell = static fn (string $col): mixed => $row[$columns[$col]] ?? null;
            $name = trim((string) $cell(self::COL_NAME));
            $iban = RemittanceLine::normalizeIban((string) $cell(self::COL_IBAN));
            $isNote = trim((string) $cell(self::COL_MANDATE)) === '' && trim((string) $cell(self::COL_AMOUNT)) === '';
            if ($iban === '' && ($name === '' || $isNote || str_starts_with(mb_strtoupper($name), 'TOTAL'))) {
                continue; // fila vacía, de totales o una nota escrita debajo de los recibos
            }

            $rowNumber = $index + 1;
            $signedOn = $this->date($cell(self::COL_SIGNED_ON));
            $amount = Amount::parse($cell(self::COL_AMOUNT));
            if ($amount === null) {
                $problems[] = sprintf('Fila %d (%s): el importe «%s» no es un número.', $rowNumber, $name, (string) $cell(self::COL_AMOUNT));
                continue;
            }
            if ($signedOn === null && trim((string) $cell(self::COL_SIGNED_ON)) !== '') {
                $problems[] = sprintf('Fila %d (%s): la fecha de firma «%s» no se entiende.', $rowNumber, $name, (string) $cell(self::COL_SIGNED_ON));
            }

            $line = (new RemittanceLine())
                ->setDebtorName($name)
                ->setIban($iban)
                ->setMandateReference((string) $cell(self::COL_MANDATE))
                ->setMandateSignedOn($signedOn)
                ->setDebitReference((string) $cell(self::COL_DEBIT_REFERENCE))
                ->setSequenceType($this->sequence((string) $cell(self::COL_SEQUENCE)))
                ->setAmount($amount);
            // Lo que el banco no admitiría (o la base no cabe) se dice aquí, no al
            // guardar. Dos cosas se dejan pasar porque lo leído ya se envió así: la
            // fecha de firma vacía y el importe a cero (un mes sin cuota). Se copian
            // al borrador siguiente, que no se envía hasta corregirlas.
            $tolerated = ['falta la fecha de firma del mandato.', 'el importe tiene que ser mayor que cero.'];
            foreach (array_diff($line->problems(), $tolerated) as $problem) {
                $problems[] = sprintf('Fila %d (%s): %s', $rowNumber, $name !== '' ? $name : 'sin nombre', $problem);
            }
            if (\strlen($line->getIban()) > 34) {
                $problems[] = sprintf('Fila %d (%s): la cuenta «%s» es demasiado larga.', $rowNumber, $name, $line->getIban());
            }
            $lines[] = $line;
        }

        return ['lines' => $lines, 'problems' => $problems];
    }

    /**
     * El Excel de una remesa, listo para subir a Fiare: sólo la fila de títulos y los
     * recibos. Sin fila de totales, porque el banco importa un rango de filas y una de
     * totales se le colaría como un recibo más.
     */
    public function write(Remittance $remittance): Spreadsheet
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Recibos');
        $sheet->fromArray(self::COLUMNS, null, 'A1');
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);

        $row = 2;
        foreach ($remittance->getLines() as $line) {
            $sheet->setCellValueExplicit('A' . $row, $line->getDebtorName(), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B' . $row, $line->getIban(), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('C' . $row, $line->getMandateReference(), DataType::TYPE_STRING);
            $sheet->setCellValue('D' . $row, $line->getMandateSignedOn() !== null ? ExcelDate::PHPToExcel($line->getMandateSignedOn()) : null);
            $sheet->setCellValueExplicit('E' . $row, $line->getDebitReference(), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('F' . $row, $line->getSequenceType(), DataType::TYPE_STRING);
            $sheet->setCellValue('G' . $row, (float) $line->getAmount());
            ++$row;
        }

        $last = max(2, $row - 1);
        $sheet->getStyle('D2:D' . $last)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->getStyle('G2:G' . $last)->getNumberFormat()->setFormatCode('0.00');
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return $book;
    }

    /**
     * La fila de títulos y en qué columna está cada uno.
     *
     * @param array<int, array<int, mixed>> $rows
     *
     * @return array{0: int, 1: array<string, int>}|null
     */
    private function findHeader(array $rows): ?array
    {
        foreach (\array_slice($rows, 0, self::HEADER_SEARCH_ROWS, true) as $index => $row) {
            $columns = [];
            foreach ($row as $col => $value) {
                $title = self::normalize((string) $value);
                if (\in_array($title, self::COLUMNS, true) && !isset($columns[$title])) {
                    $columns[$title] = $col;
                }
            }
            if (isset($columns[self::COL_NAME])) {
                return [$index, $columns];
            }
        }

        return null;
    }

    /** Una fecha de Excel: número de serie (44896) o texto («01/11/2022»). */
    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (is_numeric($value)) {
            return \DateTimeImmutable::createFromMutable(ExcelDate::excelToDateTimeObject((float) $value))->setTime(0, 0);
        }
        $text = trim((string) $value);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $text);
            if ($date !== false && $date->format($format) === $text) {
                return $date;
            }
        }

        return null;
    }

    private function sequence(string $value): string
    {
        $text = self::normalize($value);
        foreach (self::SEQUENCE_WORDS as $word => $code) {
            if (str_contains($text, $word)) {
                return $code;
            }
        }

        return $text;
    }

    /** Mayúsculas, sin tildes ni espacios de más: para comparar títulos y palabras. */
    private static function normalize(string $text): string
    {
        $text = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $text)));

        return strtr($text, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U']);
    }
}
