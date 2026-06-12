<?php

namespace App\Controller;

use App\Entity\Basket;
use App\Entity\Blog;
use App\Entity\Booking;
use App\Entity\CompostCollection;
use App\Entity\CropWorking;
use App\Entity\Lay;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\Production;
use App\Entity\User;
use App\Service\Delivery\NodeDeliveryCounter;
use App\Service\Delivery\NodeDeliveryDate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DefaultController extends AbstractController
{
    /** Producto de pienso de gallinas: ancla el lote activo de puesta. */
    private const PRODUCT_HENS = 6;
    /** Producto de pollos de carne: ancla el lote activo de cría. */
    private const PRODUCT_CHICKENS = 2;

    #[Route("/calendar", name: "calendar")]
    public function calendar(): Response
    {
        return $this->render('Default/calendar.html.twig');
    }

    /**
     * Panel de control por roles: cada dominio sólo se calcula (y se pinta) si
     * el usuario tiene su rol. Un gestor de reparto no dispara ni una query de
     * granja, y un admin —que los tiene todos— ve las cinco secciones. Es el
     * mismo principio que ya usa el menú lateral, llevado al dashboard, en vez
     * del bloque único de granja que veía todo el mundo.
     */
    #[Route("/dashboard", name: "dashboard")]
    public function dashboard(
        EntityManagerInterface $em,
        NodeDeliveryDate $nodeDeliveryDate,
        NodeDeliveryCounter $deliveryCounter,
    ): Response {
        $dash = [];

        if ($this->isGranted('ROLE_GESTION_REPARTO')) {
            $dash['reparto'] = $this->repartoSummary($em, $nodeDeliveryDate, $deliveryCounter);
        }
        if ($this->isGranted('ROLE_GESTION_SOCIXS')) {
            $dash['socios'] = $this->sociosSummary($em);
        }
        if ($this->isGranted('ROLE_GESTION_GRANJA')) {
            $dash['granja'] = $this->granjaSummary($em);
        }
        if ($this->isGranted('ROLE_BLOG')) {
            $dash['comunicacion'] = $this->comunicacionSummary($em);
        }
        if ($this->isGranted('ROLE_ADMIN')) {
            $dash['admin'] = $this->adminSummary($em);
        }

        return $this->render('Default/dashboard.html.twig', ['dash' => $dash]);
    }

    /**
     * Resumen de reparto de la SEMANA EN CURSO, por nodo. Se ancla al Basket
     * (viernes-ciclo) de esta semana ISO; cada nodo reparte su propio día
     * (Torremocha el viernes, Madrid el miércoles…), pero todos cuelgan del
     * mismo ciclo semanal.
     *
     * Cuenta con la MISMA decisión piedra-vs-dibujo que el listado de reparto,
     * vía {@see NodeDeliveryCounter}: si la semana aún no se generó, el nodo cae
     * en DRAW y se DIBUJA (cifras reales), igual que en la tabla; no sale "0" ni
     * un falso "pendiente". Un nodo ausente del conteo (modo EMPTY) es un nodo
     * que no reparte esta semana (quincenal fuera de fase o cierre).
     *
     * @return array{
     *     basket: ?Basket,
     *     nodes: list<array{node: Node, delivers: bool, cestas: float, docenas: float, date: ?\DateTimeInterface}>,
     *     total_cestas: float,
     *     total_docenas: float,
     *     changes: list<array{shift: \App\Entity\PartnerDeliveryShift, incoming: bool}>
     * }
     */
    private function repartoSummary(
        EntityManagerInterface $em,
        NodeDeliveryDate $nodeDeliveryDate,
        NodeDeliveryCounter $deliveryCounter,
    ): array {
        $basketRepo = $em->getRepository(Basket::class);

        // Basket de la semana ISO en curso: el viernes-ciclo cuya fecha cae
        // entre el lunes y el domingo de esta semana.
        $weekStart = new \DateTimeImmutable('monday this week');
        $weekEnd = new \DateTimeImmutable('sunday this week');
        $basket = $basketRepo->createQueryBuilder('b')
            ->where('b.date BETWEEN :a AND :b')
            ->setParameter('a', $weekStart->format('Y-m-d'))
            ->setParameter('b', $weekEnd->format('Y-m-d'))
            ->orderBy('b.date', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$basket instanceof Basket) {
            return ['basket' => null, 'nodes' => [], 'total_cestas' => 0.0, 'total_docenas' => 0.0, 'changes' => []];
        }

        $allNodes = $em->getRepository(Node::class)->findBy([], ['name' => 'ASC']);
        $counts = $deliveryCounter->countsByNode($allNodes, $basket);

        $nodes = [];
        $totalCestas = 0.0;
        $totalDocenas = 0.0;
        foreach ($allNodes as $node) {
            $c = $counts[$node->getId()] ?? null;
            $totalCestas += $c['cestas'] ?? 0.0;
            $totalDocenas += $c['docenas'] ?? 0.0;
            $nodes[] = [
                'node' => $node,
                'delivers' => $c !== null,
                'cestas' => $c['cestas'] ?? 0.0,
                'docenas' => $c['docenas'] ?? 0.0,
                'date' => $c !== null ? $nodeDeliveryDate->physicalDateFor($basket, $node) : null,
            ];
        }

        // Cambios puntuales que afectan a la semana en curso: los que SALEN
        // (no recoge / cambia de día / cambio de componente) y los que ENTRAN
        // (recogen aquí por un cambio). Más útil que las excepciones de
        // calendario, que están vacías casi todo el año.
        $shiftRepo = $em->getRepository(\App\Entity\PartnerDeliveryShift::class);
        $changes = [];
        foreach ($shiftRepo->findAllOutgoingFromBasket($basket) as $shift) {
            $changes[] = ['shift' => $shift, 'incoming' => false];
        }
        foreach ($shiftRepo->findAllIncomingToBasket($basket) as $shift) {
            $changes[] = ['shift' => $shift, 'incoming' => true];
        }

        return [
            'basket' => $basket,
            'nodes' => $nodes,
            'total_cestas' => $totalCestas,
            'total_docenas' => $totalDocenas,
            'changes' => $changes,
        ];
    }

    /**
     * Resumen de socios: activos totales, tendencia de altas/bajas del mes y
     * avisos accionables (activos sin cesta y sin grupo de recogida).
     *
     * @return array{active: int, this_month: array, no_basket: int, no_group: int}
     */
    private function sociosSummary(EntityManagerInterface $em): array
    {
        $partnerRepo = $em->getRepository(Partner::class);
        $months = $partnerRepo->countMonthlyJoinsAndLeaves(3);

        return [
            'active' => $partnerRepo->countActive(),
            // Último elemento de la serie = mes en curso (joins/leaves/net/label).
            'this_month' => end($months) ?: ['joins' => 0, 'leaves' => 0, 'net' => 0, 'label' => ''],
            'no_basket' => count($partnerRepo->findActiveHasNoBasket()),
            'no_group' => count($partnerRepo->findActiveWithoutGroup()),
        ];
    }

    /**
     * Resumen de granja: producción del año, cultivos en producción y compost.
     * Las gallinas y la puesta sólo se calculan (y se pintan) si hay lotes
     * activos; con la granja sin aves, esos KPIs desaparecen en vez de enseñar
     * ceros. Los pollos, igual.
     *
     * @return array{
     *     year: int, production: float|string, crops: int, compost: float|string,
     *     hens: array, chickens: array, lay_week: int, total_lay_eggs: float|string|null, lay_series: array
     * }
     */
    private function granjaSummary(EntityManagerInterface $em): array
    {
        $year = (int) date('Y');
        $batchRepo = $em->getRepository(\App\Entity\Batch::class);
        $layRepo = $em->getRepository(Lay::class);

        $hens = $batchRepo->getActiveBatch(self::PRODUCT_HENS) ?? [];
        $chickens = $batchRepo->getActiveBatch(self::PRODUCT_CHICKENS) ?? [];

        $layWeek = 0;
        $totalLayEggs = null;
        $laySeries = [];
        if ($hens !== []) {
            $layWeek = (int) $layRepo->findByWeek((int) date('W'), $year);
            $totalLayEggs = $layRepo->findTotalEggs($year);
            $laySeries = array_reverse($layRepo->findWeekLay(10));
            // Puesta semanal por lote, para la métrica de cada card.
            foreach ($hens as $batch) {
                $weekBatchLay = $layRepo->findLayInWeekYear((int) date('W'), $year, $batch->getId());
                $batch->setWeekLay(!empty($weekBatchLay['total']) ? $weekBatchLay['total'] : 0);
            }
        }

        return [
            'year' => $year,
            'production' => $em->getRepository(Production::class)->findByYear($year),
            'crops' => count($em->getRepository(CropWorking::class)->findActive()),
            'compost' => $em->getRepository(CompostCollection::class)->findTotalAmountCollection($year),
            'hens' => $hens,
            'chickens' => $chickens,
            'lay_week' => $layWeek,
            'total_lay_eggs' => $totalLayEggs,
            'lay_series' => $laySeries,
        ];
    }

    /**
     * Resumen de comunicación: últimas entradas del blog y próximos eventos
     * públicos. findLatest se llama con offset 0 (la home pública lo usa con
     * offset 1 para no repetir el destacado; aquí queremos incluir la última).
     *
     * @return array{posts: Blog[], events: Booking[]}
     */
    private function comunicacionSummary(EntityManagerInterface $em): array
    {
        return [
            'posts' => $em->getRepository(Blog::class)->findLatest(5, 0),
            'events' => $em->getRepository(Booking::class)->findUpcoming(5),
        ];
    }

    /**
     * Resumen de administración: el dashboard del admin es consecuencia de SUS
     * permisos. Hoy su superficie propia es usuarias y configuración (ambas
     * gateadas por ROLE_ADMIN, sin rol dedicado todavía — eso es la tarea 8.7).
     * Se queda ligero a propósito: atajos a sus acciones + nº de usuarias.
     *
     * @return array{users: int}
     */
    private function adminSummary(EntityManagerInterface $em): array
    {
        return ['users' => $em->getRepository(User::class)->count([])];
    }

    /**
     * Total de kg de verdura producidos en un año (por defecto el actual). Lo
     * consume también la composición de cestas ({@see Production/basket.html.twig}),
     * de ahí que siga siendo un sub-action renderizable y no se haya plegado al
     * dashboard.
     */
    public function totalProductionYear(EntityManagerInterface $em, $year = null)
    {
        if (!$year) {
            $year = date('Y');
        }
        $production = $em->getRepository(Production::class)->findByYear($year);

        return new Response($production);
    }
}
