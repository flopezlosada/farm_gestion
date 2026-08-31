<?php

namespace App\Repository;

use App\Entity\ConsumerGroupRoundItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Productos incluidos en cada ronda (con su precio de ronda).
 *
 * @extends ServiceEntityRepository<ConsumerGroupRoundItem>
 */
class ConsumerGroupRoundItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsumerGroupRoundItem::class);
    }
}
