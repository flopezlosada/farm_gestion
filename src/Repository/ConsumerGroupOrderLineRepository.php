<?php

namespace App\Repository;

use App\Entity\ConsumerGroupOrderLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Líneas de los pedidos del grupo de consumo.
 *
 * @extends ServiceEntityRepository<ConsumerGroupOrderLine>
 */
class ConsumerGroupOrderLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsumerGroupOrderLine::class);
    }
}
