<?php

namespace App\Service\Staff;

use App\Service\Calendar\MonthGrid;

/**
 * Construye la rejilla de un calendario mensual (semanas de lunes a domingo que
 * cubren el mes completo), marcando cada día con su total de horas, su ausencia
 * o su condición de hueco. Lógica pura y compartida por el calendario del
 * supervisor y el del trabajador: recibe los datos ya resueltos (totales por día
 * y días cubiertos por ausencia) y solo arma la cuadrícula.
 */
class MonthGridBuilder
{
    /**
     * @param int                    $year     Año.
     * @param int                    $month    Mes (1-12).
     * @param \DateTimeZone          $tz       Zona del calendario (Madrid).
     * @param array<string, array>   $totals   Jornadas por día, clave 'Y-m-d' (de WorkdayBuilder).
     * @param array<string, string>  $covered  Tipo de ausencia por día cubierto, clave 'Y-m-d'.
     * @param \DateTimeImmutable     $today    Hoy (para marcar hoy y no contar futuro/hueco).
     * @param \DateTimeImmutable|null $hireDate Alta del trabajador (no hay hueco antes).
     * @param array<string, string>  $holidays Nombre del festivo por día, clave 'Y-m-d'.
     * @return array<int, array<int, array>> Semanas, cada una con 7 celdas.
     */
    public function build(
        int $year,
        int $month,
        \DateTimeZone $tz,
        array $totals,
        array $covered,
        \DateTimeImmutable $today,
        ?\DateTimeImmutable $hireDate,
        array $holidays = [],
    ): array {
        $hireDay = $hireDate?->setTime(0, 0);

        // Las semanas las da la rejilla común; aquí sólo se decora cada día.
        return array_map(fn (array $days): array => array_map(function (\DateTimeImmutable $cursor) use ($month, $totals, $covered, $today, $hireDay, $holidays): array {
            $key = $cursor->format('Y-m-d');
            $inMonth = MonthGrid::inMonth($cursor, $month);
            $weekday = (int) $cursor->format('N');
            $hasEntries = isset($totals[$key]);
            $isPast = $cursor < $today;
            $afterHire = $hireDay === null || $cursor >= $hireDay;

            $holiday = $holidays[$key] ?? null;

            return [
                'date' => $cursor,
                'inMonth' => $inMonth,
                'day' => $cursor->format('j'),
                'total' => $hasEntries ? $totals[$key] : null,
                'absence' => $covered[$key] ?? null,
                'holiday' => $holiday,
                'isGap' => $inMonth && $weekday <= 5 && $isPast && $afterHire && !$hasEntries && !isset($covered[$key]) && $holiday === null,
                'isToday' => $key === $today->format('Y-m-d'),
                'isFuture' => $cursor > $today,
            ];
        }, $days), MonthGrid::weeks($year, $month, $tz));
    }
}
