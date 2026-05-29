<?php

namespace App\Repository;

use App\Entity\Basket;
use App\Entity\Node;
use App\Entity\Partner;
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

        $dql = "select w from App\\Entity\\WeeklyBasket w inner join w.partner p where w.basket_share<>:basket_share and
                      w.basket=:basket order by w.weekly_basket_group,p.name,p.surname";

        $query = $em->createQuery($dql);
        $query->setParameter("basket_share", $basket_share);
        $query->setParameter("basket", $basket);


        return $query->getResult();
    }

    /**
     * Próxima WeeklyBasket de un Partner: la asignada al primer Basket con
     * fecha >= hoy. Si el socix ya recogió todas las cestas pendientes
     * o aún no tiene asignación, devuelve null.
     *
     * Usado por el panel del socix para saber sobre qué cesta operar
     * cuando pide saltar la próxima o cambiar de nodo puntualmente.
     */
    public function findNextForPartner(Partner $partner): ?WeeklyBasket
    {
        return $this->createQueryBuilder('wb')
            ->innerJoin('wb.basket', 'b')
            ->where('wb.partner = :partner')
            ->andWhere('b.date >= :today')
            ->setParameter('partner', $partner)
            ->setParameter('today', (new \DateTimeImmutable('today'))->format('Y-m-d'))
            ->orderBy('b.date', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Listado para la pantalla de reparto v2 (sub-fase 8.8c): WeeklyBasket
     * que recoge un Nodo en un Basket dado. Solo cestas con status 1
     * (activa / "recoge"). Ordenado por WBG.name → basket_share.id →
     * partner.name para que la agrupación por sección en el render sea
     * mecánica.
     *
     * Devuelve la lista plana; el caller hace el group_by por WBG y por
     * modalidad. Pequeño volumen (64 max por Nodo en datos reales),
     * sin paginación.
     *
     * @return WeeklyBasket[]
     */
    public function findForNodeAndBasket(Node $node, Basket $basket): array
    {
        return $this->createQueryBuilder('wb')
            ->innerJoin('wb.weekly_basket_group', 'wbg')
            ->innerJoin('wb.partner', 'p')
            ->innerJoin('wb.basket_share', 'bs')
            ->where('wbg.node = :node')
            ->andWhere('wb.basket = :basket')
            ->andWhere('wb.weekly_basket_status = :status')
            ->setParameter('node', $node)
            ->setParameter('basket', $basket)
            ->setParameter('status', 1)
            ->orderBy('wbg.name', 'ASC')
            ->addOrderBy('bs.id', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Conteo de reparto por nodo para un Basket concreto, con el mismo
     * criterio que {@see findForNodeAndBasket} (status_id = 1). Sirve para
     * que las pestañas de nodo de la pantalla v2 muestren cuántos grupos y
     * socios reparten ESE día, en vez del total de WBG asignados al nodo
     * (que es engañoso: un nodo quincenal puede tener 0 reparto ese día).
     *
     * Una sola query agregada para todos los nodos. Los nodos sin reparto
     * ese día no aparecen en el resultado: el llamante debe asumir 0.
     *
     * @return array<int, array{wbg:int, socios:int}> indexado por node_id
     */
    public function countActiveByNodeForBasket(Basket $basket): array
    {
        $rows = $this->createQueryBuilder('wb')
            ->select('IDENTITY(wbg.node) AS node_id', 'COUNT(DISTINCT wbg.id) AS wbg_count', 'COUNT(wb.id) AS socios')
            ->innerJoin('wb.weekly_basket_group', 'wbg')
            ->where('wb.basket = :basket')
            ->andWhere('wb.weekly_basket_status = :status')
            ->andWhere('wbg.node IS NOT NULL')
            ->setParameter('basket', $basket)
            ->setParameter('status', 1)
            ->groupBy('wbg.node')
            ->getQuery()
            ->getArrayResult();

        $byNode = [];
        foreach ($rows as $row) {
            $byNode[(int) $row['node_id']] = [
                'wbg' => (int) $row['wbg_count'],
                'socios' => (int) $row['socios'],
            ];
        }
        return $byNode;
    }

    /**
     * Historial de repartos ya generados de un nodo, del más reciente al
     * más antiguo. Por cada Basket en el que el nodo tiene listado, devuelve
     * el resumen de lo que se estimó repartir: nº de socios y nº de cestas
     * físicas ponderado por modalidad (solo-huevos 0, compartidas ½, resto 1
     * — mismo criterio que DeliveryController::computeTotals).
     *
     * Cuenta TODOS los WeeklyBasket generados (cualquier status), porque la
     * estimación es la que se hizo al generar el listado, no lo finalmente
     * recogido. Una sola query agregada. Solo aparecen semanas ya generadas.
     *
     * @param Node $node
     * @param int $limit Máximo de repartos a devolver.
     * @return array<int, array{basket: Basket, socios: int, cestas: float}>
     */
    public function deliveredHistoryForNode(Node $node, int $limit = 4): array
    {
        $rows = $this->createQueryBuilder('wb')
            ->select(
                'IDENTITY(wb.basket) AS basket_id',
                'COUNT(wb.id) AS socios',
                'SUM(CASE WHEN bs.id = 5 THEN 0 WHEN (bs.id = 4 OR bs.id = 6 OR bs.id = 7) THEN 0.5 ELSE 1 END) AS cestas'
            )
            ->innerJoin('wb.weekly_basket_group', 'wbg')
            ->innerJoin('wb.basket', 'b')
            ->leftJoin('wb.basket_share', 'bs')
            ->where('wbg.node = :node')
            ->andWhere('b.date < :today')
            ->setParameter('node', $node)
            ->setParameter('today', (new \DateTimeImmutable('today'))->format('Y-m-d'))
            ->groupBy('wb.basket')
            ->orderBy('b.date', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        if ($rows === []) {
            return [];
        }

        // Las entidades Basket no se pueden seleccionar en la misma query
        // agregada (no es el alias raíz); se cargan aparte y se reindexan.
        $baskets = [];
        foreach ($this->getEntityManager()->getRepository(Basket::class)
                     ->findBy(['id' => array_map(static fn (array $r): int => (int) $r['basket_id'], $rows)]) as $basket) {
            $baskets[$basket->getId()] = $basket;
        }

        $history = [];
        foreach ($rows as $row) {
            $id = (int) $row['basket_id'];
            if (!isset($baskets[$id])) {
                continue;
            }
            $history[] = [
                'basket' => $baskets[$id],
                'socios' => (int) $row['socios'],
                'cestas' => (float) $row['cestas'],
            ];
        }

        return $history;
    }

    /**
     * Número de cestas a repartir en un Basket: cuenta las WeeklyBasket
     * cuyo status indica que se recogen (status_id = 1). Las marcadas como
     * "no recoge" (status 2) no se cuentan porque no llegan a salir.
     *
     * Métrica usada por la regla de equilibrio (BalanceWithinThresholdRule):
     * compara el conteo entre viernes consecutivos para evitar picos.
     */
    public function countPickedInBasket(\App\Entity\Basket $basket): int
    {
        return (int) $this->createQueryBuilder('wb')
            ->select('COUNT(wb.id)')
            ->where('wb.basket = :basket')
            ->andWhere('wb.weekly_basket_status = :status')
            ->setParameter('basket', $basket)
            ->setParameter('status', 1)
            ->getQuery()
            ->getSingleScalarResult();
    }
}