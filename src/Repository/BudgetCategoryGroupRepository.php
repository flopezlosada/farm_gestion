<?php

namespace App\Repository;

use App\Entity\BudgetCategoryGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Grupos de partidas, que son la unidad con la que se compara presupuesto y realidad.
 *
 * @extends ServiceEntityRepository<BudgetCategoryGroup>
 */
class BudgetCategoryGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BudgetCategoryGroup::class);
    }

    /**
     * Todos los grupos con sus partidas ya cargadas, en orden de presentación. Evita
     * la ristra de consultas que provocaría recorrerlos pintando sus partidas.
     *
     * @return BudgetCategoryGroup[]
     */
    public function findAllWithCategories(): array
    {
        return $this->createQueryBuilder('g')
            ->leftJoin('g.categories', 'c')->addSelect('c')
            ->orderBy('g.sortOrder', 'ASC')
            ->addOrderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
