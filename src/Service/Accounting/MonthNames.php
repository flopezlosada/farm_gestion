<?php

namespace App\Service\Accounting;

/**
 * Los meses en castellano, escritos una sola vez.
 *
 * El módulo los pinta en cabeceras de tabla, títulos y rejillas de doce columnas. El
 * formateador de Intl daría lo mismo, pero obligaría a cada plantilla a construir una
 * fecha para sacar un rótulo; aquí son lo que son: doce etiquetas fijas.
 */
final class MonthNames
{
    /** Nombre completo, para títulos y cabeceras anchas. */
    public const LONG = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    /** Abreviatura de tres letras, para las rejillas de doce columnas. */
    public const SHORT = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
    ];

    /** El nombre del mes, o cadena vacía si el número no es un mes. */
    public static function long(int $month): string
    {
        return self::LONG[$month] ?? '';
    }
}
