<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\InputBag;

/**
 * El id de un filtro opcional de la URL: `?category=12`.
 *
 * Los formularios GET de filtros mandan la opción «Todas» como `category=` (cadena
 * vacía), y `InputBag::getInt()` no la convierte en 0: lanza BadRequestException y
 * la pantalla responde 400. Aquí vacío, ausente, cero o basura significan lo mismo:
 * «sin filtro».
 */
final class QueryId
{
    /**
     * Devuelve el id positivo del parámetro, o null si no hay filtro.
     *
     * @param InputBag<string> $query La query de la petición (`$request->query`).
     * @param string           $key   Nombre del parámetro.
     */
    public static function from(InputBag $query, string $key): ?int
    {
        $value = $query->all()[$key] ?? null;
        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }
        $id = filter_var($value, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }
}
