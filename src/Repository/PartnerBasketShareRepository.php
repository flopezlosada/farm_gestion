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
     * Suscripción "activa hoy" de un socio: la que está en vigor en la fecha
     * dada (o hoy si se omite) — start_date ≤ fecha, end_date NULL o ≥ fecha.
     * Si hay varias compatibles (raro pero posible en datos legacy), devuelve
     * la más reciente por start_date.
     */
    public function findActiveForPartner(\App\Entity\Partner $partner, ?\DateTimeInterface $on = null): ?PartnerBasketShare
    {
        $on ??= new \DateTimeImmutable('today');

        return $this->createQueryBuilder('s')
            ->where('s.partner = :partner')
            ->andWhere('s.start_date <= :on')
            ->andWhere('s.end_date IS NULL OR s.end_date >= :on')
            ->setParameter('partner', $partner)
            ->setParameter('on', $on->format('Y-m-d'))
            ->orderBy('s.start_date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param $basket_share
     * @param $status
     * devuelve la lista de socios con cesta ordenados por pueblo y según el tipo de cesta
     */
    public function findBasketPartnersByTypeAndCity($basket_share, $status, $current_basket,$only_eggs=false)
    {
        $em = $this->getEntityManager();

        $dql = "select b from App\\Entity\\PartnerBasketShare b inner join b.partner p where b.basket_share=:basket_share and
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


    /**
     * Socios quincenales activos asignados a una cohorte concreta ('A' o 'B').
     * Sustituye la heurística vieja de "excluir a quien recogió la semana anterior"
     * por filtrado directo por PartnerBasketShare.delivery_group. La cohorte
     * que toca cada Basket la resuelve BiweeklyCohortResolver.
     *
     * Los PBS con delivery_group=NULL (patrones de reparto raros como "0,1,1,1")
     * quedan FUERA de cualquier cohorte y no se devuelven aquí; necesitan
     * tratamiento manual hasta modelarlos correctamente.
     *
     * @param string $cohort 'A' o 'B'.
     * @return PartnerBasketShare[]
     */
    public function findBasketPartnersBiweeklyByCohort(
        $current_basket,
        $basket_share_id,
        $status_id,
        string $cohort,
        bool $only_eggs = false
    ) {
        $em = $this->getEntityManager();

        $dql = "select b from App\\Entity\\PartnerBasketShare b
                inner join b.partner p
                where b.basket_share = :basket_share
                  and b.is_active = :status
                  and b.start_date <= :date
                  and b.delivery_group = :cohort";

        if ($only_eggs) {
            $dql .= " and b.egg_period = 2 ";
        }

        $dql .= " ORDER BY p.state, p.city asc";

        $query = $em->createQuery($dql);
        $query->setParameter("basket_share", $basket_share_id);
        $query->setParameter("status", $status_id);
        $query->setParameter("date", $current_basket->getDate());
        $query->setParameter("cohort", $cohort);

        return $query->getResult();
    }

    /**
     * Versión Node-aware de findBasketPartnersBiweeklyByCohort (sub-fase 8.8b2).
     *
     * Selecciona partners quincenales activos que recogen en este Basket
     * considerando dos casos:
     *  - Partners en nodos `weekly` (Torremocha): filtra por delivery_group
     *    igual a la cohorte global del Basket.
     *  - Partners en nodos `biweekly` (Cascorro, Midori): incluye todos
     *    los del nodo si el nodo reparte en este Basket (lista
     *    `$activeBiweeklyNodeIds`).
     *  - Partners sin nodo asignado (datos legacy): se asume Torremocha
     *    y se filtra por delivery_group como antes.
     *
     * @param int[] $activeBiweeklyNodeIds IDs de los Node biweekly que reparten en este Basket.
     * @return PartnerBasketShare[]
     */
    public function findBasketPartnersBiweeklyNodeAware(
        $current_basket,
        int $basket_share_id,
        int $status_id,
        string $cohort,
        array $activeBiweeklyNodeIds,
        bool $only_eggs = false
    ): array {
        $em = $this->getEntityManager();

        $dql = "select b from App\\Entity\\PartnerBasketShare b
                inner join b.partner p
                left join p.weekly_basket_group wbg
                left join wbg.node n
                where b.basket_share = :basket_share
                  and b.is_active = :status
                  and b.start_date <= :date
                  and (
                        (n.cadence = :cadence_weekly AND b.delivery_group = :cohort)
                     OR (n.id IS NULL AND b.delivery_group = :cohort)";

        if (!empty($activeBiweeklyNodeIds)) {
            $dql .= " OR n.id IN (:biweekly_nodes)";
        }

        $dql .= "      )";

        if ($only_eggs) {
            $dql .= " and b.egg_period = 2 ";
        }

        $dql .= " ORDER BY p.state, p.city asc";

        $query = $em->createQuery($dql);
        $query->setParameter("basket_share", $basket_share_id);
        $query->setParameter("status", $status_id);
        $query->setParameter("date", $current_basket->getDate());
        $query->setParameter("cohort", $cohort);
        $query->setParameter("cadence_weekly", \App\Entity\Node::CADENCE_WEEKLY);
        if (!empty($activeBiweeklyNodeIds)) {
            $query->setParameter("biweekly_nodes", $activeBiweeklyNodeIds);
        }

        return $query->getResult();
    }

    /*
     * LEGACY: socios quincenales. Buscaba que no estén en la semana anterior.
     * Sustituida por findBasketPartnersBiweeklyByCohort. Se mantiene temporalmente
     * para no romper callers fuera del WeeklyBasketGenerator si los hubiera;
     * eliminar cuando todas las llamadas hayan migrado.
     */
    public function findBasketPartnersBiweeklyAndCity($current_basket,$basket, $status_id, $only_eggs=false)
    {

        $em = $this->getEntityManager();

        $dql = "select b from App\\Entity\\WeeklyBasket b where b.basket=:basket";
        $query = $em->createQuery($dql);

        $query->setParameter("basket", $this->getPreviousBasket($current_basket->getId()));

        $result = $query->getResult();
        $array_id_partners = array(); //es el array con las ids de los socios que recibieron la semana anterior. Habrá txdo tipo de socios, semanales, mensuales,...

        $dql_partners = "select b from App\\Entity\\PartnerBasketShare b inner join b.partner p where b.basket_share=:basket_share and b.is_active=:status and b.start_date<=:date ";
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
        $dql = "select b.id from App\\Entity\\Basket b where b.id < :id order by b.id desc";
        $query = $em->createQuery($dql);
        $query->setParameter("id", $current_basket_id);
        $query->setMaxResults(1);

        return $query->getSingleScalarResult();
    }


    /**
     * Socios mensuales activos cuyo day_month_order coincide con el orden
     * operativo del viernes recibido. Sustituye la heurística vieja
     * findBasketPartnersMonthlyAndCity (que dependía de weekly_basket para
     * excluir a quien ya había recogido, devolviendo 0 cuando esa tabla
     * estaba vacía).
     *
     * El operative_order es 1..N donde N son los viernes hábiles del mes
     * (excluyendo festivos). Se resuelve fuera vía MonthlyOperativeOrderResolver.
     *
     * @return PartnerBasketShare[]
     */
    public function findBasketPartnersMonthlyByOperativeOrder(
        $current_basket,
        $basket_share_id,
        int $operative_order,
        bool $only_eggs = false
    ) {
        $em = $this->getEntityManager();

        $dql = "select b from App\\Entity\\PartnerBasketShare b
                inner join b.partner p
                where b.basket_share = :basket_share
                  and b.is_active = 1
                  and b.day_month_order = :operative_order
                  and b.start_date <= :date";

        if ($only_eggs) {
            $dql .= " and b.egg_period = 3 ";
        }

        $dql .= " ORDER BY p.state, p.city asc";

        $query = $em->createQuery($dql);
        $query->setParameter("basket_share", $basket_share_id);
        $query->setParameter("operative_order", $operative_order);
        $query->setParameter("date", $current_basket->getDate());

        return $query->getResult();
    }

    /**
     * @param $current_basket_id
     * @param $day_order Primer viernes, segundo viernes, etc del mes
     * devuelve los socios mensuales, que reciben 1 cesta al mes, según el orden de la semana en que reciben. Es decir, si
     *reciben el primer viernes, el segundo, etc...
     * * Ojo, aquí no se pasa el id sino el objeto basket entero
     * Directamente entiendo que se buscan solo los activos
     *
     * LEGACY: sustituida por findBasketPartnersMonthlyByOperativeOrder. Se
     * conserva temporalmente hasta confirmar que no quedan callers.
     */
    public function findBasketPartnersMonthlyAndCity($current_basket,$basket, $day_order, $only_eggs=false)
    {
        $em = $this->getEntityManager();
        $dql = "select b from App\\Entity\\Basket b where YEAR(b.date)=:year and MONTH(b.date)=:month and b.id<>:current_basket_id";

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
            $dql_partners = "select b from App\\Entity\\PartnerBasketShare b join App\\Entity\\WeeklyBasket w 
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

        $dql_final = "select b from App\\Entity\\PartnerBasketShare b inner join b.partner p where b.basket_share=:basket_share and
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
        $dql = "Select b from App\\Entity\\PartnerBasketShare b where b.is_active=1 and b.end_date<:date";

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
        $dql="Delete from App\\Entity\\WeeklyBasket w where w.basket=:basket";
        $query=$em->createQuery($dql);
        $query->setParameter("basket",$basket_id);

        return $query->execute();
    }
}


