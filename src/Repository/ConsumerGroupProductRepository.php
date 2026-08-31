<?php

namespace App\Repository;

use App\Entity\ConsumerGroupProduct;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Productos del catálogo de cada ronda del grupo de consumo.
 *
 * @extends ServiceEntityRepository<ConsumerGroupProduct>
 */
class ConsumerGroupProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsumerGroupProduct::class);
    }
}
