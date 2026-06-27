<?php

namespace App\Repository;

use App\Entity\Worker;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Worker|null find($id, $lockMode = null, $lockVersion = null)
 * @method Worker|null findOneBy(array $criteria, array $orderBy = null)
 * @method Worker[]    findAll()
 * @method Worker[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WorkerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Worker::class);
    }

    /**
     * Trabajadores activos (los que pueden fichar), ordenados por nombre. Es la
     * lista que ve el supervisor en el panel de jornada.
     *
     * @return Worker[]
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.active = true')
            ->orderBy('w.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
