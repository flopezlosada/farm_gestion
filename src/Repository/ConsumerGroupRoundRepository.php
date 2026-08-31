<?php

namespace App\Repository;

use App\Entity\ConsumerGroupRound;
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
}
