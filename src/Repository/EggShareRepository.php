<?php

namespace App\Repository;

use App\Entity\EggShare;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method EggShare|null find($id, $lockMode = null, $lockVersion = null)
 * @method EggShare|null findOneBy(array $criteria, array $orderBy = null)
 * @method EggShare[]    findAll()
 * @method EggShare[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EggShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EggShare::class);
    }

    // /**
    //  * @return EggShare[] Returns an array of EggShare objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('e.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?EggShare
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
