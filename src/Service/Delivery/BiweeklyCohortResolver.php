<?php

namespace App\Service\Delivery;

use App\Entity\Basket;

/**
 * Determina qué cohorte (A o B) de quincenales recoge cesta en un Basket dado.
 *
 * Modelo simple para MVP: alternancia semanal desde una fecha ancla.
 * Ancla operativa: 2026-05-08 (primer viernes operativo de mayo 2026) = A.
 * Cada semana ISO alterna; el viernes 29-may queda 3 semanas después del ancla
 * (= impar) → B, lo que coincide con el patrón "0,1,0,1" del CSV de mayo.
 *
 * No tiene en cuenta cancelaciones puntuales (festivos como 1-may): asume que
 * cuando un viernes no hay reparto, la alternancia NO se desplaza — los
 * socios afectados simplemente saltan ese reparto. Si en el futuro la
 * dirección operativa quiere que las cancelaciones SÍ desplacen la
 * alternancia, habría que indexar baskets operativos vs no-operativos
 * (deuda apuntada en memoria ab-cohort-design).
 *
 * Para casos especiales (patrón "0,1,1,1": el socio recibe tres veces al mes
 * en lugar de dos), el comando de importación deja delivery_group=NULL.
 * Esos socios no encajan en cohorte y se quedan fuera del listado; deben
 * tratarse manualmente.
 */
class BiweeklyCohortResolver
{
    /** Fecha ancla y cohorte conocida en esa fecha. */
    private const ANCHOR_DATE = '2026-05-08';
    private const ANCHOR_COHORT = 'A';

    /** Cohorte alternativa devuelta cuando el basket cae en semana impar respecto al ancla. */
    private const OPPOSITE_COHORT = 'B';

    /**
     * @return string 'A' o 'B'.
     */
    public function cohortForBasket(Basket $basket): string
    {
        $anchor = new \DateTimeImmutable(self::ANCHOR_DATE);
        $secondsPerWeek = 7 * 24 * 60 * 60;
        $diffSeconds = $basket->getDate()->getTimestamp() - $anchor->getTimestamp();
        $weeks = (int) round($diffSeconds / $secondsPerWeek);

        return abs($weeks) % 2 === 0 ? self::ANCHOR_COHORT : self::OPPOSITE_COHORT;
    }
}
