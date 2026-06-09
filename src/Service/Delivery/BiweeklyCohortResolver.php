<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Repository\DeliveryExceptionRepository;

/**
 * Determina qué cohorte (A o B) de quincenales recoge cesta en un Basket dado.
 *
 * Alternancia semanal desde una fecha ancla, OPERATIVO-AWARE: cuenta semanas
 * desde el ancla pero descuenta los cierres globales (festivos, puentes) que
 * cancelan el reparto, de modo que cada cierre desplaza la alternancia una
 * semana. Es la operativa real: si el viernes que tocaba al grupo A se cierra,
 * el grupo A recoge en el siguiente viernes operativo y B en el de después.
 * Pasa ~2 veces al año (Navidad, agosto, puentes), no es un borde.
 *
 * Ancla operativa: 2026-05-08 (primer viernes operativo de mayo 2026) = A.
 * Sin cierres entre medias, el viernes 29-may queda 3 semanas después del ancla
 * (= impar) → B, lo que coincide con el patrón "0,1,0,1" del CSV de mayo. Un
 * cierre global cancelado entre el ancla y un viernes dado invierte la cohorte
 * de ese viernes en adelante.
 *
 * "Cierre" = {@see \App\Entity\DeliveryException} global (sin nodo) con
 * `shiftedDate` null. Un traslado (`shiftedDate` con fecha) NO desplaza la
 * alternancia: sigue habiendo reparto, sólo cambia el día físico.
 *
 * Para casos especiales (patrón "0,1,1,1": el socio recibe tres veces al mes
 * en lugar de dos), el comando de importación deja delivery_group=NULL.
 * Esos socios no encajan en cohorte y se quedan fuera del listado; deben
 * tratarse manualmente.
 *
 * Deuda relacionada: la cadencia biweekly de NODO ({@see NodeDeliveryDate})
 * sigue usando semanas crudas frente a su anchor_date y NO descuenta cierres.
 */
class BiweeklyCohortResolver
{
    /** Fecha ancla y cohorte conocida en esa fecha. */
    private const ANCHOR_DATE = '2026-05-08';
    private const ANCHOR_COHORT = 'A';

    /** Cohorte alternativa devuelta cuando el índice operativo es impar respecto al ancla. */
    private const OPPOSITE_COHORT = 'B';

    private const SECONDS_PER_WEEK = 7 * 24 * 60 * 60;

    public function __construct(
        private readonly DeliveryExceptionRepository $exceptionRepository,
    ) {
    }

    /**
     * Cohorte que recoge en el ciclo dado, descontando los cierres globales
     * intermedios para que las cancelaciones desplacen la alternancia.
     *
     * @param Basket $basket Ciclo semanal global.
     * @return string 'A' o 'B'.
     */
    public function cohortForBasket(Basket $basket): string
    {
        $anchor = new \DateTimeImmutable(self::ANCHOR_DATE);
        $target = \DateTimeImmutable::createFromInterface($basket->getDate());

        $diffSeconds = $target->getTimestamp() - $anchor->getTimestamp();
        $rawWeeks = abs((int) round($diffSeconds / self::SECONDS_PER_WEEK));

        // Cada cierre global cancelado entre el ancla y el ciclo objetivo
        // (extremos excluidos) consume una posición de la alternancia.
        [$after, $before] = $target >= $anchor ? [$anchor, $target] : [$target, $anchor];
        $closures = $this->exceptionRepository->countGlobalCancellationsBetween($after, $before);

        // Normaliza el módulo: $closures nunca debería superar $rawWeeks, pero
        // si lo hiciera el operador % de PHP daría negativo.
        $parity = ((($rawWeeks - $closures) % 2) + 2) % 2;

        return $parity === 0 ? self::ANCHOR_COHORT : self::OPPOSITE_COHORT;
    }
}
