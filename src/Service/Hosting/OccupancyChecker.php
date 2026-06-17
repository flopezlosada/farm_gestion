<?php

namespace App\Service\Hosting;

use App\Entity\Stay;

/**
 * Aritmética de ocupación del albergue: cuántas camas hay ocupadas cada día y
 * si una estancia nueva cabe sin superar el aforo. Es la misma clase de problema
 * que los intervalos del reparto, resuelta como FUNCIÓN PURA: sin estado, sin
 * dependencias, sin BBDD. Quien la usa le pasa las estancias y una función que
 * da el aforo de cada día; así se testea entera con datos en memoria.
 *
 * Convención de intervalo medio-abierto [llegada, salida), como un hotel: la
 * cama se ocupa el día de llegada y se libera el día de salida.
 */
final class OccupancyChecker
{
    /**
     * ¿La estancia ocupa una cama el día $day? Sólo cuentan las confirmadas, y
     * sólo entre la llegada (incluida) y la salida (excluida).
     *
     * @param Stay               $stay
     * @param \DateTimeImmutable $day
     * @return bool
     */
    public function occupies(Stay $stay, \DateTimeImmutable $day): bool
    {
        if (!$stay->occupiesCapacity()) {
            return false;
        }

        $d = $this->atMidnight($day);

        return $this->atMidnight($stay->getArrivalDate()) <= $d
            && $d < $this->atMidnight($stay->getDepartureDate());
    }

    /**
     * ¿Se solapan dos estancias (comparten al menos un día de cama)? No mira el
     * estado: es geometría pura de intervalos. A y B se tocan si
     * A.llegada < B.salida && A.salida > B.llegada (medio intervalo abierto, así
     * que A que termina el mismo día que empieza B NO solapa).
     *
     * @param Stay $a
     * @param Stay $b
     * @return bool
     */
    public function overlap(Stay $a, Stay $b): bool
    {
        return $this->atMidnight($a->getArrivalDate()) < $this->atMidnight($b->getDepartureDate())
            && $this->atMidnight($a->getDepartureDate()) > $this->atMidnight($b->getArrivalDate());
    }

    /**
     * Nº de camas ocupadas el día $day entre la lista de estancias dada.
     *
     * @param \DateTimeImmutable $day
     * @param Stay[]             $stays
     * @return int
     */
    public function occupancyOn(\DateTimeImmutable $day, array $stays): int
    {
        return count(array_filter(
            $stays,
            fn (Stay $stay) => $this->occupies($stay, $day),
        ));
    }

    /**
     * Días del rango de $candidate en los que meterlo superaría el aforo, dadas
     * las demás estancias $existing y una función que devuelve el aforo de cada
     * día. Lista vacía = la estancia cabe.
     *
     * $existing NO debe incluir a $candidate (al editar, excluirla por id antes
     * de llamar, p. ej. con {@see \App\Repository\StayRepository::findConfirmedOverlapping}).
     * Un mes cerrado se modela devolviendo aforo 0: cualquier día de ese mes
     * dará conflicto.
     *
     * @param Stay                                  $candidate      Estancia a comprobar.
     * @param Stay[]                                $existing       Estancias confirmadas ya presentes.
     * @param callable(\DateTimeImmutable): int     $capacityForDay Aforo efectivo de cada día.
     * @return \DateTimeImmutable[] Días en conflicto, en orden (vacío si cabe).
     */
    public function violationsFor(Stay $candidate, array $existing, callable $capacityForDay): array
    {
        $violations = [];
        $cursor = $this->atMidnight($candidate->getArrivalDate());
        $end = $this->atMidnight($candidate->getDepartureDate());

        while ($cursor < $end) {
            $capacity = $capacityForDay($cursor);
            $occupied = $this->occupancyOn($cursor, $existing);
            if ($occupied + 1 > $capacity) {
                $violations[] = $cursor;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $violations;
    }

    /**
     * ¿Cabe la estancia $candidate sin superar el aforo ningún día?
     *
     * @param Stay                              $candidate
     * @param Stay[]                            $existing
     * @param callable(\DateTimeImmutable): int $capacityForDay
     * @return bool
     */
    public function fits(Stay $candidate, array $existing, callable $capacityForDay): bool
    {
        return $this->violationsFor($candidate, $existing, $capacityForDay) === [];
    }

    /**
     * Normaliza una fecha a medianoche para comparar por DÍA, no por instante.
     * Las fechas de la estancia ya vienen sin hora (date_immutable), pero el
     * cursor y el día consultado pueden traer hora; esto los iguala.
     *
     * @param \DateTimeImmutable $date
     * @return \DateTimeImmutable
     */
    private function atMidnight(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->setTime(0, 0, 0);
    }
}
