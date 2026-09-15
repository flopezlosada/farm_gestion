<?php

namespace App\Repository;

use App\Entity\ConsumerGroupRound;
use App\Entity\Producer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Rondas de pedido del grupo de consumo.
 *
 * @extends ServiceEntityRepository<ConsumerGroupRound>
 */
class ConsumerGroupRoundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsumerGroupRound::class);
    }

    /**
     * Todas las rondas, más recientes por fecha de entrega arriba. Para el listado
     * de gestión de la comisión.
     *
     * @return ConsumerGroupRound[]
     */
    public function findAllForManagement(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.deliveryDate', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Igual que {@see findAllForManagement()}, pero con los filtros del listado
     * de gestión: productor, estado y rango de fecha de entrega.
     *
     * @param array{producer: ?int, status: ?int, from: ?string, to: ?string} $filters
     *
     * @return ConsumerGroupRound[]
     */
    public function findFilteredForManagement(array $filters): array
    {
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.deliveryDate', 'DESC')
            ->addOrderBy('r.id', 'DESC');

        if (!empty($filters['producer'])) {
            $qb->andWhere('r.producer = :producer')->setParameter('producer', $filters['producer']);
        }

        if (null !== ($filters['status'] ?? null)) {
            $qb->andWhere('r.status = :status')->setParameter('status', $filters['status']);
        }

        if (!empty($filters['from'])) {
            $qb->andWhere('r.deliveryDate >= :from')->setParameter('from', new \DateTime($filters['from']));
        }

        if (!empty($filters['to'])) {
            $qb->andWhere('r.deliveryDate <= :to')->setParameter('to', new \DateTime($filters['to']));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Los productores que tienen al menos un pedido, para el desplegable del
     * filtro (no todo el catálogo de productores: uno sin pedidos nunca
     * filtraría nada).
     *
     * @return Producer[]
     */
    public function findProducersWithRounds(): array
    {
        return $this->createQueryBuilder('r')
            ->select('DISTINCT p')
            ->join('r.producer', 'p')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Rondas actualmente abiertas a apuntes (OPEN), con la de cierre más próximo
     * primero. Para el panel del socio.
     *
     * @return ConsumerGroupRound[]
     */
    public function findOpen(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :open')
            ->setParameter('open', ConsumerGroupRound::STATUS_OPEN)
            ->orderBy('r.ordersCloseAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Las rondas de ESTE productor, más recientes por fecha de entrega arriba.
     * Para su panel de autogestión: sólo ve lo suyo.
     *
     * @return ConsumerGroupRound[]
     */
    public function findAllForProducer(Producer $producer): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.producer = :producer')
            ->setParameter('producer', $producer)
            ->orderBy('r.deliveryDate', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
