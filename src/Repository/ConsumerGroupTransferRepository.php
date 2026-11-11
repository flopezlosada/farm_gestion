<?php

namespace App\Repository;

use App\Entity\BankStatementLine;
use App\Entity\BudgetCategory;
use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupTransfer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ConsumerGroupTransfer>
 */
class ConsumerGroupTransferRepository extends ServiceEntityRepository
{
    /** @param ManagerRegistry $registry el registro de Doctrine */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsumerGroupTransfer::class);
    }

    /**
     * Los ingresos del banco que pueden pagar pedidos y nadie ha decidido aún: desde
     * un día, sin apunte o con apunte en la partida del grupo de consumo (así una
     * cuota o un donativo nunca paga un pedido). Los más antiguos primero, que es el
     * orden en que se pagaron.
     *
     * @param \DateTimeImmutable  $from          desde qué día del banco
     * @param BudgetCategory|null $category      la partida del grupo de consumo; null = sólo los sin apunte
     * @param bool                $withReleased  si entran también los que una persona soltó
     *                                           (los ve la pantalla, no el cruce automático)
     *
     * @return list<BankStatementLine>
     */
    public function findUndecidedLines(\DateTimeImmutable $from, ?BudgetCategory $category, bool $withReleased): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('l', 'a', 'e')
            ->from(BankStatementLine::class, 'l')
            ->innerJoin('l.account', 'a')
            ->leftJoin('l.entry', 'e')
            ->leftJoin(ConsumerGroupTransfer::class, 't', 'WITH', 't.line = l')
            ->andWhere('l.amount > 0')
            ->andWhere('l.operationDate >= :from')->setParameter('from', $from)
            ->orderBy('l.operationDate', 'ASC')
            ->addOrderBy('l.id', 'ASC');
        $category !== null
            ? $qb->andWhere('e.id IS NULL OR e.category = :category')->setParameter('category', $category)
            : $qb->andWhere('e.id IS NULL');
        $withReleased
            ? $qb->andWhere(sprintf('t.id IS NULL OR (t.dismissed = false AND NOT EXISTS (SELECT o.id FROM %s o WHERE o.transfer = t))', ConsumerGroupOrder::class))
            : $qb->andWhere('t.id IS NULL');

        return $qb->getQuery()->getResult();
    }

    /**
     * Las decisiones de unos movimientos, por id de movimiento. Una consulta.
     *
     * @param list<BankStatementLine> $lines
     *
     * @return array<int, ConsumerGroupTransfer>
     */
    public function byLine(array $lines): array
    {
        if ($lines === []) {
            return [];
        }
        $found = [];
        foreach ($this->findBy(['line' => $lines]) as $transfer) {
            $found[$transfer->getLine()->getId()] = $transfer;
        }

        return $found;
    }

    /**
     * Las asignadas, las del banco más recientes primero, con sus pedidos y socias.
     *
     * @return list<ConsumerGroupTransfer>
     */
    public function findAssigned(int $limit): array
    {
        $ids = $this->createQueryBuilder('t')
            ->select('t.id')
            ->innerJoin('t.line', 'l')
            ->innerJoin('t.orders', 'o')
            ->groupBy('t.id')
            ->orderBy('MAX(l.operationDate)', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return $ids === [] ? [] : $this->withOrders()
            ->andWhere('t.id IN (:ids)')->setParameter('ids', $ids)
            ->orderBy('l.operationDate', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Las descartadas («no es de un pedido»), las del banco más recientes primero.
     *
     * @return list<ConsumerGroupTransfer>
     */
    public function findDismissed(int $limit): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('l', 'a')
            ->innerJoin('t.line', 'l')
            ->innerJoin('l.account', 'a')
            ->andWhere('t.dismissed = true')
            ->orderBy('l.operationDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Las decisiones con su movimiento, su cuenta y sus pedidos con ronda y socia, en una consulta. */
    private function withOrders(): QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->addSelect('l', 'a', 'o', 'r', 'p')
            ->innerJoin('t.line', 'l')
            ->innerJoin('l.account', 'a')
            ->innerJoin('t.orders', 'o')
            ->innerJoin('o.round', 'r')
            ->innerJoin('o.partner', 'p');
    }
}
