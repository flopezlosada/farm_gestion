<?php

namespace App\Repository;

use App\Entity\Producer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Productores del grupo de consumo (catálogo persistente).
 *
 * @extends ServiceEntityRepository<Producer>
 */
class ProducerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Producer::class);
    }

    /**
     * Productores ordenados por nombre, para el listado de gestión.
     *
     * @return Producer[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.active', 'DESC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Productores activos, para elegir al abrir una ronda.
     *
     * @return Producer[]
     */
    public function findActive(): array
    {
        return $this->findBy(['active' => true], ['name' => 'ASC']);
    }
}
