<?php

namespace App\Repository;

use App\Entity\VolunteerCategory;
use App\Entity\VolunteerEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VolunteerEvent>
 */
class VolunteerEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VolunteerEvent::class);
    }

    /**
     * El rastro de actividad, de lo más reciente hacia atrás, como QueryBuilder
     * para poder paginarlo.
     *
     * EL FILTRO POR ÁREA ES EL PUNTO DE LA PANTALLA: quien coordina el reparto
     * ve lo que pasa en el reparto y nada más. Un evento pertenece a un área si
     * la tarea a la que se refiere está en ella, o si el propio evento la
     * declara (los que no tienen tarea: crear un tipo de trabajo, cambiar quién
     * lo coordina).
     *
     * Los eventos SIN área ninguna —los de un socix cambiando sus preferencias—
     * quedan fuera para quien coordina y sólo los ve administración. No son de
     * nadie en particular y no hay forma honesta de repartirlos.
     *
     * @param list<VolunteerCategory>|null $restrictTo áreas de quien mira; null = ve todo
     * @param string|null                  $type       filtra por tipo de evento
     */
    public function feedQb(?array $restrictTo = null, ?string $type = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.offer', 'o')
            ->leftJoin('e.partner', 'p')
            ->leftJoin('e.category', 'c')
            ->addSelect('o', 'p', 'c')
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC');

        if (null !== $type) {
            $qb->andWhere('e.type = :type')->setParameter('type', $type);
        }

        if (null !== $restrictTo) {
            if ([] === $restrictTo) {
                return $qb->andWhere('1 = 0');
            }

            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->exists(
                        'SELECT 1 FROM App\Entity\VolunteerOffer oe JOIN oe.categories ce'
                        .' WHERE oe = e.offer AND ce IN (:mine)'
                    ),
                    'e.category IN (:mine)'
                )
            )->setParameter('mine', $restrictTo);
        }

        return $qb;
    }
}
