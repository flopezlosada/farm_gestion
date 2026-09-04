<?php

namespace App\Repository;

use App\Entity\VolunteerPlace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VolunteerPlace>
 */
class VolunteerPlaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VolunteerPlace::class);
    }

    /**
     * Los sitios que se siguen usando, por nombre.
     *
     * @return list<VolunteerPlace> los sitios activos
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.active = true')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Todos los sitios, activos primero y por nombre. Es el orden del listado de
     * mantenimiento: lo que está en uso arriba, lo retirado al final.
     *
     * @return list<VolunteerPlace> todos los sitios
     */
    public function findAllSorted(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.active', 'DESC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
