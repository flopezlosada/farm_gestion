<?php

namespace App\Service\Staff;

use App\Entity\TimeEntry;

/**
 * Guarda la coherencia del registro al añadir o corregir un fichaje: dentro de un
 * día, las entradas y salidas deben alternar (entrada, salida, entrada…), el día
 * no puede empezar por una salida y la última entrada puede quedar abierta
 * (jornada en curso). Sin esto, "Añadir fichaje" deja apilar salidas seguidas y
 * corregir una hora puede saltar un fichaje por encima de su pareja y descuadrar
 * la jornada.
 *
 * Valida la SECUENCIA COMPLETA del día resultante (no solo los vecinos del fichaje
 * tocado): así detecta también cuando mover una hora deja huérfano a otro fichaje.
 * Permite reparar un día ya descuadrado SI la operación lo deja bien (p. ej.
 * completar un huérfano); para casos que requieren varios pasos, anular es libre.
 *
 * Lógica pura: recibe los fichajes vigentes del día (sin el que se corrige) y el
 * fichaje propuesto, y devuelve el motivo del rechazo o null si encaja.
 */
class PunchSequenceGuard
{
    /**
     * Comprueba si un fichaje de tipo $type a la hora $at deja el día como una
     * secuencia válida (entrada, salida, …). Devuelve null si es válido, o un
     * motivo legible si rompe el orden.
     *
     * @param string             $type       TimeEntry::TYPE_IN o TYPE_OUT.
     * @param \DateTimeImmutable $at          Hora propuesta del fichaje (Madrid).
     * @param TimeEntry[]        $dayEntries Fichajes vigentes del MISMO día (sin el que se corrige).
     * @return string|null Motivo del rechazo, o null si encaja.
     */
    public function check(string $type, \DateTimeImmutable $at, array $dayEntries): ?string
    {
        // Secuencia del día resultante (existentes + el nuevo), ordenada por hora.
        $sequence = [];
        foreach ($dayEntries as $entry) {
            $sequence[] = ['in' => $entry->isIn(), 'at' => $entry->getOccurredAt()];
        }
        $sequence[] = ['in' => $type === TimeEntry::TYPE_IN, 'at' => $at];
        usort($sequence, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        // Debe alternar empezando por entrada: entrada, salida, entrada, salida…
        // (la última entrada sin cerrar es válida: jornada en curso).
        $expectIn = true;
        foreach ($sequence as $item) {
            if ($item['in'] !== $expectIn) {
                return $expectIn
                    ? 'Esa hora deja una salida por delante de su entrada. La entrada tiene que ir antes que la salida.'
                    : 'Esa hora deja dos entradas seguidas sin una salida en medio. Pon la salida antes de la siguiente entrada.';
            }
            $expectIn = !$expectIn;
        }

        return null;
    }
}
