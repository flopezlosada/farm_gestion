<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\InputBag;

/**
 * Una fecha opcional de la URL en formato `Y-m-d`: `?start=2026-11-09`.
 *
 * Igual que {@see QueryId}: vacío, ausente o basura significan «sin fecha», no un
 * 400. Una fecha imposible (`2026-02-31`) también: `createFromFormat` la
 * desbordaría al mes siguiente sin avisar, y aquí se descarta.
 */
final class QueryDate
{
    /**
     * Devuelve la fecha del parámetro, o null si no hay una válida.
     *
     * @param InputBag<string> $query La query de la petición (`$request->query`).
     * @param string           $key   Nombre del parámetro.
     */
    public static function from(InputBag $query, string $key): ?\DateTimeImmutable
    {
        $value = $query->all()[$key] ?? null;
        if (!\is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
