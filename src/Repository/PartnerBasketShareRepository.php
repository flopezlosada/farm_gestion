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
     * dada (o hoy si se omite) — start_date NULL o ≤ fecha, end_date NULL o ≥ fecha.
     * start_date NULL = sin fecha de alta conocida = activa desde siempre (no se
     * excluye: un socio sin fecha no debe desaparecer del reparto).
     * Si hay varias compatibles (raro pero posible en datos legacy), devuelve
     * la más reciente por start_date.
     */
    public function findActiveForPartner(\App\Entity\Partner $partner, ?\DateTimeInterface $on = null): ?PartnerBasketShare
    {
        $on ??= new \DateTimeImmutable('today');

        return $this->createQueryBuilder('s')
            ->where('s.partner = :partner')
            ->andWhere('s.start_date IS NULL OR s.start_date <= :on')
            ->andWhere('s.end_date IS NULL OR s.end_date >= :on')
            ->setParameter('partner', $partner)
            ->setParameter('on', $on->format('Y-m-d'))
            ->orderBy('s.start_date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Devuelve la lista de socios con cesta de un tipo (semanal, media, solo-huevo
     * semanal) ordenados por pueblo, candidatos a repartir en el Basket dado.
     *
     * Acota a la ventana de vigencia de la PBS frente a la FECHA del Basket:
     * start_date <= fecha y (end_date NULL o end_date >= fecha). El filtro de
     * end_date es imprescindible: un socio con baja programada a futuro sigue
     * is_active=1 hasta que finalizeExpiredShares la desactive (relativo a la
     * semana en curso, no al Basket que se genera), así que sin este filtro
     * recibiría entregas en listados adelantados POSTERIORES a su baja.
     *
     * @param $basket_share
     * @param $status
     */
    public function findBasketPartnersByTypeAndCity($basket_share, $status, $current_basket,$only_eggs=false)
    {
        $em = $this->getEntityManager();

        $dql = "select b from App\\Entity\\PartnerBasketShare b inner join b.partner p where b.basket_share=:basket_share and
                      b.is_active=:status and (b.start_date IS NULL OR b.start_date<=:date)
                      and (b.end_date IS NULL OR b.end_date>=:date) ";
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
                  and (b.start_date IS NULL OR b.start_date <= :date)
                  and (b.end_date IS NULL OR b.end_date >= :date)
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
                  and (b.start_date IS NULL OR b.start_date <= :date)
                  and (b.end_date IS NULL OR b.end_date >= :date)
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

        $dql_partners = "select b from App\\Entity\\PartnerBasketShare b inner join b.partner p where b.basket_share=:basket_share and b.is_active=:status and (b.start_date IS NULL OR b.start_date<=:date) and (b.end_date IS NULL OR b.end_date>=:date) ";
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
     * Socios mensuales activos node-aware. Atiende dos casos:
     *
     *  - Partners en nodos `weekly` o sin nodo asignado (datos legacy):
     *    filtra por `day_month_order = $weeklyMonthlyOrder` — el orden
     *    operativo entre las entregas del nodo weekly en el mes, resuelto
     *    fuera con {@see MonthlyOperativeOrderResolver::operativeOrderForNode}.
     *    Si el nodo weekly no entrega esta semana (excepción global de
     *    cancelación o de ese nodo), `$weeklyMonthlyOrder` viene null y la
     *    rama se omite.
     *  - Partners en nodos `biweekly` (Cascorro, Midori): se itera el mapa
     *    `nodeId => orderEnEseNodo`, donde el orden está resuelto fuera con
     *    {@see MonthlyOperativeOrderResolver::operativeOrderForNode} y sólo
     *    aparecen los nodos que sí entregan en este Basket.
     *
     * Se ejecutan 0..1 + N queries (N = número de nodos biweekly activos
     * esta semana, hoy ≤ 2). KISS: array_merge en PHP frente a un DQL con
     * pares (nodo, orden) correlacionados, que sería ilegible.
     *
     * Introducido en sub-fase 8.8b3 (2026-05-28); $weeklyMonthlyOrder
     * pasa a nullable en 8.8e (2026-05-28).
     *
     * @param mixed $current_basket Basket (firma laxa por coherencia con el resto del repo).
     * @param int $basket_share_id ID de BasketShare (3 mensual, 5 sólo huevos…).
     * @param int[] $weeklyMonthlyOrders Órdenes que el basket sirve en el nodo weekly
     *              (pegajoso, ver MonthlyOperativeOrderResolver::ordersServedBy);
     *              vacío si éste no entrega esta semana.
     * @param array<int,int[]> $biweeklyNodeIdToOrders Mapa nodeId → órdenes servidas en ese nodo.
     * @param bool $only_eggs Filtrar a egg_period=3 (sólo huevos mensuales).
     * @return PartnerBasketShare[]
     */
    public function findBasketPartnersMonthlyNodeAware(
        $current_basket,
        int $basket_share_id,
        array $weeklyMonthlyOrders,
        array $biweeklyNodeIdToOrders,
        bool $only_eggs = false
    ): array {
        $em = $this->getEntityManager();
        $eggPeriod = $only_eggs ? 3 : null;
        $results = [];

        if ($weeklyMonthlyOrders !== []) {
            $weeklyDql = "select b from App\\Entity\\PartnerBasketShare b
                          inner join b.partner p
                          left join p.weekly_basket_group wbg
                          left join wbg.node n
                          where b.basket_share = :basket_share
                            and b.is_active = 1
                            and (b.start_date IS NULL OR b.start_date <= :date)
                            and (b.end_date IS NULL OR b.end_date >= :date)
                            and b.day_month_order IN (:weekly_orders)
                            and (n.cadence = :cadence_weekly OR n.id IS NULL)";
            if ($eggPeriod !== null) {
                $weeklyDql .= " and b.egg_period = :egg_period ";
            }
            $weeklyDql .= " ORDER BY p.state, p.city asc";

            $weeklyQuery = $em->createQuery($weeklyDql);
            $weeklyQuery->setParameter("basket_share", $basket_share_id);
            $weeklyQuery->setParameter("date", $current_basket->getDate());
            $weeklyQuery->setParameter("weekly_orders", $weeklyMonthlyOrders);
            $weeklyQuery->setParameter("cadence_weekly", \App\Entity\Node::CADENCE_WEEKLY);
            if ($eggPeriod !== null) {
                $weeklyQuery->setParameter("egg_period", $eggPeriod);
            }

            $results = $weeklyQuery->getResult();
        }

        foreach ($biweeklyNodeIdToOrders as $nodeId => $ordersForNode) {
            if ($ordersForNode === []) {
                continue;
            }
            $biDql = "select b from App\\Entity\\PartnerBasketShare b
                      inner join b.partner p
                      inner join p.weekly_basket_group wbg
                      inner join wbg.node n
                      where b.basket_share = :basket_share
                        and b.is_active = 1
                        and (b.start_date IS NULL OR b.start_date <= :date)
                        and (b.end_date IS NULL OR b.end_date >= :date)
                        and b.day_month_order IN (:orders_for_node)
                        and n.id = :node_id";
            if ($eggPeriod !== null) {
                $biDql .= " and b.egg_period = :egg_period ";
            }
            $biDql .= " ORDER BY p.state, p.city asc";

            $biQuery = $em->createQuery($biDql);
            $biQuery->setParameter("basket_share", $basket_share_id);
            $biQuery->setParameter("date", $current_basket->getDate());
            $biQuery->setParameter("orders_for_node", $ordersForNode);
            $biQuery->setParameter("node_id", $nodeId);
            if ($eggPeriod !== null) {
                $biQuery->setParameter("egg_period", $eggPeriod);
            }

            $results = array_merge($results, $biQuery->getResult());
        }

        return $results;
    }

    /**
     * @param $current_basket_id
     * @param $day_order Primer viernes, segundo viernes, etc del mes
     * devuelve los socios mensuales, que reciben 1 cesta al mes, según el orden de la semana en que reciben. Es decir, si
     *reciben el primer viernes, el segundo, etc...
     * * Ojo, aquí no se pasa el id sino el objeto basket entero
     * Directamente entiendo que se buscan solo los activos
     *
     * LEGACY: sustituida por findBasketPartnersMonthlyNodeAware. Sólo la
     * sigue usando SendPickupReminderCommand (deuda apuntada, email
     * aparcado por copy). Eliminar cuando se modernice ese comando.
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
                      b.is_active=1 and b.day_month_order<=:day_order and (b.start_date IS NULL OR b.start_date<=:date) and (b.end_date IS NULL OR b.end_date>=:date) "; //pongo <= porque si alguien de la semana anterior no ha recogido y quiere recogerla esta, no aparece en el listado anterior (dql_partners) y así puede recogerla
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

    /**
     * PBS activas con huevos asignados (egg_amount + egg_period no null) que
     * estaban vigentes en la fecha del Basket. No filtra por modalidad de
     * cesta: incluye semanales, quincenales, mensuales y Only-Egg.
     *
     * Usado por WeeklyBasketGenerator para materializar entregas de huevo
     * sueltas cuando la PBS tiene egg_period más frecuente que la cesta
     * (ej. mensual+egg semanal): los viernes intermedios donde no toca
     * cesta pero sí huevo se cubren con un WB Only-Egg adicional.
     *
     * @param mixed $basket Basket (firma laxa, sólo se usa basket->getDate()).
     * @return PartnerBasketShare[]
     */
    public function findActiveSharesWithEggsForBasket($basket): array
    {
        $em = $this->getEntityManager();
        $dql = "SELECT b FROM App\\Entity\\PartnerBasketShare b
                INNER JOIN b.partner p
                WHERE b.is_active = 1
                  AND (b.start_date IS NULL OR b.start_date <= :date)
                  AND (b.end_date IS NULL OR b.end_date >= :date)
                  AND b.egg_amount IS NOT NULL
                  AND b.egg_period IS NOT NULL";
        return $em->createQuery($dql)
            ->setParameter('date', $basket->getDate())
            ->getResult();
    }
}


