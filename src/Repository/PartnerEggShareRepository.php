<?php

namespace App\Repository;

use App\Entity\PartnerEggShare;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method PartnerEggShare|null find($id, $lockMode = null, $lockVersion = null)
 * @method PartnerEggShare|null findOneBy(array $criteria, array $orderBy = null)
 * @method PartnerEggShare[]    findAll()
 * @method PartnerEggShare[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PartnerEggShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PartnerEggShare::class);
    }

    // /**
    //  * @return PartnerEggShare[] Returns an array of PartnerEggShare objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?PartnerEggShare
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
