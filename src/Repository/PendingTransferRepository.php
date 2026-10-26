<?php

namespace App\Repository;

use App\Entity\PendingTransfer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PendingTransfer>
 */
class PendingTransferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PendingTransfer::class);
    }

    /**
     * Los que esperan a que alguien los confirme o descarte, los más antiguos primero.
     *
     * @return list<PendingTransfer>
     */
    public function findUnconfirmed(): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('f', 't', 'u')
            ->leftJoin('p.from', 'f')
            ->leftJoin('p.to', 't')
            ->join('p.sentBy', 'u')
            ->where('p.entry IS NULL')
            ->orderBy('p.date', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Lo relee bloqueado hasta el final de la transacción en curso, o null si ya no
     * está (lo han tirado desde el bot). Así confirmarlo en la web y deshacerlo en el
     * bot a la vez no dejan apuntes de algo que el bot da por deshecho.
     *
     * @param int $id Id del traspaso.
     */
    public function findLocked(int $id): ?PendingTransfer
    {
        return $this->createQueryBuilder('p')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }
}
