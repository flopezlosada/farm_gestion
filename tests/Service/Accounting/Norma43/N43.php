<?php

namespace App\Tests\Service\Accounting\Norma43;

/**
 * Fabrica extractos Norma 43 sintéticos para los tests: cada método devuelve un
 * registro de 80 posiciones como lo describe el Anexo 1 del cuaderno 43. Cifras y
 * nombres inventados.
 */
final class N43
{
    /** Registro 11: cabecera de cuenta. Importe en céntimos con signo. */
    public static function header(string $bank, string $branch, string $number, string $from, string $to, int $openingCents, string $currency = '978', string $holder = 'ASOCIACION DE PRUEBA'): string
    {
        return '11'.$bank.$branch.$number.$from.$to.self::sign($openingCents).self::amount($openingCents).$currency.'3'.self::pad($holder, 26).'   ';
    }

    /** Registro 22: movimiento. Importe en céntimos con signo (negativo = debe). */
    public static function movement(string $operation, int $cents, string $document = '0000000000', string $reference1 = '000000000000', string $reference2 = '', string $common = '99', ?string $value = null): string
    {
        return '22    0001'.$operation.($value ?? $operation).$common.'000'.self::sign($cents).self::amount($cents).$document.$reference1.self::pad($reference2, 16);
    }

    /** Registro 23: complementario de concepto. */
    public static function complement(int $code, string $text, string $more = ''): string
    {
        return '23'.sprintf('%02d', $code).self::pad($text, 38).self::pad($more, 38);
    }

    /** Registro 24: equivalencia en otra divisa (sin valor contable). */
    public static function equivalence(): string
    {
        return '2401840'.self::amount(12345).str_repeat(' ', 59);
    }

    /** Registro 33: final de cuenta. */
    public static function close(string $bank, string $branch, string $number, int $debitCount, int $debitCents, int $creditCount, int $creditCents, int $closingCents, string $currency = '978'): string
    {
        return '33'.$bank.$branch.$number.sprintf('%05d', $debitCount).self::amount($debitCents).sprintf('%05d', $creditCount).self::amount($creditCents).self::sign($closingCents).self::amount($closingCents).$currency.'    ';
    }

    /** Registro 88: fin de fichero. */
    public static function end(int $records): string
    {
        return '88'.str_repeat('9', 18).sprintf('%06d', $records).str_repeat(' ', 54);
    }

    /**
     * Una cuenta entera, con el 33 calculado de sus movimientos.
     *
     * Los movimientos llevan el mismo nº de documento salvo que se diga otro, como
     * los bancos que no lo informan: así dos idénticos del mismo día sólo se distinguen
     * por su orden.
     *
     * @param list<array{0: string, 1: int, 2?: list<string>, 3?: string}> $movements Fecha AAMMDD, céntimos con signo, textos de sus 23 y nº de documento.
     *
     * @return list<string>
     */
    public static function account(string $bank, string $branch, string $number, string $from, string $to, int $openingCents, array $movements): array
    {
        $lines = [self::header($bank, $branch, $number, $from, $to, $openingCents)];
        $debit = [0, 0];
        $credit = [0, 0];
        foreach ($movements as $m) {
            $lines[] = self::movement($m[0], $m[1], $m[3] ?? '0000000000');
            foreach ($m[2] ?? [] as $n => $text) {
                $lines[] = self::complement($n + 1, $text);
            }
            if ($m[1] < 0) {
                $debit = [$debit[0] + 1, $debit[1] - $m[1]];
            } else {
                $credit = [$credit[0] + 1, $credit[1] + $m[1]];
            }
        }
        $lines[] = self::close($bank, $branch, $number, $debit[0], $debit[1], $credit[0], $credit[1], $openingCents - $debit[1] + $credit[1]);

        return $lines;
    }

    /**
     * El fichero: las líneas, el 88 al final y saltos LF.
     *
     * @param list<string> $lines
     */
    public static function file(array $lines, bool $withEnd = true, string $eol = "\n"): string
    {
        return implode($eol, $withEnd ? [...$lines, self::end(\count($lines))] : $lines).$eol;
    }

    private static function sign(int $cents): string
    {
        return $cents < 0 ? '1' : '2';
    }

    private static function amount(int $cents): string
    {
        return sprintf('%014d', abs($cents));
    }

    private static function pad(string $text, int $length): string
    {
        return $text.str_repeat(' ', max(0, $length - mb_strlen($text)));
    }
}
