<?php

namespace App\Service\Staff;

/**
 * Construye la cabecera de días de un mes para el calendario del EQUIPO (una fila
 * por trabajador): cada día con su número, si es fin de semana, si es festivo y si
 * es hoy. Lógica pura y testeable; la expansión de las ausencias por trabajador la
 * hace quien llama (necesita la base de datos).
 */
class TeamMonthBuilder
{
    /**
     * Días del mes, en orden.
     *
     * @param int                    $year         Año.
     * @param int                    $month        Mes (1-12).
     * @param \DateTimeZone          $tz           Zona (Madrid).
     * @param \DateTimeImmutable     $today        Hoy.
     * @param array<string, string>  $holidayDates Festivos por 'Y-m-d'.
     * @return array<int, array{ymd: string, day: int, weekend: bool, holiday: ?string, today: bool}>
     */
    public function monthDays(int $year, int $month, \DateTimeZone $tz, \DateTimeImmutable $today, array $holidayDates): array
    {
        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
        $monthEnd = $monthStart->modify('first day of next month');
        $todayKey = $today->format('Y-m-d');

        $days = [];
        for ($cursor = $monthStart; $cursor < $monthEnd; $cursor = $cursor->modify('+1 day')) {
            $key = $cursor->format('Y-m-d');
            $days[] = [
                'ymd' => $key,
                'day' => (int) $cursor->format('j'),
                'weekend' => (int) $cursor->format('N') >= 6,
                'holiday' => $holidayDates[$key] ?? null,
                'today' => $key === $todayKey,
            ];
        }

        return $days;
    }
}
