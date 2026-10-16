<?php

namespace App\Repository;

use App\Entity\AccountantDelivery;
use App\Service\Accounting\Invoice\InvoiceQuarter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountantDelivery>
 */
class AccountantDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountantDelivery::class);
    }

    /** La entrega más reciente de un trimestre, o null si nunca se entregó. */
    public function latestFor(InvoiceQuarter $quarter): ?AccountantDelivery
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.year = :year')->setParameter('year', $quarter->year)
            ->andWhere('d.quarter = :quarter')->setParameter('quarter', $quarter->quarter)
            ->orderBy('d.deliveredAt', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * La entrega más reciente de cada trimestre que se haya entregado alguna vez,
     * con clave «año-trimestre» («2026-3»). Son unas pocas filas al año: se traen
     * todas y se elige en PHP, en una sola consulta.
     *
     * @return array<string, AccountantDelivery>
     */
    public function latestByQuarter(): array
    {
        $latest = [];
        foreach ($this->findBy([], ['deliveredAt' => 'ASC', 'id' => 'ASC']) as $delivery) {
            $quarter = $delivery->getQuarter();
            $latest[$quarter->year.'-'.$quarter->quarter] = $delivery;
        }

        return $latest;
    }
}
