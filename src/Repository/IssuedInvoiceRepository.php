<?php

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\IssuedInvoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IssuedInvoice>
 */
class IssuedInvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IssuedInvoice::class);
    }

    /**
     * El último número de una serie en un año, con las filas bloqueadas hasta el
     * final de la transacción: dos personas emitiendo a la vez no cogen el mismo.
     * Sólo dentro de una transacción.
     */
    public function lastNumberLocked(string $series, int $year): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('MAX(i.number)')
            ->andWhere('i.series = :series')
            ->andWhere('i.year = :year')
            ->setParameter('series', $series)
            ->setParameter('year', $year)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getSingleScalarResult();
    }

    /**
     * La emitida más reciente por fecha de una serie en un año (la de mayor número
     * si hay varias ese día), o null si aún no hay ninguna.
     */
    public function findLastIssued(string $series, int $year): ?IssuedInvoice
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.status = :issued')
            ->andWhere('i.series = :series')
            ->andWhere('i.year = :year')
            ->setParameter('issued', IssuedInvoice::STATUS_ISSUED)
            ->setParameter('series', $series)
            ->setParameter('year', $year)
            ->orderBy('i.issueDate', 'DESC')
            ->addOrderBy('i.number', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Los borradores (de cualquier fecha) y lo emitido en el año, con sus líneas y
     * su cliente: lo que enseña el listado. Borradores primero; luego de la más
     * reciente a la más antigua.
     *
     * @return list<IssuedInvoice>
     */
    public function findForYear(int $year): array
    {
        return $this->createQueryBuilder('i')
            ->addSelect('l', 'c')
            ->leftJoin('i.lines', 'l')
            ->leftJoin('i.customer', 'c')
            ->andWhere('i.status = :draft OR i.year = :year')
            ->setParameter('draft', IssuedInvoice::STATUS_DRAFT)
            ->setParameter('year', $year)
            ->orderBy('i.status', 'ASC') // «draft» antes que «issued»
            ->addOrderBy('i.issueDate', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Las emitidas a un cliente, de la más reciente a la más antigua.
     *
     * @return list<IssuedInvoice>
     */
    public function findIssuedToCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('i')
            ->addSelect('l')
            ->leftJoin('i.lines', 'l')
            ->andWhere('i.customer = :customer')
            ->andWhere('i.status = :issued')
            ->setParameter('customer', $customer)
            ->setParameter('issued', IssuedInvoice::STATUS_ISSUED)
            ->orderBy('i.issueDate', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Las emitidas que no están ligadas a ningún apunte del libro, con la factura que
     * rectifican (si lo son) y su cliente: lo que queda por cobrar o por devolver. Las
     * más antiguas primero. Un borrador no cuenta: todavía no se debe nada.
     *
     * @return list<IssuedInvoice>
     */
    public function findUnpaid(): array
    {
        return $this->createQueryBuilder('i')
            ->addSelect('r', 'c')
            ->leftJoin('i.rectifies', 'r')
            ->leftJoin('i.customer', 'c')
            ->andWhere('i.status = :issued')
            ->andWhere('i.accountEntry IS NULL')
            ->setParameter('issued', IssuedInvoice::STATUS_ISSUED)
            ->orderBy('i.issueDate', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Las emitidas con fecha entre dos días, ambos incluidos, con sus líneas: lo
     * que va al libro de facturas emitidas. Por fecha y, el mismo día, por serie y
     * número.
     *
     * @return list<IssuedInvoice>
     */
    public function findIssuedBetween(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('i')
            ->addSelect('l')
            ->leftJoin('i.lines', 'l')
            ->andWhere('i.status = :issued')
            ->andWhere('i.issueDate BETWEEN :from AND :to')
            ->setParameter('issued', IssuedInvoice::STATUS_ISSUED)
            ->setParameter('from', \DateTimeImmutable::createFromInterface($from)->setTime(0, 0), 'date_immutable')
            ->setParameter('to', \DateTimeImmutable::createFromInterface($to)->setTime(0, 0), 'date_immutable')
            ->orderBy('i.issueDate', 'ASC')
            ->addOrderBy('i.series', 'ASC')
            ->addOrderBy('i.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Las rectificativas que tiene una factura.
     *
     * @return list<IssuedInvoice>
     */
    public function findRectificationsOf(IssuedInvoice $invoice): array
    {
        return $this->findBy(['rectifies' => $invoice], ['id' => 'ASC']);
    }
}
