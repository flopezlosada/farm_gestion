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
 * partida) o el régimen de la Seguridad Social. Sólo sueltos, entre espacios aunque
 * lleven signos pegados («111,», «(303)»): dentro de una referencia o de una fecha
 * («002-26», «12/10») no son nada fijo. Pegados a letras («mod.111») tampoco cuentan:
 * partir también por el punto haría contar los miles de un importe («1.234,56»).
 *
 * No se limita a los modelos de Hacienda: un número de tres cifras que cambie cada mes
 * sólo separa el historial, y lo que no tiene historial se queda para una persona; el
 * error va hacia el lado seguro.
 */
final class ConceptKey
{
    /**
     * La clave de un concepto; vacía si no tiene ninguna letra ni número de tres cifras
     * suelto.
     */
    public static function of(string $concept): string
    {
        $plain = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $concept);
        $words = preg_split('/\s+/', $plain === false ? mb_strtolower($concept) : $plain, -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_map(
            static fn (string $word): string => preg_match('/^[^a-z0-9]*(\d{3})[^a-z0-9]*$/', $word, $number) === 1
                ? $number[1]
                : (preg_replace('/[^a-z]+/', '', $word) ?? ''),
            $words,
        ));
    }
}
