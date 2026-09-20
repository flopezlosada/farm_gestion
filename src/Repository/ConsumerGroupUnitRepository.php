<?php

namespace App\Repository;

use App\Entity\ConsumerGroupUnit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Unidades de venta del grupo de consumo (globales, compartidas entre productores).
 *
 * @extends ServiceEntityRepository<ConsumerGroupUnit>
 */
class ConsumerGroupUnitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsumerGroupUnit::class);
    }

    /**
     * Todas, ordenadas para listar y editar.
     *
     * @return ConsumerGroupUnit[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.sortOrder', 'ASC')
            ->addOrderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Sólo las activas, para ofrecerlas al elegir la unidad de un producto.
     *
     * @return ConsumerGroupUnit[]
     */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.active = true')
            ->orderBy('u.sortOrder', 'ASC')
            ->addOrderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
