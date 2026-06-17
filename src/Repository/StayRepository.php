<?php

namespace App\Repository;

use App\Entity\Stay;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Stay|null find($id, $lockMode = null, $lockVersion = null)
 * @method Stay|null findOneBy(array $criteria, array $orderBy = null)
 * @method Stay[]    findAll()
 * @method Stay[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class StayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Stay::class);
    }

    /**
     * Estancias CONFIRMADAS que solapan con el rango [$from, $to) — es decir,
     * las que ocupan al menos un día de ese rango. Convención de medio intervalo
     * abierto, coherente con {@see \App\Service\Hosting\OccupancyChecker}: dos
     * estancias se tocan si A.arrival < B.departure && A.departure > B.arrival.
     *
     * Sirve para alimentar al OccupancyChecker con las estancias relevantes sin
     * traer toda la tabla. Sólo confirmadas: las solicitadas/canceladas no
     * comprometen aforo.
     *
     * @param \DateTimeImmutable $from        Inicio del rango (incluido).
     * @param \DateTimeImmutable $to          Fin del rango (excluido).
     * @param int|null           $excludeId   Estancia a excluir (la que se está
     *                                         editando, para no contarse a sí misma).
     * @return Stay[]
     */
    public function findConfirmedOverlapping(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?int $excludeId = null,
    ): array {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.status = :status')
            ->andWhere('s.arrivalDate < :to')
            ->andWhere('s.departureDate > :from')
            ->setParameter('status', Stay::STATUS_CONFIRMED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('s.arrivalDate', 'ASC');

        if ($excludeId !== null) {
            $qb->andWhere('s.id != :excludeId')
               ->setParameter('excludeId', $excludeId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Estancias de los estados dados que solapan con el rango [$from, $to), con
     * su voluntario traído en el mismo SELECT (el timeline pinta una fila por
     * persona, así que sin esto sería N+1). Por defecto confirmadas y
     * solicitadas; las canceladas no se muestran en el calendario.
     *
     * @param \DateTimeImmutable $from     Inicio del rango (incluido).
     * @param \DateTimeImmutable $to       Fin del rango (excluido).
     * @param string[]           $statuses Estados a incluir.
     * @return Stay[] Ordenadas por voluntario y llegada.
     */
    public function findOverlapping(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $statuses = [Stay::STATUS_CONFIRMED, Stay::STATUS_REQUESTED],
    ): array {
        return $this->createQueryBuilder('s')
            ->addSelect('h')
            ->join('s.helper', 'h')
            ->andWhere('s.status IN (:statuses)')
            ->andWhere('s.arrivalDate < :to')
            ->andWhere('s.departureDate > :from')
            ->setParameter('statuses', $statuses)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('h.name', 'ASC')
            ->addOrderBy('s.arrivalDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
