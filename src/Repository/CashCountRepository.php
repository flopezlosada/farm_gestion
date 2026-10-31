<?php

namespace App\Repository;

use App\Entity\CashCount;
use App\Entity\FinancialAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Los arqueos de caja que llegan por el bot.
 *
 * @extends ServiceEntityRepository<CashCount>
 */
class CashCountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CashCount::class);
    }

    /**
     * El arqueo que ya se registró con ese mensaje de Telegram, si Telegram lo repite.
     *
     * @param int $updateId `update_id` del mensaje.
     */
    public function findByTelegramUpdateId(int $updateId): ?CashCount
    {
        return $this->findOneBy(['telegramUpdateId' => (string) $updateId]);
    }

    /**
     * El último arqueo de cada caja de efectivo abierta: el del día más reciente y, de
     * ese día, el último que llegó (si se contó mal, se manda otro y vale ese). Es el
     * que dice si la caja cuadra hoy; uno anterior ya lo ha sustituido.
     *
     * Una consulta para todas las cajas, con la caja ya cargada.
     *
     * @return list<CashCount>
     */
    public function findLatestOfActiveCashAccounts(): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('a')
            ->join('c.account', 'a')
            ->andWhere('a.active = true')
            ->andWhere('a.kind = :cash')
            ->andWhere('NOT EXISTS (SELECT later.id FROM ' . CashCount::class . ' later WHERE later.account = c.account AND (later.countDate > c.countDate OR (later.countDate = c.countDate AND later.id > c.id)))')
            ->setParameter('cash', FinancialAccount::KIND_CASH)
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
