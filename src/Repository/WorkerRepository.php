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
    /** Sigue trabajando hoy: sin baja, o con la baja hoy o más adelante (parámetro `today`). */
    private const ACTIVE_TODAY = '(w.leaveDate IS NULL OR w.leaveDate >= :today)';

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
            ->andWhere(self::ACTIVE_TODAY)
            ->setParameter('today', self::today())
            ->orderBy('w.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Quién entra en un registro de jornada de un periodo: los activos hoy dados
     * de alta antes de que acabe, y los ya de baja que fichasen dentro. Uno de baja
     * sin fichajes en el periodo no se incluye: los huecos aún no se cortan en la
     * fecha de baja, y pintaría todo el periodo como sin fichar.
     *
     * @param \DateTimeImmutable $from Inicio del periodo, incluido.
     * @param \DateTimeImmutable $to   Fin del periodo, excluido.
     * @return Worker[] Ordenados por nombre.
     */
    public function findForRecordBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere(sprintf(
                '(' . self::ACTIVE_TODAY . ' AND w.hireDate < :to) OR EXISTS (SELECT t.id FROM %s t WHERE t.worker = w AND t.occurredAt >= :from AND t.occurredAt < :to)',
                TimeEntry::class,
            ))
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('today', self::today())
            ->orderBy('w.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Hoy, como fecha: el corte de quién sigue trabajando, igual que {@see Worker::isActive()}.
     *
     * @return string
     */
    private static function today(): string
    {
        return Worker::today()->format('Y-m-d');
    }
}
