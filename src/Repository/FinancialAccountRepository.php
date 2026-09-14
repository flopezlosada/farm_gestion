<?php

namespace App\Repository;

use App\Entity\FinancialAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Cuentas donde vive el dinero: bancos y cajas de efectivo.
 *
 * @extends ServiceEntityRepository<FinancialAccount>
 */
class FinancialAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FinancialAccount::class);
    }

    /**
     * Cuentas abiertas, en su orden de presentación. Es lo que se ofrece al anotar y
     * lo que encabeza el resumen del mes.
     *
     * @return FinancialAccount[]
     */
    public function findActive(): array
    {
        return $this->activeQueryBuilder()->getQuery()->getResult();
    }

    /**
     * El mismo criterio, sin ejecutar, para los desplegables de los formularios: así
     * una cuenta cerrada no se puede elegir al anotar aunque siga en el libro.
     */
    public function activeQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.active = true')
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.name', 'ASC');
    }
}
