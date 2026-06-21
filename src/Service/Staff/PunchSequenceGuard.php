<?php

namespace App\Service\Staff;

use App\Entity\TimeEntry;

/**
 * Guarda la coherencia del registro al añadir o corregir un fichaje: dentro de un
 * día, las entradas y salidas deben alternar (entrada, salida, entrada…) y el día
 * no puede empezar por una salida. Sin esto, "Añadir fichaje" deja apilar tres
 * salidas seguidas y descuadra la jornada.
 *
 * La regla es LOCAL (mira los dos vecinos por hora), no global: así permite
 * intercalar un fichaje olvidado a media mañana sin tener que rehacer el día, y
 * no atrapa al usuario cuando repara un día ya descuadrado (anular sigue libre).
 *
 * Lógica pura: recibe los fichajes vigentes del día (sin el que se corrige) y el
 * fichaje propuesto, y devuelve el motivo del rechazo o null si encaja.
 */
class PunchSequenceGuard
{
    /**
     * Comprueba si un fichaje de tipo $type a la hora $at encaja en la secuencia
     * del día. Devuelve null si es válido, o un motivo legible si rompe el orden.
     *
     * @param string             $type       TimeEntry::TYPE_IN o TYPE_OUT.
     * @param \DateTimeImmutable $at          Hora propuesta del fichaje (Madrid).
     * @param TimeEntry[]        $dayEntries Fichajes vigentes del MISMO día (sin el que se corrige).
     * @return string|null Motivo del rechazo, o null si encaja.
     */
    public function check(string $type, \DateTimeImmutable $at, array $dayEntries): ?string
    {
        // Vecino anterior (mayor hora <= at) y siguiente (menor hora > at). El <=
        // hace que un fichaje a la misma hora cuente como anterior, lo que rechaza
        // duplicados del mismo tipo a la misma hora.
        $prev = null;
        $next = null;
        foreach ($dayEntries as $entry) {
            $t = $entry->getOccurredAt();
            if ($t <= $at) {
                if ($prev === null || $t > $prev->getOccurredAt()) {
                    $prev = $entry;
                }
            } elseif ($next === null || $t < $next->getOccurredAt()) {
                $next = $entry;
            }
        }

        if ($type === TimeEntry::TYPE_IN) {
            if ($prev !== null && $prev->isIn()) {
                return 'Antes de esa hora ya hay una entrada sin su salida.';
            }
            if ($next !== null && $next->isIn()) {
                return 'El siguiente fichaje del día ya es una entrada; faltaría la salida intermedia.';
            }

            return null;
        }

        // Salida: necesita una entrada abierta justo antes y no puede ir seguida de otra salida.
        if ($prev === null || !$prev->isIn()) {
            return 'Una salida tiene que ir después de una entrada.';
        }
        if ($next !== null && $next->isOut()) {
            return 'El siguiente fichaje del día ya es una salida; rompería la alternancia.';
        }

        return null;
    }
}
