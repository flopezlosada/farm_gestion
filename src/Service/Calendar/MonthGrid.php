<?php

namespace App\Service\Calendar;

/**
 * Las semanas de un mes tal y como se pintan en una rejilla: de lunes a domingo,
 * cubriendo el mes entero, con los días de relleno del mes anterior y siguiente.
 *
 * Es la parte común a TODOS los calendarios mensuales de la casa —fichajes,
 * voluntariado, lo que venga— y por eso no sabe nada de lo que va dentro de
 * cada día: cada calendario recorre estas semanas y decora las celdas con lo
 * suyo. Lógica pura, sin BBDD.
 */
final class MonthGrid
{
    /**
     * Las semanas del mes, cada una con sus siete días.
     *
     * @param int                $year  año
     * @param int                $month mes (1-12)
     * @param \DateTimeZone|null $tz    zona horaria de las fechas; null para la del sistema
     *
     * @return list<list<\DateTimeImmutable>> semanas de lunes a domingo, a medianoche
     */
    public static function weeks(int $year, int $month, ?\DateTimeZone $tz = null): array
    {
        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
        $monthEnd = $monthStart->modify('first day of next month');

        // "monday this week" en PHP devuelve el lunes SIGUIENTE cuando el día 1
        // ya es lunes de otra semana en curso; se corrige mirando si se pasó.
        $gridStart = $monthStart->modify('monday this week');
        if ($gridStart > $monthStart) {
            $gridStart = $monthStart->modify('last monday');
        }
        $gridEnd = $monthEnd->modify('-1 day')->modify('sunday this week');

        $weeks = [];
        $week = [];
        for ($cursor = $gridStart; $cursor <= $gridEnd; $cursor = $cursor->modify('+1 day')) {
            $week[] = $cursor;
            if (7 === \count($week)) {
                $weeks[] = $week;
                $week = [];
            }
        }

        return $weeks;
    }

    /**
     * El primer día del mes pedido como `AAAA-MM`, o el del mes en curso si no
     * se pide ninguno o el texto no es una fecha. Es cómo todos los calendarios
     * leen `?mes=` de la URL.
     *
     * @param string             $requested lo que venía en la URL
     * @param \DateTimeImmutable $now       ahora
     *
     * @return \DateTimeImmutable el día 1 del mes, a medianoche
     */
    public static function monthFrom(string $requested, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $month = preg_match('/^\d{4}-\d{2}$/', $requested)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $requested.'-01')
            : false;

        return $month ?: $now->modify('first day of this month')->setTime(0, 0);
    }

    /**
     * Si una fecha cae dentro del mes de la rejilla, o es relleno.
     *
     * @param \DateTimeImmutable $date  el día
     * @param int                $month mes (1-12)
     *
     * @return bool true si es del mes
     */
    public static function inMonth(\DateTimeImmutable $date, int $month): bool
    {
        return (int) $date->format('n') === $month;
    }
}
