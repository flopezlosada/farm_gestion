<?php

namespace App\Repository;

use App\Entity\AccountEntry;
use App\Entity\BankStatementLine;
use App\Entity\FinancialAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Los movimientos que han traído los extractos del banco.
 *
 * «Sin apunte» quiere decir siempre lo mismo aquí: sin ligar, distinto de cero y desde
 * la apertura de su cuenta (lo anterior ya está dentro del saldo de apertura).
 *
 * @extends ServiceEntityRepository<BankStatementLine>
 */
class BankStatementLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BankStatementLine::class);
    }

    /**
     * De estas huellas, las que ya están guardadas, en una consulta.
     *
     * @param list<string> $fingerprints
     *
     * @return array<string, true>
     */
    public function existingFingerprints(array $fingerprints): array
    {
        if ($fingerprints === []) {
            return [];
        }
        $found = $this->createQueryBuilder('l')
            ->select('l.fingerprint')
            ->andWhere('l.fingerprint IN (:fingerprints)')
            ->setParameter('fingerprints', $fingerprints)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys($found, true);
    }

    /**
     * Los movimientos sin apunte de una cuenta entre dos fechas, por fecha.
     *
     * @return list<BankStatementLine>
     */
    public function findUnlinkedIn(FinancialAccount $account, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->inPeriod($account, $from, $to)
            ->andWhere('l.entry IS NULL')
            ->andWhere('l.amount != 0')
            ->andWhere('l.operationDate >= :opening')
            ->setParameter('opening', $account->getOpeningDate())
            ->orderBy('l.operationDate', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Cuántos movimientos de una cuenta entre dos fechas están ya ligados a su apunte. */
    public function countLinkedIn(FinancialAccount $account, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->inPeriod($account, $from, $to)
            ->select('COUNT(l.id)')
            ->andWhere('l.entry IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Cuántos movimientos sin apunte hay en las cuentas activas: lo que avisa la portada. */
    public function countUnlinked(): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->innerJoin('l.account', 'a')
            ->andWhere('l.entry IS NULL')
            ->andWhere('l.amount != 0')
            ->andWhere('l.operationDate >= a.openingDate')
            ->andWhere('a.active = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Si el apunte ya explica algún movimiento del banco. */
    public function isLinked(AccountEntry $entry): bool
    {
        return $this->count(['entry' => $entry]) > 0;
    }

    /** Los movimientos de una cuenta entre dos fechas, incluidas. */
    private function inPeriod(FinancialAccount $account, \DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.account = :account')
            ->andWhere('l.operationDate BETWEEN :from AND :to')
            ->setParameter('account', $account)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'));
    }
}
