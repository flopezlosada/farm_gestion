<?php

namespace App\Repository;

use App\Entity\Worker;
use App\Entity\WorkSchedule;
use App\Service\Staff\WorkingWeek;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method WorkSchedule|null find($id, $lockMode = null, $lockVersion = null)
 * @method WorkSchedule|null findOneBy(array $criteria, array $orderBy = null)
 * @method WorkSchedule[]    findAll()
 * @method WorkSchedule[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WorkScheduleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkSchedule::class);
    }

    /**
     * Horario aplicable a un trabajador un día de la semana: su propia excepción
     * si la tiene, o si no la plantilla global. Es la que usa
     * {@see \App\Service\Staff\ScheduleAutofill} para saber qué rellenar.
     *
     * @param Worker $worker    Trabajador.
     * @param int    $dayOfWeek Día ISO-8601 (1 lunes .. 7 domingo).
     * @return WorkSchedule|null
     */
    public function forWorkerAndDay(Worker $worker, int $dayOfWeek): ?WorkSchedule
    {
        return $this->findOneForSlot($worker, $dayOfWeek) ?? $this->findOneForSlot(null, $dayOfWeek);
    }

    /**
     * Días de la semana en los que le toca trabajar, según su horario aplicable
     * (ver {@see WorkingWeek::fromSchedules()}). Una sola consulta para los 7
     * días, no una por día.
     *
     * Es el horario de HOY, no el que tenía en el mes que se mira: no se guarda
     * historial. Cambiar el horario recalcula los huecos de meses pasados,
     * también en el PDF de un mes ya aprobado.
     *
     * @param Worker $worker Trabajador.
     * @return WorkingWeek
     */
    public function workingWeekFor(Worker $worker): WorkingWeek
    {
        return WorkingWeek::fromSchedules($this->createQueryBuilder('s')
            ->andWhere('s.worker = :worker OR s.worker IS NULL')
            ->setParameter('worker', $worker)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult());
    }

    /**
     * Fila exacta de un slot (worker, día), sin caer a la global. Es la que usa
     * el guardado del admin para hacer find-or-create sin duplicar filas.
     *
     * @param Worker|null $worker    Trabajador, o null para la plantilla global.
     * @param int         $dayOfWeek Día ISO-8601 (1 lunes .. 7 domingo).
     * @return WorkSchedule|null
     */
    public function findOneForSlot(?Worker $worker, int $dayOfWeek): ?WorkSchedule
    {
        return $this->findOneBy(['worker' => $worker, 'dayOfWeek' => $dayOfWeek]);
    }

    /**
     * Las 7 filas de la semana de un horario, indexadas por día ISO-8601 (1-7,
     * con huecos a null en los días sin fila). Es lo que pinta la pantalla de
     * configuración (global si $worker es null, excepción del trabajador si no).
     *
     * @param Worker|null $worker Trabajador, o null para la plantilla global.
     * @return array<int, WorkSchedule|null>
     */
    public function findWeekFor(?Worker $worker): array
    {
        $week = array_fill(WorkSchedule::MONDAY, WorkSchedule::SUNDAY - WorkSchedule::MONDAY + 1, null);
        foreach ($this->findBy(['worker' => $worker]) as $schedule) {
            $week[$schedule->getDayOfWeek()] = $schedule;
        }

        return $week;
    }
}
