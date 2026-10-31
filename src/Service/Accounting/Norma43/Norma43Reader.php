<?php

namespace App\Service\Accounting\Norma43;

/**
 * Lee un extracto bancario en el formato del cuaderno 43 de la AEB/CECA (Norma 43) y
 * comprueba que es coherente consigo mismo antes de dar por buena una sola cifra.
 *
 * El fichero son líneas de 80 posiciones: por cada cuenta, una cabecera (11), sus
 * movimientos (22, con hasta cinco complementarios 23 y un 24 de divisa) y un cierre
 * (33); al final, un registro de fin (88). Las posiciones de este código son las del
 * Anexo 1 del cuaderno (junio de 2012): desde 1 y con los dos extremos incluidos.
 *
 * Si algo no cuadra se rechaza el fichero ENTERO: un extracto a medias deja el cuadre
 * con el libro sin base, y el banco puede volver a darlo bien. Lo que el banco suele
 * hacer sin malicia (saltos de línea de Windows, blancos finales recortados, el 88
 * olvidado) se tolera.
 */
class Norma43Reader
{
    /** Clave numérica ISO del euro: la única divisa que lleva el libro. */
    public const EURO = '978';

    /** Largo de cada registro. */
    public const LINE_LENGTH = 80;

    /** Registros complementarios de concepto (23) que admite un movimiento. */
    private const MAX_COMPLEMENTS = 5;

    /**
     * Lee el fichero tal como llega del banco.
     *
     * @param string $raw Contenido del fichero, en la codificación que traiga.
     *
     * @throws Norma43Exception Si no es un Norma 43 válido; el mensaje dice dónde y por qué.
     */
    public function read(string $raw): Norma43File
    {
        $accounts = [];
        $open = null;      // la cuenta en curso, entre su 11 y su 33
        $records = 0;      // registros antes del 88, que es lo que el 88 cuenta
        $ended = false;

        foreach (self::lines(self::toUtf8($raw)) as $index => $line) {
            $no = $index + 1;
            if ($ended) {
                throw new Norma43Exception(sprintf('Línea %d: hay registros después del de fin de fichero (88).', $no));
            }
            $code = self::field($line, 1, 2);
            switch ($code) {
                case '11':
                    if ($open !== null) {
                        throw new Norma43Exception(sprintf('Línea %d: empieza otra cuenta sin haber cerrado la anterior con su registro final (33).', $no));
                    }
                    $open = self::header($line, $no);
                    break;
                case '22':
                    self::requireOpen($open, $no, $code);
                    $open['movements'][] = self::movement($line, $no, $open);
                    $open['nextComplement'] = 1;
                    $open['hasEquivalence'] = false;
                    break;
                case '23':
                    self::requireMovement($open, $no, $code);
                    if ($open['hasEquivalence']) {
                        throw new Norma43Exception(sprintf('Línea %d: un complementario de concepto (23) tiene que ir antes del de divisa (24).', $no));
                    }
                    self::complement($line, $no, $open);
                    break;
                case '24':
                    // Equivalencia en otra divisa: no tiene valor contable, sólo se comprueba su sitio.
                    self::requireMovement($open, $no, $code);
                    if ($open['hasEquivalence']) {
                        throw new Norma43Exception(sprintf('Línea %d: el movimiento ya tenía su registro de divisa (24).', $no));
                    }
                    $open['hasEquivalence'] = true;
                    break;
                case '33':
                    self::requireOpen($open, $no, $code);
                    $accounts[] = self::close($line, $no, $open);
                    $open = null;
                    break;
                case '88':
                    if ($open !== null) {
                        throw new Norma43Exception(sprintf('Línea %d: el fichero termina con una cuenta sin su registro final (33).', $no));
                    }
                    self::end($line, $no, $records, $accounts !== []);
                    $ended = true;
                    continue 2;
                default:
                    throw new Norma43Exception(sprintf('Línea %d: el registro «%s» no existe en la Norma 43 (sólo 11, 22, 23, 24, 33 y 88). ¿Es un extracto en formato Norma 43 / cuaderno 43?', $no, $code));
            }
            ++$records;
        }

        if ($open !== null) {
            throw new Norma43Exception(sprintf('El fichero acaba sin el registro final (33) de la cuenta %s: está cortado.', self::label($open)));
        }
        if ($accounts === []) {
            throw new Norma43Exception('El fichero no trae ninguna cuenta. ¿Es un extracto en formato Norma 43 / cuaderno 43?');
        }

        return new Norma43File($accounts, $ended ? [] : ['El fichero no trae el registro de fin (88). Se ha leído igual, porque cada cuenta cuadra con su cierre; si el banco lo cortó, faltarían cuentas enteras, no movimientos.']);
    }

    /**
     * Pasa el fichero a UTF-8. Los bancos lo dan en UTF-8, en Windows-1252 o en la
     * página de códigos de MS-DOS (CP850). Si es UTF-8 válido, se queda como está. Si
     * no, CP850 sólo cuando el único byte alto es 0xA5 (la «Ñ» en CP850, que en
     * Windows-1252 sería «¥»); en cualquier otro caso, Windows-1252. Los textos sólo se
     * enseñan: una letra mal adivinada no cambia ninguna cifra.
     *
     * @param string $raw El fichero tal cual.
     */
    public static function toUtf8(string $raw): string
    {
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }
        preg_match_all('/[\x80-\xFF]/', $raw, $matches);
        $high = array_values(array_unique($matches[0]));

        return mb_convert_encoding($raw, 'UTF-8', $high === ["\xA5"] ? 'CP850' : 'Windows-1252');
    }

    /**
     * Las líneas del fichero, cada una de 80 posiciones. Admite saltos LF o CRLF, una
     * marca BOM al principio, blancos finales recortados (se vuelve a rellenar a 80) y
     * líneas vacías al final. Una línea vacía en medio o una de más de 80 se rechaza.
     *
     * @return list<string>
     *
     * @throws Norma43Exception
     */
    private static function lines(string $text): array
    {
        $rows = array_map(
            static fn (string $row): string => rtrim($row, " \r"),
            explode("\n", (string) preg_replace('/^\xEF\xBB\xBF/', '', $text)),
        );
        while ($rows !== [] && end($rows) === '') {
            array_pop($rows);
        }

        foreach ($rows as $index => $row) {
            $length = mb_strlen($row);
            if ($length === 0) {
                throw new Norma43Exception(sprintf('Línea %d: está vacía. En un Norma 43 cada línea es un registro.', $index + 1));
            }
            if ($length > self::LINE_LENGTH) {
                throw new Norma43Exception(sprintf('Línea %d: mide %d caracteres y un registro Norma 43 mide %d. ¿Es un extracto en formato Norma 43 / cuaderno 43?', $index + 1, $length, self::LINE_LENGTH));
            }
            $rows[$index] = $row.str_repeat(' ', self::LINE_LENGTH - $length);
        }

        return $rows;
    }

    /**
     * El trozo de una línea entre dos posiciones, contadas desde 1 y las dos incluidas,
     * como en el cuaderno.
     */
    private static function field(string $line, int $from, int $to): string
    {
        return mb_substr($line, $from - 1, $to - $from + 1);
    }

    /**
     * Cabecera de cuenta (11). Devuelve el estado de la cuenta en curso, que se va
     * completando con sus movimientos hasta el 33.
     *
     * @return array<string, mixed>
     *
     * @throws Norma43Exception
     */
    private static function header(string $line, int $no): array
    {
        $bank = self::digits($line, 3, 6, $no, 'la entidad');
        $branch = self::digits($line, 7, 10, $no, 'la oficina');
        $number = self::digits($line, 11, 20, $no, 'el nº de cuenta');
        $from = self::date($line, 21, 26, $no, 'fecha inicial');
        $to = self::date($line, 27, 32, $no, 'fecha final');
        if ($from > $to) {
            throw new Norma43Exception(sprintf('Línea %d: la fecha inicial (%s) es posterior a la final (%s).', $no, $from->format('d/m/Y'), $to->format('d/m/Y')));
        }
        self::euro($line, 48, 50, $no);

        return [
            'bank' => $bank,
            'branch' => $branch,
            'number' => $number,
            'from' => $from,
            'to' => $to,
            'opening' => self::signed($line, 33, 34, 47, $no, 'saldo inicial'),
            'holder' => trim(self::field($line, 52, 77)),
            'movements' => [],
            'debitCount' => 0,
            'debitCents' => 0,
            'creditCount' => 0,
            'creditCents' => 0,
            'inMovement' => false,
            'nextComplement' => 1,
            'hasEquivalence' => false,
        ];
    }

    /**
     * Movimiento (22). Suma al debe o al haber de la cuenta para comprobarlo con el 33.
     *
     * @param array<string, mixed> $open La cuenta en curso.
     *
     * @return array<string, mixed> El movimiento, aún sin sus textos.
     *
     * @throws Norma43Exception
     */
    private static function movement(string $line, int $no, array &$open): array
    {
        $operation = self::date($line, 11, 16, $no, 'fecha de operación');
        if ($operation < $open['from'] || $operation > $open['to']) {
            throw new Norma43Exception(sprintf(
                'Línea %d: el movimiento es del %s y el extracto va del %s al %s.',
                $no,
                $operation->format('d/m/Y'),
                $open['from']->format('d/m/Y'),
                $open['to']->format('d/m/Y'),
            ));
        }
        $key = self::field($line, 28, 28);
        $cents = self::amount($line, 29, 42, $no, 'importe');
        if ($key === '1') {
            ++$open['debitCount'];
            $open['debitCents'] += $cents;
        } elseif ($key === '2') {
            ++$open['creditCount'];
            $open['creditCents'] += $cents;
        } else {
            throw new Norma43Exception(sprintf('Línea %d: la clave debe/haber es «%s» y tiene que ser 1 (sale) o 2 (entra).', $no, $key));
        }
        $open['inMovement'] = true;

        return [
            'operationDate' => $operation,
            'valueDate' => self::date($line, 17, 22, $no, 'fecha valor'),
            'commonConcept' => self::field($line, 23, 24),
            'ownConcept' => self::field($line, 25, 27),
            'amountCents' => $key === '1' ? -$cents : $cents,
            'document' => trim(self::field($line, 43, 52)),
            'reference1' => trim(self::field($line, 53, 64)),
            'reference2' => trim(self::field($line, 65, 80)),
            'texts' => [],
        ];
    }

    /**
     * Complementario de concepto (23): hasta cinco por movimiento, numerados 01, 02…
     * seguidos. Su texto se guarda entero (posiciones 5 a 80): en la modalidad tercera
     * los campos no van partidos en dos de 38, y sólo sirve para enseñarlo.
     *
     * @param array<string, mixed> $open La cuenta en curso.
     *
     * @throws Norma43Exception
     */
    private static function complement(string $line, int $no, array &$open): void
    {
        $expected = $open['nextComplement'];
        if ($expected > self::MAX_COMPLEMENTS) {
            throw new Norma43Exception(sprintf('Línea %d: un movimiento lleva como mucho %d complementarios de concepto (23).', $no, self::MAX_COMPLEMENTS));
        }
        $code = self::field($line, 3, 4);
        if ($code !== sprintf('%02d', $expected)) {
            throw new Norma43Exception(sprintf('Línea %d: el complementario de concepto es el «%s» y tocaba el %02d.', $no, $code, $expected));
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', self::field($line, 5, 80)));
        if ($text !== '') {
            $open['movements'][array_key_last($open['movements'])]['texts'][] = $text;
        }
        $open['nextComplement'] = $expected + 1;
    }

    /**
     * Final de cuenta (33): misma cuenta que su 11, y el nº de apuntes, las sumas y el
     * saldo final tienen que salir de los movimientos leídos.
     *
     * @param array<string, mixed> $open La cuenta en curso.
     *
     * @throws Norma43Exception
     */
    private static function close(string $line, int $no, array $open): Norma43Account
    {
        $same = self::field($line, 3, 6) === $open['bank']
            && self::field($line, 7, 10) === $open['branch']
            && self::field($line, 11, 20) === $open['number'];
        if (!$same) {
            throw new Norma43Exception(sprintf('Línea %d: el registro final (33) es de otra cuenta que su cabecera (%s).', $no, self::label($open)));
        }
        $checks = [
            ['apuntes que salen', self::count($line, 21, 25, $no), $open['debitCount'], false],
            ['total de lo que sale', self::amount($line, 26, 39, $no, 'total debe'), $open['debitCents'], true],
            ['apuntes que entran', self::count($line, 40, 44, $no), $open['creditCount'], false],
            ['total de lo que entra', self::amount($line, 45, 58, $no, 'total haber'), $open['creditCents'], true],
        ];
        foreach ($checks as [$what, $declared, $read, $money]) {
            if ($declared !== $read) {
                throw new Norma43Exception(sprintf(
                    'Línea %d: el cierre de la cuenta %s dice %s de %s y los movimientos suman %s. El fichero está incompleto o dañado.',
                    $no,
                    self::label($open),
                    $money ? self::euros($declared) : $declared,
                    $what,
                    $money ? self::euros($read) : $read,
                ));
            }
        }
        $closing = self::signed($line, 59, 60, 73, $no, 'saldo final');
        $expected = $open['opening'] - $open['debitCents'] + $open['creditCents'];
        if ($closing !== $expected) {
            throw new Norma43Exception(sprintf(
                'Línea %d: el saldo final de la cuenta %s es %s, pero el inicial (%s) con sus movimientos da %s.',
                $no,
                self::label($open),
                self::euros($closing),
                self::euros($open['opening']),
                self::euros($expected),
            ));
        }
        self::euro($line, 74, 76, $no);

        return new Norma43Account(
            $open['bank'],
            $open['branch'],
            $open['number'],
            $open['from'],
            $open['to'],
            $open['opening'],
            $closing,
            $open['holder'],
            array_map(static fn (array $m): Norma43Movement => new Norma43Movement(
                $m['operationDate'],
                $m['valueDate'],
                $m['commonConcept'],
                $m['ownConcept'],
                $m['amountCents'],
                $m['document'],
                $m['reference1'],
                $m['reference2'],
                $m['texts'],
            ), $open['movements']),
        );
    }

    /**
     * Fin de fichero (88): dieciocho nueves y el nº de registros anteriores.
     *
     * @throws Norma43Exception
     */
    private static function end(string $line, int $no, int $records, bool $hasAccounts): void
    {
        if (!$hasAccounts) {
            throw new Norma43Exception('El fichero no trae ninguna cuenta. ¿Es un extracto en formato Norma 43 / cuaderno 43?');
        }
        if (self::field($line, 3, 20) !== str_repeat('9', 18)) {
            throw new Norma43Exception(sprintf('Línea %d: el registro de fin (88) tiene que llevar dieciocho nueves.', $no));
        }
        $declared = self::count($line, 21, 26, $no);
        if ($declared !== $records) {
            throw new Norma43Exception(sprintf('Línea %d: el registro de fin dice que hay %d registros y hay %d. El fichero está incompleto o dañado.', $no, $declared, $records));
        }
    }

    /**
     * @param array<string, mixed>|null $open
     *
     * @throws Norma43Exception
     */
    private static function requireOpen(?array $open, int $no, string $code): void
    {
        if ($open === null) {
            throw new Norma43Exception(sprintf('Línea %d: un registro %s tiene que ir dentro de una cuenta, después de su cabecera (11).', $no, $code));
        }
    }

    /**
     * @param array<string, mixed>|null $open
     *
     * @throws Norma43Exception
     */
    private static function requireMovement(?array $open, int $no, string $code): void
    {
        if ($open === null || !$open['inMovement']) {
            throw new Norma43Exception(sprintf('Línea %d: un registro %s tiene que ir detrás de un movimiento (22).', $no, $code));
        }
    }

    /** @throws Norma43Exception */
    private static function digits(string $line, int $from, int $to, int $no, string $what): string
    {
        $value = self::field($line, $from, $to);
        if (!ctype_digit($value)) {
            throw new Norma43Exception(sprintf('Línea %d: %s (posiciones %d a %d) tiene que ser numérico y es «%s».', $no, $what, $from, $to, $value));
        }

        return $value;
    }

    /** Un importe sin signo de 14 cifras, las dos últimas decimales, en céntimos. */
    private static function amount(string $line, int $from, int $to, int $no, string $what): int
    {
        return (int) self::digits($line, $from, $to, $no, sprintf('el %s', $what));
    }

    /** Un contador de apuntes o registros. */
    private static function count(string $line, int $from, int $to, int $no): int
    {
        return (int) self::digits($line, $from, $to, $no, 'el contador');
    }

    /**
     * Un saldo con su clave: 1 deudor (negativo), 2 acreedor (positivo).
     *
     * @throws Norma43Exception
     */
    private static function signed(string $line, int $signAt, int $from, int $to, int $no, string $what): int
    {
        $sign = self::field($line, $signAt, $signAt);
        if ($sign !== '1' && $sign !== '2') {
            throw new Norma43Exception(sprintf('Línea %d: la clave del %s es «%s» y tiene que ser 1 (deudor) o 2 (acreedor).', $no, $what, $sign));
        }
        $cents = self::amount($line, $from, $to, $no, $what);

        return $sign === '1' ? -$cents : $cents;
    }

    /**
     * Una fecha AAMMDD (del año 2000 en adelante) que exista en el calendario.
     *
     * @throws Norma43Exception
     */
    private static function date(string $line, int $from, int $to, int $no, string $what): \DateTimeImmutable
    {
        $raw = self::field($line, $from, $to);
        [$y, $m, $d] = ctype_digit($raw) ? [2000 + (int) substr($raw, 0, 2), (int) substr($raw, 2, 2), (int) substr($raw, 4, 2)] : [0, 0, 0];
        if (!checkdate($m, $d, $y)) {
            throw new Norma43Exception(sprintf('Línea %d: la %s «%s» no es una fecha (AAMMDD).', $no, $what, $raw));
        }

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $d));
    }

    /** @throws Norma43Exception */
    private static function euro(string $line, int $from, int $to, int $no): void
    {
        $currency = self::field($line, $from, $to);
        if ($currency !== self::EURO) {
            throw new Norma43Exception(sprintf('Línea %d: la cuenta está en la divisa «%s» y el libro sólo lleva euros (978).', $no, $currency));
        }
    }

    /**
     * La cuenta en curso, para los mensajes.
     *
     * @param array<string, mixed> $open
     */
    private static function label(array $open): string
    {
        return sprintf('%s %s ····%s', $open['bank'], $open['branch'], substr($open['number'], -4));
    }

    /** Céntimos con signo, como se leen: «-1.234,50 €». */
    private static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.').' €';
    }
}
