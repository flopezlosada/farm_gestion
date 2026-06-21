<?php

namespace App\Service\Staff;

use App\Entity\TimeEntry;

/**
 * Reconstruye las jornadas trabajadas a partir de fichajes sueltos. Es el cálculo
 * central del registro: emparejar entradas con salidas en tramos y sumar el
 * tiempo. No persiste nada — las horas se derivan al vuelo de los eventos, nunca
 * se guardan (un total materializado se desincronizaría al corregir un fichaje).
 *
 * Lógica pura y sin estado: la comparten la ficha del trabajador, el panel de
 * huecos y el PDF del justificante.
 *
 * Limitación asumida (YAGNI): empareja dentro de cada día natural; un turno que
 * cruzara la medianoche quedaría como tramo abierto. El horario de la asociación
 * es diurno y flexible, así que no se contempla el turno nocturno.
 */
class WorkdayBuilder
{
    /**
     * Agrupa los fichajes por día natural (en la zona dada) y empareja cada día
     * en tramos. Devuelve una lista de días, de más reciente a más antiguo.
     *
     * Cada día es un array:
     *   - date          \DateTimeImmutable (medianoche del día, en $tz)
     *   - segments      lista de tramos (ver {@see self::pairDay()})
     *   - totalMinutes  suma de los tramos cerrados
     *   - open          bool: hay un tramo sin cerrar (jornada en curso)
     *   - anomaly       bool: hay algún fichaje descuadrado (IN sin OUT intermedio, etc.)
     *
     * @param TimeEntry[]       $entries Fichajes vigentes, en cualquier orden.
     * @param \DateTimeZone     $tz      Zona con la que se decide el día y se pinta la hora.
     * @return array<int, array{date: \DateTimeImmutable, segments: array, totalMinutes: int, open: bool, anomaly: bool}>
     */
    public function buildDays(array $entries, \DateTimeZone $tz): array
    {
        // Ordena por instante real (UTC) para emparejar en secuencia.
        usort($entries, static fn (TimeEntry $a, TimeEntry $b) => $a->getOccurredAt() <=> $b->getOccurredAt());

        $byDay = [];
        foreach ($entries as $entry) {
            $local = $entry->getOccurredAt()->setTimezone($tz);
            $byDay[$local->format('Y-m-d')][] = $entry;
        }

        $days = [];
        foreach ($byDay as $key => $dayEntries) {
            $segments = $this->pairDay($dayEntries);
            $days[] = [
                'date' => new \DateTimeImmutable($key . ' 00:00:00', $tz),
                'segments' => $segments,
                'totalMinutes' => array_sum(array_map(static fn (array $s) => $s['minutes'] ?? 0, $segments)),
                'open' => array_filter($segments, static fn (array $s) => $s['out'] === null && $s['in'] !== null) !== [],
                'anomaly' => array_filter($segments, static fn (array $s) => $s['in'] === null) !== [],
            ];
        }

        usort($days, static fn (array $a, array $b) => $b['date'] <=> $a['date']);

        return $days;
    }

    /**
     * Empareja los fichajes de un día (ordenados) en tramos entrada→salida. Cada
     * tramo es ['in' => TimeEntry|null, 'out' => TimeEntry|null, 'minutes' => int|null]:
     *   - cerrado:  in y out presentes, minutes = duración.
     *   - abierto:  in presente, out null (jornada en curso), minutes null.
     *   - anómalo:  in null y out presente (salida sin entrada previa), minutes null.
     * Dos entradas seguidas cierran la primera como tramo abierto (anomalía).
     *
     * @param TimeEntry[] $dayEntries Fichajes del día, ordenados por hora.
     * @return array<int, array{in: TimeEntry|null, out: TimeEntry|null, minutes: int|null}>
     */
    private function pairDay(array $dayEntries): array
    {
        $segments = [];
        $openIn = null;

        foreach ($dayEntries as $entry) {
            if ($entry->isIn()) {
                if ($openIn !== null) {
                    // Dos entradas sin salida intermedia: la anterior queda abierta.
                    $segments[] = ['in' => $openIn, 'out' => null, 'minutes' => null];
                }
                $openIn = $entry;
                continue;
            }

            // Salida.
            if ($openIn === null) {
                $segments[] = ['in' => null, 'out' => $entry, 'minutes' => null];
                continue;
            }

            $minutes = (int) round(($entry->getOccurredAt()->getTimestamp() - $openIn->getOccurredAt()->getTimestamp()) / 60);
            $segments[] = ['in' => $openIn, 'out' => $entry, 'minutes' => $minutes];
            $openIn = null;
        }

        if ($openIn !== null) {
            $segments[] = ['in' => $openIn, 'out' => null, 'minutes' => null];
        }

        return $segments;
    }
}
