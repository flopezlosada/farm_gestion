<?php

namespace App\Repository;

use App\Entity\EggAmount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method EggAmount|null find($id, $lockMode = null, $lockVersion = null)
 * @method EggAmount|null findOneBy(array $criteria, array $orderBy = null)
 * @method EggAmount[]    findAll()
 * @method EggAmount[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EggAmountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EggAmount::class);
    }

    // /**
    //  * @return EggAmount[] Returns an array of EggAmount objects
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
    public function findOneBySomeField($value): ?EggAmount
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
