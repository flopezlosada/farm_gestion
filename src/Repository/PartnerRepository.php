<?php

namespace App\Repository;

use App\Entity\Basket;
use App\Entity\Partner;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Persistence\ManagerRegistry;

/**
 * @method Partner|null find($id, $lockMode = null, $lockVersion = null)
 * @method Partner|null findOneBy(array $criteria, array $orderBy = null)
 * @method Partner[]    findAll()
 * @method Partner[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PartnerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Partner::class);
    }

    // /**
    //  * @return Partner[] Returns an array of Partner objects
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
    public function findOneBySomeField($value): ?Partner
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    $dql_no_evaluated="select t from AppBundle:AssessmentBoardLearningDifficultiesType t where t not in (:ids) and t in (:ids_assessmentBoard) ";

    */

    public function findFamiliar(Partner $partner)
    {

        $partners_selected = array($partner->getId());
        foreach ($partner->getRelatives() as $relative) {
            $partners_selected[] = $relative->getId();
        }

        return $this->createQueryBuilder('p')
            ->where("p.id not in (:ids)")
            ->orderBy("p.name", "asc")
            ->setParameter("ids", $partners_selected)
            ->getQuery()
            ->getResult();

    }


    public function findActiveHasNoBasket()
    {
        $em = $this->getEntityManager();
        $dql_partner_basket = "select b from App:PartnerBasketShare b where b.is_active=1";
        $query_partner_basket = $em->createQuery($dql_partner_basket);
        $result = $query_partner_basket->getResult();

        $array_id_partners = array();

        if (count($result) > 0) {

            foreach ($result as $basket) {
                $array_id_partners[] = $basket->getPartner()->getId();
            }
        }

        $dql = "select p from App:Partner p where p.is_active=1 and p.parent is null"; //no tiene que tener parent, si no saldrían muchos que sí tienen cesta en realidad porque está asociada al parent

        if (count($array_id_partners)) {
            $dql .= ' and p.id not in (:ids) ';
        }

        $query = $em->createQuery($dql);

        if (count($array_id_partners)) {
            $query->setParameter("ids", $array_id_partners);
        }

        return $query->getResult();

    }


    /*
     * busca socios según el tipo de cesta
     */
    public function findPartnersByBasketType($type)
    {
        $em = $this->getEntityManager();
        $dql = 'select p from App:Partner p inner  join p.partner_basket_share b where b.basket_share=:type';
        $query = $em->createQuery($dql);
        $query->setParameter("type", $type);

        return $query->getResult();


    }

    public function findAmountPartnersByMonth(Basket $basket)
    {
        $em = $this->getEntityManager();
        $dql = "select count(p) from App:Partner p where p.inscription_date<=:basket_date and (p.demote_date is null or p.demote_date>=:basket_date)";
        $query = $em->createQuery($dql);
        $query->setParameter("basket_date", $basket->getDate()->format(('Y-m-d')));

        return $query->getSingleScalarResult();

    }


    public function findAmountBasketsByMonth(Basket $basket)
    {
        $em = $this->getEntityManager();
        $dql = "select p from App:Partner p where p.inscription_date<=:basket_date and (p.demote_date is null or p.demote_date>=:basket_date)";
        $query = $em->createQuery($dql);
        $query->setParameter("basket_date", $basket->getDate()->format(('Y-m-d')));
        $partners = $query->getResult();
        $amount = 0;
        foreach ($partners as $partner) {
            if ($partner->hasActiveBasket()) {
                $amount += $partner->getActiveBasket()->getBasketShare()->getCompleteBasketEquivalence();
            }
        }

        return $amount;
    }
}
