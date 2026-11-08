<?php

namespace App\Service\Accounting;

/**
 * El concepto de un movimiento del banco reducido a lo que no cambia de un mes a otro:
 * las letras, en minúscula y sin tildes, y los números sueltos de exactamente tres
 * cifras. Así «rcbo.caymuz sl» y «rcbo.caymuz, s.l.» son el mismo, y un número de
 * recibo o de referencia que cambia cada mes no los separa.
 *
 * Los de tres cifras sí cuentan porque nombran algo fijo: el modelo de Hacienda
 * («adeudo 111 domiciliado aeat» es IRPF y «adeudo 303…» el IVA, cada uno con su
 * partida) o el régimen de la Seguridad Social. Sólo sueltos, entre espacios: dentro
 * de una referencia o de una fecha («002-26», «12/10») no son nada fijo.
 */
final class ConceptKey
{
    /** La clave de un concepto; vacía si no tiene ninguna letra ni número de tres cifras suelto. */
    public static function of(string $concept): string
    {
        $plain = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $concept);
        $words = preg_split('/\s+/', $plain === false ? mb_strtolower($concept) : $plain, -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_map(
            static fn (string $word): string => preg_match('/^\d{3}$/', $word) === 1 ? $word : (preg_replace('/[^a-z]+/', '', $word) ?? ''),
            $words,
        ));
    }
}
