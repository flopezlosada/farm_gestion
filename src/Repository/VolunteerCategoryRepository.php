<?php

namespace App\Repository;

use App\Entity\VolunteerCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
     * El listado de tipos de trabajo con sus filtros, como QueryBuilder para
     * poder paginarlo igual que el de socixs.
     *
     * @param string      $scope active | retired | all
     * @param string|null $query texto libre sobre nombre y descripción
     */
    public function listQb(string $scope = 'active', ?string $query = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')->orderBy('c.name', 'ASC');

        if ('active' === $scope) {
            $qb->andWhere('c.active = true');
        } elseif ('retired' === $scope) {
            $qb->andWhere('c.active = false');
        }

        if (null !== $query && '' !== trim($query)) {
            $qb->andWhere('LOWER(c.name) LIKE :q OR LOWER(c.description) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower(trim($query)).'%');
        }

        return $qb;
    }

    /**
     * Cuántos tipos hay en cada estado, para la tira de cifras del listado.
     *
     * @return array{active: int, retired: int, all: int}
     */
    public function counts(): array
    {
        $count = fn (string $scope): int => (int) $this->listQb($scope)
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'active' => $count('active'),
            'retired' => $count('retired'),
            'all' => $count('all'),
        ];
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
