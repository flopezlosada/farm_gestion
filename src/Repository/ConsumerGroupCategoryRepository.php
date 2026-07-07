<?php

namespace App\Repository;

use App\Entity\ConsumerGroupCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Categorías de producto del grupo de consumo.
 *
 * @extends ServiceEntityRepository<ConsumerGroupCategory>
 */
class ConsumerGroupCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsumerGroupCategory::class);
    }

    /**
     * Categorías ordenadas para listar y elegir en el catálogo.
     *
     * @return ConsumerGroupCategory[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.sortOrder', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
