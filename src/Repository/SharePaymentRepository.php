<?php

namespace App\Repository;

use App\Entity\SharePayment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method SharePayment|null find($id, $lockMode = null, $lockVersion = null)
 * @method SharePayment|null findOneBy(array $criteria, array $orderBy = null)
 * @method SharePayment[]    findAll()
 * @method SharePayment[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SharePaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SharePayment::class);
    }

    // /**
    //  * @return SharePayment[] Returns an array of SharePayment objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('s.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?SharePayment
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
