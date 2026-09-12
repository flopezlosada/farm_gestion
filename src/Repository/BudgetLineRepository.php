<?php

namespace App\Repository;

use App\Entity\Budget;
use App\Entity\BudgetLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Lo previsto por partida y mes.
 *
 * @extends ServiceEntityRepository<BudgetLine>
 */
class BudgetLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BudgetLine::class);
    }

    /**
     * Lo presupuestado de un año, indexado por partida y mes: `[categoryId][month] =>
     * importe`. Una sola consulta para toda la rejilla del seguimiento.
     *
     * @return array<int, array<int, string>>
     */
    public function totalsByCategoryAndMonth(Budget $budget): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.category) AS categoryId', 'l.month', 'SUM(l.amount) AS total')
            ->andWhere('l.budget = :budget')->setParameter('budget', $budget)
            ->groupBy('l.category', 'l.month')
            ->getQuery()
            ->getScalarResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['categoryId']][(int) $row['month']] = (string) $row['total'];
        }

        return $out;
    }
}
