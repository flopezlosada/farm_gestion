<?php

namespace App\Repository;

use App\Entity\VolunteerCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VolunteerCategory>
 */
class VolunteerCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VolunteerCategory::class);
    }

    /**
     * Las categorías que siguen ofreciéndose, por nombre. Es lo que se pinta
     * tanto al crear una oferta como en la ficha del socix: una categoría
     * retirada no debe poder elegirse de nuevo, aunque el histórico la conserve.
     *
     * @return list<VolunteerCategory> las categorías activas, ordenadas por nombre
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.active = true')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
