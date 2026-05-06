<?php

namespace App\Repository;

use App\Entity\PartnerBasketShare;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method PartnerBasketShare|null find($id, $lockMode = null, $lockVersion = null)
 * @method PartnerBasketShare|null findOneBy(array $criteria, array $orderBy = null)
 * @method PartnerBasketShare[]    findAll()
 * @method PartnerBasketShare[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PartnerBasketShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PartnerBasketShare::class);
    }

    // /**
    //  * @return PartnerBasketShare[] Returns an array of PartnerBasketShare objects
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
    public function findOneBySomeField($value): ?PartnerBasketShare
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */

    public function findAllByStatus($status)
    {
        return $this->createQueryBuilder("b")
            ->where("b.is_active=:status")
            ->setParameter("status", $status)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param $basket_share
     * @param $status
     * devuelve la lista de socios con cesta ordenados por pueblo y según el tipo de cesta
     */
    public function findBasketPartnersByTypeAndCity($basket_share, $status, $current_basket,$only_eggs=false)
    {
        $em = $this->getEntityManager();

        $dql = "select b from App:PartnerBasketShare b inner join b.partner p where b.basket_share=:basket_share and
                      b.is_active=:status and b.start_date<=:date ";
        if ($only_eggs)
        {
            $dql.=" and b.egg_period=1 ";
        }

        $dql.=" ORDER BY  p.weekly_basket_group asc";
        $query = $em->createQuery($dql);
        $query->setParameter("basket_share", $basket_share);
        $query->setParameter("status", $status);
        $query->setParameter("date", $current_basket->getDate());


        return $query->getResult();
    }


    /*
     * Son los socios quincenales. Hay que buscar que no estén en la semana anterior
     */
    public function findBasketPartnersBiweeklyAndCity($current_basket,$basket, $status_id, $only_eggs=false)
    {

        $em = $this->getEntityManager();

        $dql = "select b from App:WeeklyBasket b where b.basket=:basket";
        $query = $em->createQuery($dql);

        $query->setParameter("basket", $this->getPreviousBasket($current_basket->getId()));

        $result = $query->getResult();
        $array_id_partners = array(); //es el array con las ids de los socios que recibieron la semana anterior. Habrá txdo tipo de socios, semanales, mensuales,...

        $dql_partners = "select b from App:PartnerBasketShare b inner join b.partner p where b.basket_share=:basket_share and b.is_active=:status and b.start_date<=:date ";
        if (count($result) > 0) {

            foreach ($result as $weekly_basket) {
                $array_id_partners[] = $weekly_basket->getPartner()->getId();
            }
            $dql_partners .= ' and p not in (:ids) ';

        }

        if ($only_eggs)
        {
            $dql_partners.=" and b.egg_period=2 ";
        }


        $dql_partners .= ' ORDER BY  p.state, p.city asc';

        $query_partners = $em->createQuery($dql_partners);
        $query_partners->setParameter("basket_share", $basket);
        $query_partners->setParameter("status", $status_id);
        $query_partners->setParameter("date", $current_basket->getDate());



        if (count($result) > 0) {
            $query_partners->setParameter("ids", $array_id_partners);
        }

        return $query_partners->getResult();

    }

    /**
     * @param $current_basket
     * devuelve el id de la semana anterior a la que estamos en la que haya habido cesta
     */

    public function getPreviousBasket($current_basket_id)
    {
        $em = $this->getEntityManager();
        $dql = "select b.id from App:Basket b where b.id < :id order by b.id desc";
        $query = $em->createQuery($dql);
        $query->setParameter("id", $current_basket_id);
        $query->setMaxResults(1);

        return $query->getSingleScalarResult();
    }


    /**
     * @param $current_basket_id
     * @param $day_order Primer viernes, segundo viernes, etc del mes
     * devuelve los socios mensuales, que reciben 1 cesta al mes, según el orden de la semana en que reciben. Es decir, si
     *reciben el primer viernes, el segundo, etc...
     * * Ojo, aquí no se pasa el id sino el objeto basket entero
     * Directamente entiendo que se buscan solo los activos
     */
    public function findBasketPartnersMonthlyAndCity($current_basket,$basket, $day_order, $only_eggs=false)
    {
        $em = $this->getEntityManager();
        $dql = "select b from App:Basket b where YEAR(b.date)=:year and MONTH(b.date)=:month and b.id<>:current_basket_id";

        $query = $em->createQuery($dql);
        $query->setParameter("year", $current_basket->getDate()->format('Y'));
        $query->setParameter("month", $current_basket->getDate()->format('m'));
        $query->setParameter("current_basket_id", $current_basket->getId());

        $result = $query->getResult();
        $array_id_baskets = array(); //son las ids de las cestas del mismo mes que la actual
        $array_id_partners = array();//son las ids de los socios que ya han recogido la cesta
        if (count($result) > 0) {

            foreach ($result as $basket_result) {
                $array_id_baskets[] = $basket_result->getId();
            }
            $dql_partners = "select b from App:PartnerBasketShare b join App:WeeklyBasket w 
                where  b.partner=w.partner and b.basket_share=:basket_share and b.is_active=1 and w.basket in (:ids)";//busca los que ya han recogido la cesta en algun momento del mes
            $query_partners = $em->createQuery($dql_partners);
            $query_partners->setParameter("ids", $array_id_baskets);
            $query_partners->setParameter("basket_share", $basket);

            $result_partners = $query_partners->getResult();

            if (count($result_partners)) {

                foreach ($result_partners as $partner) {
                    $array_id_partners[] = $partner->getPartner()->getId();
                }
            }
        }

        $dql_final = "select b from App:PartnerBasketShare b inner join b.partner p where b.basket_share=:basket_share and
                      b.is_active=1 and b.day_month_order<=:day_order and b.start_date<=:date "; //pongo <= porque si alguien de la semana anterior no ha recogido y quiere recogerla esta, no aparece en el listado anterior (dql_partners) y así puede recogerla
        if (count($array_id_partners)) {
            $dql_final .= " and b.partner not in (:ids)";
        }

        if ($only_eggs)
        {
            $dql_final.=" and b.egg_period=3 ";
        }

        $dql_final .= " ORDER BY  p.state, p.city asc ";

        $query_final = $em->createQuery($dql_final);

        $query_final->setParameter("day_order", $day_order);
        $query_final->setParameter("basket_share", $basket);
        $query_final->setParameter("date", $current_basket->getDate());

        if (count($array_id_partners)) {
            $query_final->setParameter("ids", $array_id_partners);
        }

        return $query_final->getResult();
    }

    public function findFinalized()
    {
        $em = $this->getEntityManager();
        $dql = "Select b from App:PartnerBasketShare b where b.is_active=1 and b.end_date<:date";

        //busco la fecha de la siguiente entrega, viernes
        $monday = date('d F', strtotime(date('Y', strtotime(date('Y-m-d'))) . "W" . str_pad(date('W'), 2, "0", STR_PAD_LEFT)));
        //echo strtotime(date('Y', strtotime($entity->getProductionDate())) . "W" . str_pad($entity->getWeek(), 2, "0", STR_PAD_LEFT))."<br>";
        //echo date('Y', strtotime($entity->getProductionDate()));
        $friday = strtotime("+4 day", strtotime($monday));
        //echo $friday;
        $end_date = date("Y-m-d", $friday);
        $query = $em->createQuery($dql);
        $query->setParameter("date", $end_date);

        return $query->getResult();
    }




    public function deleteWeeklyBasket($basket_id)
    {
        $em = $this->getEntityManager();
        $dql="Delete from App:WeeklyBasket w where w.basket=:basket";
        $query=$em->createQuery($dql);
        $query->setParameter("basket",$basket_id);

        return $query->execute();
    }
}


