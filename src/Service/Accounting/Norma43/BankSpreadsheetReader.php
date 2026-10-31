<?php

namespace App\Service\Accounting\Norma43;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Lee la hoja de movimientos que la banca online exporta en Excel (.xls o .xlsx) y la
 * deja en las mismas piezas que un Norma 43: así el cuadre con el libro, el enganche
 * con los apuntes y las propuestas son los mismos venga el extracto como venga.
 *
 * No hay un formato de hoja común entre bancos, así que no se fía de posiciones: busca
 * la fila de cabecera (la que tiene «Fecha», «Importe» y «Saldo») y reconoce cada
 * columna por su nombre. Lo que no es fecha, importe ni saldo es texto del movimiento.
 *
 * La hoja no trae saldo inicial ni final, pero sí el saldo tras cada movimiento: se
 * exige que la cadena cuadre ENTERA (saldo anterior + importe = saldo) en un sentido
 * u otro, porque unos bancos la dan del más antiguo al más nuevo y otros al revés. Si
 * un solo eslabón falla, la hoja no vale: falta o sobra un movimiento, y el cuadre con
 * el libro no tendría base.
 */
class BankSpreadsheetReader
{
    /** Filas en las que se busca la cabecera: los bancos ponen encima el título y la cuenta. */
    private const HEADER_SEARCH_ROWS = 20;

    /** Un IBAN español en un título, con o sin espacios cada cuatro cifras. */
    private const IBAN_PATTERN = '/ES\d{2}(?:\s?\d{4}){5}/';

    /** Firmas del contenedor: Excel 97 (OLE) y Excel 2007+ (zip). */
    private const SIGNATURES = ["\xD0\xCF\x11\xE0", "PK\x03\x04"];

    /**
     * Si el contenido parece una hoja de cálculo y no un fichero de texto.
     *
     * @param string $raw Contenido del fichero subido.
     */
    public static function looksLikeSpreadsheet(string $raw): bool
    {
        foreach (self::SIGNATURES as $signature) {
            if (str_starts_with($raw, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lee la primera hoja del libro como el extracto de una sola cuenta.
     *
     * @param string $raw Contenido del fichero.
     *
     * @throws Norma43Exception Si no se reconoce la hoja o sus saldos no cuadran; el mensaje dice dónde.
     */
    public function read(string $raw): Norma43File
    {
        $rows = self::rows($raw);
        [$headerIndex, $columns] = self::header($rows);
        $movements = self::movements(\array_slice($rows, $headerIndex + 1, null, true), $columns);
        if ($movements === []) {
            throw new Norma43Exception('La hoja no tiene movimientos debajo de la cabecera.');
        }
        $chronological = self::chronological($movements);
        [$bank, $branch, $number] = self::accountFromTitle(\array_slice($rows, 0, $headerIndex));
        $dates = array_map(static fn (array $m): string => $m['movement']->operationDate->format('Y-m-d'), $chronological);
        $first = $chronological[0];
        $last = $chronological[\count($chronological) - 1];

        return new Norma43File([new Norma43Account(
            $bank,
            $branch,
            $number,
            new \DateTimeImmutable(min($dates)),
            new \DateTimeImmutable(max($dates)),
            $first['balance'] - $first['movement']->amountCents,
            $last['balance'],
            '',
            array_column($chronological, 'movement'),
        )], []);
    }

    /**
     * Las filas de la primera hoja con el valor crudo de cada celda (números y fechas
     * como número, sin el formato con que se ven).
     *
     * @return array<int, list<mixed>>
     *
     * @throws Norma43Exception
     */
    private static function rows(string $raw): array
    {
        $path = tempnam(sys_get_temp_dir(), 'bank-sheet-');
        if ($path === false || file_put_contents($path, $raw) === false) {
            throw new \RuntimeException('No se ha podido guardar la hoja para leerla.');
        }
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);

            return $reader->load($path)->getSheet(0)->toArray(null, false, false, false);
        } catch (ReaderException $e) {
            throw new Norma43Exception('No es una hoja de cálculo que se pueda leer: guárdala de nuevo como .xls o .xlsx.', 0, $e);
        } finally {
            @unlink($path);
        }
    }

    /**
     * La fila de cabecera y qué columna es cada cosa.
     *
     * @param array<int, list<mixed>> $rows
     *
     * @return array{0: int, 1: array{date: int, value: ?int, amount: int, balance: int, texts: list<int>}}
     *
     * @throws Norma43Exception
     */
    private static function header(array $rows): array
    {
        foreach (\array_slice($rows, 0, self::HEADER_SEARCH_ROWS, true) as $index => $row) {
            $names = array_map(static fn (mixed $cell): string => self::normalize((string) $cell), $row);
            $amount = array_search('importe', $names, true);
            $balance = array_search('saldo', $names, true);
            $value = array_search('fecha valor', $names, true);
            $dates = array_keys(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'fecha') && $n !== 'fecha valor'));
            if ($amount === false || $balance === false || $dates === []) {
                continue;
            }
            $used = [$amount, $balance, $value, $dates[0]];
            $texts = array_keys(array_filter($names, static fn (string $n, int $i): bool => $n !== '' && !\in_array($i, $used, true), \ARRAY_FILTER_USE_BOTH));

            return [$index, ['date' => $dates[0], 'value' => $value === false ? null : $value, 'amount' => $amount, 'balance' => $balance, 'texts' => $texts]];
        }

        throw new Norma43Exception(sprintf('No encuentro la fila de cabecera en las %d primeras filas: tiene que tener columnas «Fecha», «Importe» y «Saldo».', self::HEADER_SEARCH_ROWS));
    }

    /**
     * Los movimientos en el orden de la hoja, cada uno con el saldo que deja.
     *
     * @param array<int, list<mixed>>                                                         $rows    Por número de fila desde 0.
     * @param array{date: int, value: ?int, amount: int, balance: int, texts: list<int>} $columns
     *
     * @return list<array{movement: Norma43Movement, balance: int, row: int}>
     *
     * @throws Norma43Exception
     */
    private static function movements(array $rows, array $columns): array
    {
        $movements = [];
        foreach ($rows as $index => $row) {
            if (array_filter($row, static fn (mixed $cell): bool => $cell !== null && trim((string) $cell) !== '') === []) {
                continue;
            }
            $no = $index + 1;
            $date = self::date($row[$columns['date']] ?? null, $no);
            $movements[] = [
                'movement' => new Norma43Movement(
                    $date,
                    $columns['value'] !== null ? self::date($row[$columns['value']] ?? null, $no) : $date,
                    '',
                    '',
                    self::cents($row[$columns['amount']] ?? null, $no, 'importe'),
                    '',
                    '',
                    '',
                    array_values(array_filter(array_map(static fn (int $i): string => trim((string) ($row[$i] ?? '')), $columns['texts']), static fn (string $t): bool => $t !== '')),
                ),
                'balance' => self::cents($row[$columns['balance']] ?? null, $no, 'saldo'),
                'row' => $no,
            ];
        }

        return $movements;
    }

    /**
     * Los movimientos del más antiguo al más nuevo, comprobando que la cadena de saldos
     * cuadra en el sentido de la hoja.
     *
     * @param list<array{movement: Norma43Movement, balance: int, row: int}> $movements
     *
     * @return list<array{movement: Norma43Movement, balance: int, row: int}>
     *
     * @throws Norma43Exception
     */
    private static function chronological(array $movements): array
    {
        $oldestFirst = self::brokenLink($movements);
        if ($oldestFirst === null) {
            return $movements;
        }
        $reversed = array_reverse($movements);
        if (self::brokenLink($reversed) === null) {
            return $reversed;
        }

        throw new Norma43Exception(sprintf(
            'Los saldos no cuadran en la fila %d: el saldo anterior más el importe no da el saldo de esa fila. Falta o sobra algún movimiento; descarga la hoja otra vez.',
            $oldestFirst['row'],
        ));
    }

    /**
     * El primer movimiento cuyo saldo no es el del anterior más su importe, o null si
     * la cadena cuadra entera.
     *
     * @param list<array{movement: Norma43Movement, balance: int, row: int}> $movements
     *
     * @return array{movement: Norma43Movement, balance: int, row: int}|null
     */
    private static function brokenLink(array $movements): ?array
    {
        for ($i = 1, $n = \count($movements); $i < $n; ++$i) {
            if ($movements[$i - 1]['balance'] + $movements[$i]['movement']->amountCents !== $movements[$i]['balance']) {
                return $movements[$i];
            }
        }

        return null;
    }

    /**
     * Entidad, oficina y número de cuenta del IBAN que el banco pone en el título, o
     * vacíos si no lo pone (entonces la cuenta del libro se elige a mano).
     *
     * @param array<int, list<mixed>> $titleRows Las filas de encima de la cabecera.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function accountFromTitle(array $titleRows): array
    {
        foreach ($titleRows as $row) {
            foreach ($row as $cell) {
                if (preg_match(self::IBAN_PATTERN, (string) $cell, $match) === 1) {
                    $iban = str_replace(' ', '', $match[0]);

                    return [substr($iban, 4, 4), substr($iban, 8, 4), substr($iban, 14, 10)];
                }
            }
        }

        return ['', '', ''];
    }

    /**
     * Una fecha de la hoja: Excel la guarda como número de días.
     *
     * @throws Norma43Exception
     */
    private static function date(mixed $cell, int $row): \DateTimeImmutable
    {
        if (!is_numeric($cell)) {
            throw new Norma43Exception(sprintf('Fila %d: «%s» no es una fecha de la hoja.', $row, mb_substr((string) $cell, 0, 30)));
        }

        return new \DateTimeImmutable(ExcelDate::excelToDateTimeObject((float) $cell)->format('Y-m-d'));
    }

    /**
     * Un importe de la hoja en céntimos, con su signo.
     *
     * @throws Norma43Exception
     */
    private static function cents(mixed $cell, int $row, string $what): int
    {
        if (!is_numeric($cell)) {
            throw new Norma43Exception(sprintf('Fila %d: el %s «%s» no es un número.', $row, $what, mb_substr((string) $cell, 0, 30)));
        }

        return (int) round((float) $cell * 100);
    }

    /** El nombre de una columna sin mayúsculas, tildes ni blancos de más. */
    private static function normalize(string $name): string
    {
        $plain = strtr(mb_strtolower(trim($name)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

        return (string) preg_replace('/\s+/', ' ', $plain);
    }
}
