<?php

namespace App\Repository;

use App\Entity\WeeklyBasketStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Persistence\ManagerRegistry;

/**
 * @method WeeklyBasketStatus|null find($id, $lockMode = null, $lockVersion = null)
 * @method WeeklyBasketStatus|null findOneBy(array $criteria, array $orderBy = null)
 * @method WeeklyBasketStatus[]    findAll()
 * @method WeeklyBasketStatus[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WeeklyBasketStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WeeklyBasketStatus::class);
    }

    // /**
    //  * @return WeeklyBasketStatus[] Returns an array of WeeklyBasketStatus objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('w.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?WeeklyBasketStatus
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
