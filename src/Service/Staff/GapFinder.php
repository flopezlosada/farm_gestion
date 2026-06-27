<?php

namespace App\Service\Staff;

/**
 * Encuentra los HUECOS del registro de jornada: días laborables en los que un
 * trabajador no fichó nada y que no están cubiertos por una ausencia justificada.
 * Es lo que el supervisor mira para perseguir los olvidos ANTES de que lo haga la
 * Inspección.
 *
 * Un hueco es un día que cumple todo esto:
 *   - es laborable (lunes a viernes),
 *   - cae en el rango pedido y a partir del alta del trabajador,
 *   - es anterior a hoy (el día en curso no es un olvido),
 *   - no tiene ningún fichaje,
 *   - no está cubierto por una ausencia aprobada (vacaciones, baja, permiso),
 *   - no es festivo del calendario laboral.
 *
 * Lógica pura: recibe los conjuntos de días ya trabajados, cubiertos y festivos
 * (calculados por quien llama) y devuelve las fechas-hueco.
 */
class GapFinder
{
    /**
     * Días-hueco en [from, today) a partir del alta, descontando trabajados y
     * cubiertos.
     *
     * @param \DateTimeImmutable     $from         Inicio de la ventana (incluido).
     * @param \DateTimeImmutable     $today        Hoy (excluido: no se evalúa el día en curso).
     * @param \DateTimeImmutable     $hireDate     Alta del trabajador (no hay huecos antes).
     * @param array<string, bool>    $workedDates  Días con algún fichaje, clave 'Y-m-d'.
     * @param array<string, bool>    $coveredDates Días cubiertos por ausencia, clave 'Y-m-d'.
     * @param array<string, mixed>   $holidayDates Días festivos, clave 'Y-m-d'.
     * @return \DateTimeImmutable[] Fechas-hueco, de más antigua a más reciente.
     */
    public function gapsFor(
        \DateTimeImmutable $from,
        \DateTimeImmutable $today,
        \DateTimeImmutable $hireDate,
        array $workedDates,
        array $coveredDates,
        array $holidayDates = [],
    ): array {
        // El barrido empieza en el más tardío entre la ventana y el alta.
        $cursor = $from > $hireDate ? $from : $hireDate;
        $cursor = $cursor->setTime(0, 0, 0);
        $end = $today->setTime(0, 0, 0);

        $gaps = [];
        while ($cursor < $end) {
            $weekday = (int) $cursor->format('N'); // 1 (lunes) … 7 (domingo)
            $key = $cursor->format('Y-m-d');

            if ($weekday <= 5
                && !isset($workedDates[$key])
                && !isset($coveredDates[$key])
                && !isset($holidayDates[$key])
            ) {
                $gaps[] = $cursor;
            }

            $cursor = $cursor->modify('+1 day');
        }

        return $gaps;
    }
}
