<?php

namespace App\Service\Accounting\Invoice;

/**
 * Si un NIF, NIE o CIF español cuadra con su carácter de control.
 *
 * No dice que exista ni de quién es, sólo que está bien escrito: una cifra cambiada
 * (errata del proveedor o mala lectura) casi siempre descuadra el control. Se
 * calcula sin IA ni servicios de fuera, con el algoritmo público de la AEAT.
 */
final class SpanishTaxId
{
    /** Letra de control de un NIF o NIE: la del resto de dividir el número entre 23. */
    private const NIF_LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    /** Letra de control de un CIF, por la cifra de control que le corresponde. */
    private const CIF_LETTERS = 'JABCDEFGHI';

    /** Primeras letras de un NIE y la cifra por la que se cambian para calcular. */
    private const NIE_PREFIXES = ['X' => '0', 'Y' => '1', 'Z' => '2'];

    /**
     * Si el identificador, ya normalizado (mayúsculas, sin espacios ni guiones), cuadra.
     *
     * @param string $taxId Identificador normalizado.
     */
    public static function isValid(string $taxId): bool
    {
        return match (true) {
            preg_match('/^\d{8}[A-Z]$/', $taxId) === 1 => self::nifLetter(substr($taxId, 0, 8)) === $taxId[8],
            preg_match('/^[XYZ]\d{7}[A-Z]$/', $taxId) === 1 => self::nifLetter(self::NIE_PREFIXES[$taxId[0]] . substr($taxId, 1, 7)) === $taxId[8],
            // NIF especiales de personas físicas (K, L, M): el control es el del NIF
            // sobre las siete cifras.
            preg_match('/^[KLM]\d{7}[A-Z]$/', $taxId) === 1 => self::nifLetter(substr($taxId, 1, 7)) === $taxId[8],
            preg_match('/^[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J]$/', $taxId) === 1 => self::cifControlMatches($taxId),
            default => false,
        };
    }

    /**
     * La letra que le toca a un número de NIF.
     *
     * @param string $number Sólo cifras.
     */
    private static function nifLetter(string $number): string
    {
        return self::NIF_LETTERS[(int) $number % 23];
    }

    /**
     * El control de un CIF: las cifras pares se suman tal cual y las impares se
     * doblan sumando sus dígitos. Según la letra inicial, el control es una cifra
     * (sociedades anónimas y limitadas…), una letra (entidades públicas, extranjeras…)
     * o vale cualquiera de las dos (asociaciones, cooperativas…).
     *
     * @param string $cif CIF con su forma ya comprobada.
     */
    private static function cifControlMatches(string $cif): bool
    {
        $sum = 0;
        foreach (str_split(substr($cif, 1, 7)) as $position => $digit) {
            $sum += $position % 2 === 0 ? array_sum(str_split((string) ((int) $digit * 2))) : (int) $digit;
        }
        $digit = (10 - $sum % 10) % 10;
        $byDigit = (string) $digit;
        $byLetter = self::CIF_LETTERS[$digit];
        $control = $cif[8];

        return match (true) {
            str_contains('ABEH', $cif[0]) => $control === $byDigit,
            str_contains('NPQRSW', $cif[0]) => $control === $byLetter,
            default => $control === $byDigit || $control === $byLetter,
        };
    }
}
