<?php

namespace App\Service\Accounting;

/**
 * El concepto de un movimiento del banco reducido a lo que no cambia de un mes a otro:
 * sólo letras, en minúscula y sin tildes. Así «rcbo.caymuz sl» y «rcbo.caymuz, s.l.»
 * son el mismo, y un número de recibo o de referencia que cambia cada mes no los separa.
 */
final class ConceptKey
{
    /** La clave de un concepto; vacía si no tiene ninguna letra. */
    public static function of(string $concept): string
    {
        $plain = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $concept);

        return preg_replace('/[^a-z]+/', '', $plain === false ? mb_strtolower($concept) : $plain) ?? '';
    }
}
