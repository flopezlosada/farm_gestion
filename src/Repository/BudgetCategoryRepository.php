<?php

namespace App\Repository;

use App\Entity\BudgetCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Partidas a las que se imputan los apuntes.
 *
 * @extends ServiceEntityRepository<BudgetCategory>
 */
class BudgetCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BudgetCategory::class);
    }

    /**
     * Partidas vigentes agrupadas para un desplegable, con su grupo cargado.
     *
     * @return BudgetCategory[]
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.group', 'g')->addSelect('g')
            ->andWhere('c.active = true')
            ->orderBy('g.sortOrder', 'ASC')
            ->addOrderBy('c.sortOrder', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Busca una partida por grupo y nombre. La usa la importación desde el Excel para
     * casar cada etiqueta del libro con su partida ya normalizada.
     */
    public function findOneByGroupAndName(string $groupName, string $name): ?BudgetCategory
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.group', 'g')
            ->andWhere('g.name = :groupName')->setParameter('groupName', $groupName)
            ->andWhere('c.name = :name')->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
