<?php

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\IssuedInvoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Customer>
 */
class CustomerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }

    /**
     * Todos, por nombre, con cuántas facturas emitidas tienen y lo facturado en el año.
     *
     * @return list<array{customer: Customer, invoices: int, total: float}>
     */
    public function findWithYearTotals(int $year): array
    {
        $customers = $this->createQueryBuilder('c')->orderBy('c.name', 'ASC')->getQuery()->getResult();

        /** @var list<IssuedInvoice> $issued */
        $issued = $this->getEntityManager()->createQueryBuilder()
            ->select('i', 'l')
            ->from(IssuedInvoice::class, 'i')
            ->leftJoin('i.lines', 'l')
            ->andWhere('i.status = :issued')
            ->andWhere('i.year = :year')
            ->setParameter('issued', IssuedInvoice::STATUS_ISSUED)
            ->setParameter('year', $year)
            ->getQuery()
            ->getResult();

        $byCustomer = [];
        foreach ($issued as $invoice) {
            $id = $invoice->getCustomer()?->getId();
            $byCustomer[$id]['invoices'] = ($byCustomer[$id]['invoices'] ?? 0) + 1;
            $byCustomer[$id]['total'] = ($byCustomer[$id]['total'] ?? 0.0) + (float) $invoice->getTotal();
        }

        return array_map(static fn (Customer $c): array => [
            'customer' => $c,
            'invoices' => $byCustomer[$c->getId()]['invoices'] ?? 0,
            'total' => $byCustomer[$c->getId()]['total'] ?? 0.0,
        ], $customers);
    }
}
