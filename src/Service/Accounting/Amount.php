<?php

namespace App\Service\Accounting;

/**
 * Un importe tecleado o leído de un Excel, a decimal con punto y dos cifras.
 *
 * La coma es siempre decimal. El punto es de miles sólo si agrupa de tres en tres
 * («1.200», «-12.500»); si no, es decimal («43.50», «12.5»). Así un «12.50» tecleado
 * con el punto del teclado numérico vale 12,50 y no mil doscientos cincuenta.
 */
final class Amount
{
    /**
     * «43,5», «43.50», 43.5, «1.200,00», «1.200» o «-200» → «43.50», «1200.00»…
     * Null si no es un importe; la cadena vacía tampoco lo es.
     */
    public static function parse(mixed $value): ?string
    {
        if (\is_int($value) || \is_float($value)) {
            return number_format((float) $value, 2, '.', '');
        }
        $text = str_replace(['€', ' ', "\u{a0}"], '', trim((string) $value));
        if (str_contains($text, ',') || preg_match('/^-?\d{1,3}(\.\d{3})+$/', $text) === 1) {
            $text = str_replace(['.', ','], ['', '.'], $text);
        }

        return is_numeric($text) ? number_format((float) $text, 2, '.', '') : null;
    }
}
