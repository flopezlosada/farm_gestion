<?php

namespace App\Service\Staff;

use App\Entity\Worker;
use App\Repository\AbsenceRepository;
use App\Repository\HolidayRepository;
use App\Repository\TimeEntryRepository;

/**
 * Reúne los datos del justificante mensual de jornada de un trabajador a partir
 * de las fuentes (fichajes, ausencias aprobadas y festivos) y los pasa por
 * {@see MonthlyTimesheetBuilder}. Centraliza la preparación que, si no, se
 * duplicaría entre el panel del supervisor y el del trabajador, los dos puntos
 * desde los que se descarga el PDF.
 */
class MonthlyTimesheetReport
{
    public function __construct(
        private readonly TimeEntryRepository $timeEntries,
        private readonly AbsenceRepository $absences,
        private readonly HolidayRepository $holidays,
        private readonly WorkdayBuilder $workdays,
        private readonly MonthlyTimesheetBuilder $builder,
    ) {
    }

    /**
     * Construye la hoja del mes para un trabajador.
     *
     * @param Worker             $worker Trabajador.
     * @param int                $year   Año.
     * @param int                $month  Mes (1-12).
     * @param \DateTimeZone      $tz     Zona horaria (Madrid).
     * @param \DateTimeImmutable $today  Día de hoy (para marcar días futuros).
     * @return array Estructura de {@see MonthlyTimesheetBuilder::build()}.
     */
    public function forMonth(Worker $worker, int $year, int $month, \DateTimeZone $tz, \DateTimeImmutable $today): array
    {
        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
        $monthEnd = $monthStart->modify('first day of next month');

        $entries = $this->timeEntries->findEffectiveForWorkerBetween($worker, $monthStart, $monthEnd);
        $workedDays = [];
        foreach ($this->workdays->buildDays($entries, $tz) as $day) {
            $workedDays[$day['date']->format('Y-m-d')] = $day;
        }

        $covered = [];
        foreach ($this->absences->findApprovedForWorkerBetween($worker, $monthStart, $monthEnd) as $absence) {
            $cursor = $absence->getStartDate();
            while ($cursor <= $absence->getEndDate()) {
                $covered[$cursor->format('Y-m-d')] = $absence->getType();
                $cursor = $cursor->modify('+1 day');
            }
        }

        $holidayNames = $this->holidays->findNamesBetween($monthStart, $monthEnd->modify('-1 day'));

        return $this->builder->build($year, $month, $tz, $workedDays, $covered, $holidayNames, $worker->getHireDate(), $today);
    }
}
