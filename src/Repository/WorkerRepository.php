<?php

namespace App\Repository;

use App\Entity\TimeEntry;
use App\Entity\Worker;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Worker|null find($id, $lockMode = null, $lockVersion = null)
 * @method Worker|null findOneBy(array $criteria, array $orderBy = null)
 * @method Worker[]    findAll()
 * @method Worker[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WorkerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Worker::class);
    }

    /**
     * Trabajadores activos (los que pueden fichar), ordenados por nombre. Es la
     * lista que ve el supervisor en el panel de jornada.
     *
     * @return Worker[]
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.active = true')
            ->orderBy('w.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Quién entra en un registro de jornada de un periodo: los activos dados de
     * alta antes de que acabe, y los ya inactivos que fichasen dentro. Un
     * inactivo sin fichajes en el periodo no se incluye: no hay fecha de baja con
     * la que saber si aún trabajaba, y pintaría todo el periodo como huecos.
     *
     * @param \DateTimeImmutable $from Inicio del periodo, incluido.
     * @param \DateTimeImmutable $to   Fin del periodo, excluido.
     * @return Worker[] Ordenados por nombre.
     */
    public function findForRecordBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere(sprintf(
                '(w.active = true AND w.hireDate < :to) OR EXISTS (SELECT t.id FROM %s t WHERE t.worker = w AND t.occurredAt >= :from AND t.occurredAt < :to)',
                TimeEntry::class,
            ))
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('w.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
