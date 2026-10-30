<?php

namespace App\Repository;

use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
     * Las partidas a las que se puede imputar un apunte a mano: las vigentes, sin la
     * de traspasos. Ésa crearía media mitad de un traspaso sin la otra y el saldo de
     * una cuenta quedaría mal; mover dinero entre cuentas propias tiene su acción.
     */
    public function forEntriesQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.group', 'g')->addSelect('g')
            ->andWhere('c.active = true')
            ->andWhere('g.kind != :transfer')
            ->setParameter('transfer', BudgetCategoryGroup::KIND_TRANSFER)
            ->orderBy('g.sortOrder', 'ASC')
            ->addOrderBy('c.sortOrder', 'ASC');
    }

    /**
     * Las de {@see forEntriesQueryBuilder}, ya cargadas.
     *
     * @return BudgetCategory[]
     */
    public function findForEntries(): array
    {
        return $this->forEntriesQueryBuilder()->getQuery()->getResult();
    }

    /**
     * El orden que pone una partida la última de su grupo: el orden se decide
     * arrastrando en el catálogo, y lo que llega nuevo a un grupo va al final.
     */
    public function nextSortOrder(BudgetCategoryGroup $group): int
    {
        $max = $this->createQueryBuilder('c')
            ->select('MAX(c.sortOrder)')
            ->andWhere('c.group = :group')
            ->setParameter('group', $group)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $max + 10;
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
