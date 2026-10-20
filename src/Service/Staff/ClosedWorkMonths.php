<?php

namespace App\Service\Staff;

use App\Entity\Worker;
use App\Repository\WorkMonthCloseRepository;

/**
 * El candado de los meses aprobados. Todo lo que cambia el registro de jornada
 * de un trabajador —fichajes, correcciones, anulaciones, ausencias aprobadas—
 * pregunta aquí antes, para que la regla viva en un sitio y no en cada pantalla.
 */
class ClosedWorkMonths
{
    public function __construct(private readonly WorkMonthCloseRepository $closes)
    {
    }

    /**
     * ¿Está aprobado el mes al que pertenece ese día?
     *
     * @param Worker             $worker Trabajador.
     * @param \DateTimeInterface $day    Cualquier instante del mes.
     * @return bool
     */
    public function isClosed(Worker $worker, \DateTimeInterface $day): bool
    {
        return $this->closes->findStanding($worker, WorkMonth::containing($day)) !== null;
    }

    /**
     * Falla si el mes de ese día está aprobado.
     *
     * @param Worker             $worker Trabajador.
     * @param \DateTimeInterface $day    Cualquier instante del mes.
     * @return void
     *
     * @throws ClosedWorkMonthException
     */
    public function assertOpen(Worker $worker, \DateTimeInterface $day): void
    {
        $month = WorkMonth::containing($day);
        if ($this->closes->findStanding($worker, $month) !== null) {
            throw new ClosedWorkMonthException($month);
        }
    }

    /**
     * Falla si algún mes que toca el rango [desde, hasta] (fechas incluidas) está
     * aprobado. Es el caso de una ausencia: unas vacaciones del 28 al 3 tocan dos
     * meses, y basta con que uno esté cerrado.
     *
     * @param Worker             $worker Trabajador.
     * @param \DateTimeInterface $from   Primer día.
     * @param \DateTimeInterface $to     Último día (incluido).
     * @return void
     *
     * @throws ClosedWorkMonthException
     */
    public function assertRangeOpen(Worker $worker, \DateTimeInterface $from, \DateTimeInterface $to): void
    {
        $month = WorkMonth::containing($from);
        $last = WorkMonth::containing($to);

        while (true) {
            if ($this->closes->findStanding($worker, $month) !== null) {
                throw new ClosedWorkMonthException($month);
            }
            if ($month->equals($last) || $month->start() > $last->start()) {
                return;
            }
            $month = $month->next();
        }
    }
}
