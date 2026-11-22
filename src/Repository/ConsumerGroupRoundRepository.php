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
            ->leftJoin('r.producers', 'pr')->addSelect('pr')
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
            ->leftJoin('r.producers', 'pr')->addSelect('pr')
            ->orderBy('r.deliveryDate', 'DESC')
            ->addOrderBy('r.id', 'DESC');

        if (!empty($filters['producer'])) {
            // MEMBER OF y no un WHERE sobre el join de arriba: ese join es el que
            // carga TODOS los productores del pedido, y filtrarlo dejaría solo uno.
            $qb->andWhere(':producer MEMBER OF r.producers')->setParameter('producer', $filters['producer']);
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
        return $this->getEntityManager()->createQuery(
            'SELECT p FROM App\Entity\Producer p
             WHERE EXISTS (SELECT 1 FROM App\Entity\ConsumerGroupRound r JOIN r.producers rp WHERE rp = p)
             ORDER BY p.name ASC'
        )->getResult();
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
            ->leftJoin('r.producers', 'pr')->addSelect('pr')
            ->where('r.status = :open')
            ->setParameter('open', ConsumerGroupRound::STATUS_OPEN)
            ->orderBy('r.ordersCloseAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Las rondas en las que participa ESTE productor (solo o con otros), más
     * recientes por fecha de entrega arriba. Cada una trae TODOS sus productores
     * cargados, no solo este.
     *
     * @return ConsumerGroupRound[]
     */
    public function findAllForProducer(Producer $producer): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.producers', 'pr')->addSelect('pr')
            ->where(':producer MEMBER OF r.producers')
            ->setParameter('producer', $producer)
            ->orderBy('r.deliveryDate', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
