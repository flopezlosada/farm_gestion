<?php

namespace App\Repository;

use App\Entity\Budget;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Los presupuestos, que son varios por año: el que aprobó la asamblea y las
 * revisiones que se hacen cuando la realidad se desvía.
 *
 * @extends ServiceEntityRepository<Budget>
 */
class BudgetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Budget::class);
    }

    /**
     * El presupuesto de referencia de un año: el aprobado más reciente. Es contra éste
     * contra el que se mide la ejecución salvo que se pida otro expresamente, porque
     * es el compromiso que la asociación votó.
     */
    public function findApprovedForYear(int $year): ?Budget
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.year = :year')->setParameter('year', $year)
            ->andWhere('b.approved = true')
            ->orderBy('b.approvedAt', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Todos los de un año, el aprobado primero.
     *
     * @return Budget[]
     */
    public function findForYear(int $year): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.year = :year')->setParameter('year', $year)
            ->orderBy('b.approved', 'DESC')
            ->addOrderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Años que tienen algún presupuesto, del más reciente al más antiguo, para el
     * selector de año.
     *
     * @return int[]
     */
    public function findYears(): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select('DISTINCT b.year')
            ->orderBy('b.year', 'DESC')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['year'], $rows);
    }
}
