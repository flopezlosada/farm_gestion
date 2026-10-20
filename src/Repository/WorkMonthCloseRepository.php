<?php

namespace App\Repository;

use App\Entity\Worker;
use App\Entity\WorkMonthClose;
use App\Service\Staff\WorkMonth;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkMonthClose>
 */
class WorkMonthCloseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkMonthClose::class);
    }

    /**
     * La aprobación vigente (no reabierta) del mes de un trabajador, o null si el
     * mes está abierto. Como reabrir marca la fila y aprobar crea otra, a lo sumo
     * hay una vigente por trabajador y mes.
     *
     * @param Worker    $worker Trabajador.
     * @param WorkMonth $month  Mes.
     * @return WorkMonthClose|null
     */
    public function findStanding(Worker $worker, WorkMonth $month): ?WorkMonthClose
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.worker = :worker')->setParameter('worker', $worker)
            ->andWhere('c.year = :year')->setParameter('year', $month->year)
            ->andWhere('c.month = :month')->setParameter('month', $month->month)
            ->andWhere('c.reopenedAt IS NULL')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Las aprobaciones vigentes firmadas desde una fecha, de la más antigua a la
     * más reciente. Es la cola del envío de resúmenes: la ventana evita que, al
     * encender la tarea por primera vez, salgan de golpe los resúmenes de meses
     * que ya se entregaron por otra vía.
     *
     * @param \DateTimeImmutable $since Desde cuándo (por fecha de aprobación).
     * @return WorkMonthClose[]
     */
    public function findStandingApprovedSince(\DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.reopenedAt IS NULL')
            ->andWhere('c.approvedAt >= :since')->setParameter('since', $since)
            ->orderBy('c.approvedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
