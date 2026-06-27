<?php

namespace App\Service\Staff;

/**
 * Cuenta días LABORABLES (lunes a viernes) de un rango, excluyendo los festivos.
 * Es lo que vale para el saldo de vacaciones: un fin de semana o un festivo dentro
 * de un periodo de vacaciones NO consume días (el convenio cuenta laborables, no
 * naturales). Lógica pura y testeable: recibe los festivos ya resueltos.
 */
class WorkingDayCounter
{
    /**
     * Días laborables en [start, end] (ambos incluidos), sin festivos, recortado
     * opcionalmente a [clipFrom, clipTo] (p. ej. al año natural para el saldo).
     *
     * @param \DateTimeImmutable      $start        Inicio del rango.
     * @param \DateTimeImmutable      $end          Fin del rango (incluido).
     * @param array<string, mixed>    $holidayDates Festivos por fecha 'Y-m-d' (se excluyen).
     * @param \DateTimeImmutable|null $clipFrom     Recorte inferior, o null.
     * @param \DateTimeImmutable|null $clipTo       Recorte superior, o null.
     * @return int Número de días laborables.
     */
    public function count(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        array $holidayDates,
        ?\DateTimeImmutable $clipFrom = null,
        ?\DateTimeImmutable $clipTo = null,
    ): int {
        $from = ($clipFrom !== null && $clipFrom > $start) ? $clipFrom : $start;
        $to = ($clipTo !== null && $clipTo < $end) ? $clipTo : $end;
        $from = $from->setTime(0, 0, 0);
        $to = $to->setTime(0, 0, 0);

        $count = 0;
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            if ((int) $day->format('N') <= 5 && !isset($holidayDates[$day->format('Y-m-d')])) {
                ++$count;
            }
        }

        return $count;
    }
}
