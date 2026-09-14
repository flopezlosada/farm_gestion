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

    /**
     * Lo que suma cada presupuesto, en UNA consulta: ingresos, gastos y cuántas líneas
     * tiene. Recorrer `$budget->getLines()` en el listado traería a memoria varios
     * miles de líneas —unas 240 por año— para acabar sumando cuatro números.
     *
     * @return array<int, array{income: float, expense: float, lines: int}>
     */
    public function totals(): array
    {
        $rows = $this->getEntityManager()->createQuery(
            'SELECT IDENTITY(l.budget) AS budgetId,
                    SUM(CASE WHEN l.amount > 0 THEN l.amount ELSE 0 END) AS income,
                    SUM(CASE WHEN l.amount < 0 THEN l.amount ELSE 0 END) AS expense,
                    COUNT(l.id) AS lines
             FROM App\Entity\BudgetLine l
             GROUP BY l.budget'
        )->getScalarResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['budgetId']] = [
                'income' => (float) $row['income'],
                'expense' => (float) $row['expense'],
                'lines' => (int) $row['lines'],
            ];
        }

        return $out;
    }
}
