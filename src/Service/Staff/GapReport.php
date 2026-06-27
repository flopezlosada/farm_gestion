<?php

namespace App\Service\Staff;

use App\Entity\TimeEntry;
use App\Entity\Worker;
use App\Repository\AbsenceRepository;
use App\Repository\HolidayRepository;
use App\Repository\TimeEntryRepository;
use App\Repository\WorkerRepository;

/**
 * Reúne, para los trabajadores activos, los huecos del registro de jornada y los
 * tramos abiertos rancios. Centraliza la preparación (fichajes + ausencias +
 * festivos) que comparten el panel de huecos del supervisor y los avisos por
 * email, para no duplicarla ni desincronizar sus criterios.
 *
 * Apoya el cálculo en {@see GapFinder} (lógica pura ya testeada); aquí solo se
 * añaden las consultas a BBDD.
 */
class GapReport
{
    public function __construct(
        private readonly WorkerRepository $workers,
        private readonly TimeEntryRepository $timeEntries,
        private readonly AbsenceRepository $absences,
        private readonly GapFinder $gapFinder,
        private readonly HolidayRepository $holidays,
    ) {
    }

    /**
     * Huecos por trabajador activo en la ventana [from, today). Solo incluye a los
     * trabajadores que tienen al menos un hueco.
     *
     * @param \DateTimeImmutable $from  Inicio de la ventana (incluido).
     * @param \DateTimeImmutable $today Hoy (excluido).
     * @return array<int, array{worker: Worker, gaps: \DateTimeImmutable[]}>
     */
    public function activeWorkersGaps(\DateTimeImmutable $from, \DateTimeImmutable $today): array
    {
        // Los festivos son los mismos para todos: una sola consulta fuera del bucle.
        $holidayDates = $this->holidays->findNamesBetween($from, $today);

        $rows = [];
        foreach ($this->workers->findActive() as $worker) {
            $worked = [];
            foreach ($this->timeEntries->findEffectiveForWorkerBetween($worker, $from, $today) as $entry) {
                $worked[$entry->getOccurredAt()->format('Y-m-d')] = true;
            }

            $covered = [];
            foreach ($this->absences->findApprovedForWorkerBetween($worker, $from, $today) as $absence) {
                $cursor = $absence->getStartDate();
                while ($cursor <= $absence->getEndDate()) {
                    $covered[$cursor->format('Y-m-d')] = true;
                    $cursor = $cursor->modify('+1 day');
                }
            }

            $gaps = $this->gapFinder->gapsFor($from, $today, $worker->getHireDate(), $worked, $covered, $holidayDates);
            if ($gaps !== []) {
                $rows[] = ['worker' => $worker, 'gaps' => $gaps];
            }
        }

        return $rows;
    }

    /**
     * Trabajadores activos con un tramo de jornada ABIERTO de un día anterior a
     * hoy: una entrada sin salida que ya no se cerrará sola (olvido de fichar la
     * salida). Es la "salida abierta" del aviso en caliente; el tramo abierto del
     * propio día en curso NO cuenta (la persona puede seguir trabajando).
     *
     * @param \DateTimeImmutable $today Hoy (a medianoche, hora de Madrid).
     * @return array<int, array{worker: Worker, since: \DateTimeImmutable}>
     */
    public function staleOpenShifts(\DateTimeImmutable $today): array
    {
        $rows = [];
        foreach ($this->workers->findActive() as $worker) {
            $last = $this->timeEntries->findLastEffectiveForWorker($worker);
            if ($last === null || !$last->isIn()) {
                continue;
            }

            $since = $last->getOccurredAt();
            // Comparación por día (medianoche): cuenta como salida abierta si la
            // entrada es de un día ANTERIOR a hoy; la del propio día no.
            if ($since->setTime(0, 0) < $today) {
                $rows[] = ['worker' => $worker, 'since' => $since];
            }
        }

        return $rows;
    }
}
