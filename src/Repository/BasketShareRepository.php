<?php

namespace App\Repository;

use App\Entity\BasketShare;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Persistence\ManagerRegistry;

/**
 * @method BasketShare|null find($id, $lockMode = null, $lockVersion = null)
 * @method BasketShare|null findOneBy(array $criteria, array $orderBy = null)
 * @method BasketShare[]    findAll()
 * @method BasketShare[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BasketShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BasketShare::class);
    }

    // /**
    //  * @return BasketShare[] Returns an array of BasketShare objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('b.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?BasketShare
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */


}
