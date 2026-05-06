<?php

namespace App\Repository;

use App\Entity\WeeklyBasket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method WeeklyBasket|null find($id, $lockMode = null, $lockVersion = null)
 * @method WeeklyBasket|null findOneBy(array $criteria, array $orderBy = null)
 * @method WeeklyBasket[]    findAll()
 * @method WeeklyBasket[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WeeklyBasketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WeeklyBasket::class);
    }

    // /**
    //  * @return WeeklyBasket[] Returns an array of WeeklyBasket objects
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
    public function findOneBySomeField($value): ?WeeklyBasket
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */

    /**
     * @param $basket
     * @param $basket_share
     * @return las cestas de la semana, excepto las medias compartidas, ordenadas por el grupo al que pertenecen
     */
    public function findAllByGroupNotHalfBasket($basket,$basket_share)
    {
        $em = $this->getEntityManager();

        $dql = "select w from App:WeeklyBasket w inner join w.partner p where w.basket_share<>:basket_share and
                      w.basket=:basket order by w.weekly_basket_group,p.name,p.surname";

        $query = $em->createQuery($dql);
        $query->setParameter("basket_share", $basket_share);
        $query->setParameter("basket", $basket);


        return $query->getResult();
    }
}