<?php

namespace App\Repository;

use App\Entity\BankStatement;
use App\Entity\BankStatementLine;
use App\Entity\FinancialAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Los extractos subidos.
 *
 * @extends ServiceEntityRepository<BankStatement>
 */
class BankStatementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankStatement::class);
    }

    /**
     * Todos, los más recientes primero, con su cuenta.
     *
     * @return list<BankStatement>
     */
    public function findLatestFirst(): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.account', 'a')->addSelect('a')
            ->orderBy('s.to', 'DESC')
            ->addOrderBy('a.sortOrder', 'ASC')
            ->addOrderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** El extracto ya subido de esa cuenta para esas mismas fechas, si lo hay. */
    public function findSamePeriod(FinancialAccount $account, \DateTimeImmutable $from, \DateTimeImmutable $to): ?BankStatement
    {
        return $this->findOneBy(['account' => $account, 'from' => $from, 'to' => $to]);
    }

    /**
     * El último extracto de la cuenta que empieza el día $from o antes: el que un extracto
     * nuevo que empieza ese día continúa (o el mismo fichero, más largo, si empieza igual).
     */
    public function findLatestStartingBy(FinancialAccount $account, \DateTimeImmutable $from): ?BankStatement
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.account = :account')->setParameter('account', $account)
            ->andWhere('s.from <= :from')->setParameter('from', $from)
            ->orderBy('s.from', 'DESC')
            ->addOrderBy('s.to', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Cuántos movimientos sin apunte tiene cada extracto, en una consulta:
     * `[id del extracto => nº]`. Cuentan los de su cuenta entre sus fechas, desde la
     * apertura de la cuenta y distintos de cero, como en la ficha. Sólo salen los que
     * tienen alguno.
     *
     * @param bool $activeAccountsOnly Contar sólo las cuentas activas, como la portada
     *                                 ({@see BankStatementLineRepository::countUnlinked}).
     *
     * @return array<int, int>
     */
    public function unlinkedCounts(bool $activeAccountsOnly = false): array
    {
        $qb = $this->createQueryBuilder('s')
            ->select('s.id AS id, COUNT(l.id) AS n')
            ->innerJoin('s.account', 'a')
            ->innerJoin(BankStatementLine::class, 'l', 'WITH', 'l.account = s.account AND l.operationDate BETWEEN s.from AND s.to')
            ->andWhere('l.entry IS NULL')
            ->andWhere('l.amount != 0')
            ->andWhere('l.operationDate >= a.openingDate')
            ->groupBy('s.id');
        if ($activeAccountsOnly) {
            $qb->andWhere('a.active = true');
        }
        $rows = $qb->getQuery()->getArrayResult();

        return array_column(array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'n' => (int) $r['n']], $rows), 'n', 'id');
    }
}
