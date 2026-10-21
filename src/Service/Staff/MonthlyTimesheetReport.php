<?php

namespace App\Service\Staff;

use App\Entity\Worker;
use App\Repository\AbsenceRepository;
use App\Repository\HolidayRepository;
use App\Repository\TimeEntryRepository;
use App\Repository\WorkMonthCloseRepository;
use App\Repository\WorkScheduleRepository;

/**
 * Reúne los datos del resumen mensual de jornada de un trabajador a partir
 * de las fuentes (fichajes, ausencias aprobadas, festivos y su horario) y los pasa por
 * {@see MonthlyTimesheetBuilder}. Centraliza la preparación que, si no, se
 * duplicaría entre el panel del supervisor y el del trabajador, los dos puntos
 * desde los que se descarga el PDF, la pantalla de cierre del mes y el envío del
 * resumen a quien trabaja a tiempo parcial.
 */
class MonthlyTimesheetReport
{
    public function __construct(
        private readonly TimeEntryRepository $timeEntries,
        private readonly AbsenceRepository $absences,
        private readonly HolidayRepository $holidays,
        private readonly WorkdayBuilder $workdays,
        private readonly MonthlyTimesheetBuilder $builder,
        private readonly WorkMonthCloseRepository $closes,
        private readonly WorkScheduleRepository $schedules,
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
     * @return array Estructura de {@see MonthlyTimesheetBuilder::build()}, más
     *               `close`: la aprobación vigente del mes ({@see \App\Entity\WorkMonthClose}) o null.
     */
    public function forMonth(Worker $worker, int $year, int $month, \DateTimeZone $tz, \DateTimeImmutable $today): array
    {
        $only = WorkMonth::of($year, $month);

        return $this->forRange($worker, WorkPeriod::single($only), $tz, $today)[$only->key()];
    }

    /**
     * Construye las hojas de varios meses seguidos de un trabajador. Las fuentes
     * se cargan una sola vez para todo el rango y luego se reparten por mes: el
     * export para la Inspección pide años enteros de todo el equipo, y mes a mes
     * serían cinco consultas por cada trabajador y mes.
     *
     * @param Worker             $worker Trabajador.
     * @param WorkPeriod         $period Los meses.
     * @param \DateTimeZone      $tz     Zona horaria (Madrid).
     * @param \DateTimeImmutable $today  Día de hoy (para marcar días futuros).
     * @return array<string, array> Hoja de cada mes (ver {@see self::forMonth()}) por clave 'YYYY-MM', en orden.
     */
    public function forRange(Worker $worker, WorkPeriod $period, \DateTimeZone $tz, \DateTimeImmutable $today): array
    {
        $rangeStart = $period->start();
        $rangeEnd = $period->end();

        $workedDays = [];
        $entries = $this->timeEntries->findEffectiveForWorkerBetween($worker, $rangeStart, $rangeEnd);
        foreach ($this->workdays->buildDays($entries, $tz) as $day) {
            $workedDays[$day['date']->format('Y-m-d')] = $day;
        }

        $covered = $this->absences->coveredDaysForWorkerBetween($worker, $rangeStart, $rangeEnd);
        $holidayNames = $this->holidays->findNamesBetween($rangeStart, $rangeEnd->modify('-1 day'));
        $workingWeek = $this->schedules->workingWeekFor($worker);
        $closes = $this->closes->findStandingForWorkerBetween($worker, $period);

        $sheets = [];
        foreach ($period->months() as $month) {
            $sheet = $this->builder->build($month->year, $month->month, $tz, $workedDays, $covered, $holidayNames, $workingWeek, $worker->getHireDate(), $today);
            $sheet['close'] = $closes[$month->key()] ?? null;
            $sheets[$month->key()] = $sheet;
        }

        return $sheets;
    }
}
