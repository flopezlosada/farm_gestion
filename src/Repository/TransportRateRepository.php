<?php

namespace App\Repository;

use App\Entity\TransportRate;
use App\Entity\WeeklyBasketGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TransportRate>
 */
class TransportRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TransportRate::class);
    }

    /**
     * Todos los precios de transporte con su grupo, su nodo y quién los puso, en una consulta: son
     * pocas filas y así se puede preguntar por cientos de cestas sin ir a la base.
     *
     * @return list<TransportRate>
     */
    public function findAllWithGroup(): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('g', 'n', 'u')
            ->join('t.pickupGroup', 'g')
            ->leftJoin('g.node', 'n')
            ->leftJoin('t.createdBy', 'u')
            ->getQuery()->getResult();
    }

    /** Si el grupo ya tiene un precio que empieza ese día. */
    public function existsFor(WeeklyBasketGroup $group, \DateTimeInterface $validFrom): bool
    {
        return $this->count(['pickupGroup' => $group, 'validFrom' => \DateTimeImmutable::createFromInterface($validFrom)->setTime(0, 0)]) > 0;
    }
}
