<?php

namespace App\Repository;

use App\Entity\ConsumerGroupEventLog;
use App\Entity\ConsumerGroupRound;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Consultas sobre la bitácora de eventos del grupo de consumo ({@see ConsumerGroupEventLog}).
 *
 * @extends ServiceEntityRepository<ConsumerGroupEventLog>
 */
class ConsumerGroupEventLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsumerGroupEventLog::class);
    }

    /**
     * La actividad de un pedido, de más nueva a más vieja.
     *
     * @return ConsumerGroupEventLog[]
     */
    public function findByRound(ConsumerGroupRound $round): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.actorUser', 'u')->addSelect('u')
            ->where('e.round = :round')
            ->setParameter('round', $round)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
