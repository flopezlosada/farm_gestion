<?php

namespace App\Service\Staff;

/**
 * Construye el calendario ANUAL del trabajador (12 mini-meses) para "Mis
 * vacaciones": cada día queda marcado como fin de semana, festivo, ausencia
 * (aprobada o pendiente) y/o hoy. Lógica pura y testeable, al estilo de
 * {@see MonthGridBuilder}: recibe los conjuntos ya resueltos (festivos y días de
 * ausencia por estado) y solo arma la cuadrícula; no toca la base de datos.
 */
class YearCalendarBuilder
{
    /**
     * @param int                    $year         Año.
     * @param \DateTimeZone          $tz           Zona del calendario (Madrid).
     * @param \DateTimeImmutable     $today        Hoy (para marcar el día actual).
     * @param array<string, string>  $holidayDates Nombre del festivo por día 'Y-m-d'.
     * @param array<string, string>  $absenceDays  Estado de la ausencia por día 'Y-m-d' (Absence::STATUS_*).
     * @return array<int, array{month: int, first: \DateTimeImmutable, weeks: array<int, array<int, array>>}>
     */
    public function build(
        int $year,
        \DateTimeZone $tz,
        \DateTimeImmutable $today,
        array $holidayDates,
        array $absenceDays,
    ): array {
        $todayKey = $today->format('Y-m-d');
        $months = [];

        for ($month = 1; $month <= 12; ++$month) {
            $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
            $monthEnd = $monthStart->modify('first day of next month');
            $lastDay = $monthEnd->modify('-1 day');

            // Rejilla de lunes a domingo que cubre el mes (N: 1=lunes … 7=domingo).
            $gridStart = $monthStart->modify(sprintf('-%d days', (int) $monthStart->format('N') - 1));
            $gridEnd = $lastDay->modify(sprintf('+%d days', 7 - (int) $lastDay->format('N')));

            $weeks = [];
            $week = [];
            for ($cursor = $gridStart; $cursor <= $gridEnd; $cursor = $cursor->modify('+1 day')) {
                $inMonth = $cursor >= $monthStart && $cursor < $monthEnd;
                $key = $cursor->format('Y-m-d');

                $week[] = [
                    'date' => $cursor,
                    'inMonth' => $inMonth,
                    'day' => (int) $cursor->format('j'),
                    'weekend' => (int) $cursor->format('N') >= 6,
                    'holiday' => $inMonth ? ($holidayDates[$key] ?? null) : null,
                    'absence' => $inMonth ? ($absenceDays[$key] ?? null) : null,
                    'isToday' => $inMonth && $key === $todayKey,
                ];

                if (count($week) === 7) {
                    $weeks[] = $week;
                    $week = [];
                }
            }

            $months[] = ['month' => $month, 'first' => $monthStart, 'weeks' => $weeks];
        }

        return $months;
    }
}
