<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Adónde vuelve una acción que también se puede hacer desde la portada de contabilidad.
 *
 * El formulario de la portada manda `_back=agenda`; cualquier otro valor (o ninguno) es
 * la pantalla de siempre. Sólo se acepta ese valor, nunca una URL: una vuelta a una
 * dirección que llega en la petición es una redirección abierta.
 */
final class BackToAgenda
{
    /** Valor de `_back` con el que la portada pide volver a ella. */
    public const VALUE = 'agenda';

    /** Ruta de la portada de contabilidad. */
    public const ROUTE = 'accounting_index';

    /**
     * La ruta a la que volver.
     *
     * @param Request $request La petición de la acción.
     * @param string  $default La ruta de siempre de esa acción.
     */
    public static function route(Request $request, string $default): string
    {
        return self::requested($request) ? self::ROUTE : $default;
    }

    /** Si la acción viene de la portada y tiene que volver a ella. */
    public static function requested(Request $request): bool
    {
        return $request->request->get('_back') === self::VALUE;
    }
}
