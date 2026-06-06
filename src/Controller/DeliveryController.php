<?php

namespace App\Controller;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerEvent;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketStatus;
use App\Repository\BasketRepository;
use App\Repository\DeliveryExceptionRepository;
use App\Repository\NodeRepository;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Repository\PartnerRepository;
use App\Repository\PartnerBasketShareRepository;
use App\Repository\WeeklyBasketItemRepository;
use App\Repository\WeeklyBasketRepository;
use App\Repository\WeeklyBasketStatusRepository;
use App\Service\Delivery\DeliveryShiftApplier;
use App\Service\Delivery\DeliveryShiftValidator;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Delivery\NodeDeliverySheet;
use App\Service\Delivery\WeeklyBasketGenerator;
use App\Service\Delivery\WeeklyDeliveryReport;
use Dompdf\Dompdf;
use Dompdf\Options;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Vista detallada del reparto de un viernes concreto. Se accede desde el
 * calendario interno (pinchando en un evento "Reparto · cesta #N") y deja
 * ver el listado completo con búsqueda, orden por nombre/nodo/grupo y la
 * marca de excepción de calendario si la hay.
 *
 * Bajo el mismo prefijo se cuelga el registro de cambios de reparto
 * (DeliveryShift): una pantalla de CONSULTA por semana que lista los
 * cambios de día y los "no recoge" y permite deshacerlos. El ALTA de
 * cambios ya no vive aquí —se hace en el calendario de recogida de la
 * ficha del socix (partner_delivery_calendar)—.
 */
#[Route('/gestion/reparto')]
#[IsGranted('ROLE_GESTION_SOCIXS')]
class DeliveryController extends AbstractController
{
    /** Nº de semanas (Baskets) visibles en la tira selectora del registro. */
    private const WEEK_STRIP_SIZE = 7;

    /**
     * Registro de cambios de reparto de una semana concreta (Basket): los
     * cambios de día (DeliveryShift) y los "no recoge" sin cambio de día.
     * NO registra cambios nuevos —eso vive en el calendario de recogida de
     * la ficha del socix (partner_delivery_calendar)—; es una pantalla de
     * consulta. La semana se elige con la tira de chips superior (?basket).
     *
     * El filtro es por el viernes-ciclo ORIGEN ("le tocaba"): un cambio
     * 5-jun→26-jun aparece en la semana del 5. El "Deshacer" solo se ofrece
     * en semanas futuras (un viernes pasado ya no se deshace).
     */
    #[Route('/historial-cambios', name: 'delivery_shifts_index', methods: ['GET'])]
    public function shiftsIndex(
        Request $request,
        PartnerDeliveryShiftRepository $shiftRepo,
        BasketRepository $basketRepo,
        WeeklyBasketRepository $weeklyBasketRepo,
        DeliveryExceptionRepository $exceptionRepo,
    ): Response {
        $today = new \DateTimeImmutable('today');

        // Semana seleccionada: ?basket=ID, o la próxima (date >= hoy), o la
        // última pasada si todas pasaron, para no quedarnos sin nada que pintar.
        $selected = $basketRepo->find($request->query->getInt('basket', 0))
            ?? $basketRepo->createQueryBuilder('b')
                ->where('b.date >= :today')->setParameter('today', $today->format('Y-m-d'))
                ->orderBy('b.date', 'ASC')->setMaxResults(1)->getQuery()->getOneOrNullResult()
            ?? $basketRepo->createQueryBuilder('b')
                ->orderBy('b.date', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();

        if (!$selected instanceof Basket) {
            throw $this->createNotFoundException('No hay semanas de reparto configuradas.');
        }

        // Cambios de día cuyo ORIGEN es la semana seleccionada.
        $shifts = $shiftRepo->createQueryBuilder('s')
            ->innerJoin('s.partner', 'p')
            ->where('s.fromBasket = :basket')
            ->setParameter('basket', $selected)
            ->orderBy('p.surname', 'ASC')->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        // "No recoge esta semana" (WeeklyBasket en status=2) que no son cascada
        // de un shift: si la WB está en status 2 porque la origina un
        // PartnerDeliveryShift, el shift ya aparece arriba y meter la WB
        // duplicaría.
        $skips = $weeklyBasketRepo->createQueryBuilder('wb')
            ->innerJoin('wb.partner', 'p')
            ->where('wb.basket = :basket')
            ->andWhere('wb.weekly_basket_status = :statusNoRecoge')
            ->andWhere('NOT EXISTS (
                SELECT 1 FROM \App\Entity\PartnerDeliveryShift s2
                WHERE s2.partner = wb.partner AND s2.fromBasket = wb.basket
            )')
            ->setParameter('basket', $selected)
            ->setParameter('statusNoRecoge', 2)
            ->orderBy('p.surname', 'ASC')->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        // Cierre de la semana: excepción global (todos los nodos) sin fecha de
        // traslado. Da contexto al registro —los cambios de esa semana suelen
        // ser consecuencia del cierre—.
        $globalException = $exceptionRepo->findGlobalForBasket($selected);
        $closure = ($globalException !== null && $globalException->isCancelled()) ? $globalException : null;

        return $this->render('delivery/shifts_index.html.twig', [
            'selected' => $selected,
            'is_future' => $selected->getDate() !== null && $selected->getDate() >= $today,
            'closure' => $closure,
            'week_strip' => $this->buildWeekStrip($basketRepo, $shiftRepo, $weeklyBasketRepo, $exceptionRepo, $selected, $today),
            'shifts' => $shifts,
            'skips' => $skips,
        ]);
    }

    /**
     * Tira de chips de semanas (Baskets) centrada en la seleccionada, con un
     * marcador en las que tienen algún cambio o salto registrado, para que el
     * registro se escanee de un vistazo. A diferencia de la mini-timeline de
     * reparto (por nodo, con fecha física), esta es global por viernes-ciclo.
     *
     * @return array{weeks: list<array{basket:Basket, active:bool, past:bool, has_activity:bool, closed:bool}>, prev_id:?int, next_id:?int}
     */
    private function buildWeekStrip(
        BasketRepository $basketRepo,
        PartnerDeliveryShiftRepository $shiftRepo,
        WeeklyBasketRepository $weeklyBasketRepo,
        DeliveryExceptionRepository $exceptionRepo,
        Basket $selected,
        \DateTimeImmutable $today,
    ): array {
        $selectedDate = $selected->getDate()->format('Y-m-d');
        $half = intdiv(self::WEEK_STRIP_SIZE - 1, 2);

        $previous = $basketRepo->createQueryBuilder('b')
            ->where('b.date < :date')->setParameter('date', $selectedDate)
            ->orderBy('b.date', 'DESC')->setMaxResults($half)
            ->getQuery()->getResult();
        $next = $basketRepo->createQueryBuilder('b')
            ->where('b.date > :date')->setParameter('date', $selectedDate)
            ->orderBy('b.date', 'ASC')->setMaxResults(self::WEEK_STRIP_SIZE - 1 - count($previous))
            ->getQuery()->getResult();

        /** @var Basket[] $window */
        $window = [...array_reverse($previous), $selected, ...$next];
        $ids = array_map(static fn (Basket $b): int => $b->getId(), $window);

        // Semanas con actividad: origen de algún shift o algún "no recoge".
        $withShift = array_column($shiftRepo->createQueryBuilder('s')
            ->select('DISTINCT IDENTITY(s.fromBasket) AS bid')
            ->where('s.fromBasket IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->getScalarResult(), 'bid');
        $withSkip = array_column($weeklyBasketRepo->createQueryBuilder('wb')
            ->select('DISTINCT IDENTITY(wb.basket) AS bid')
            ->where('wb.basket IN (:ids)')
            ->andWhere('wb.weekly_basket_status = :s2')->setParameter('s2', 2)
            ->setParameter('ids', $ids)
            ->getQuery()->getScalarResult(), 'bid');
        $active = array_flip(array_map('intval', [...$withShift, ...$withSkip]));

        // Semanas cerradas: excepción global (node IS NULL) de cierre (sin
        // fecha de traslado) — para pintarlas distintas en la tira.
        $closedIds = array_column($exceptionRepo->createQueryBuilder('e')
            ->select('DISTINCT IDENTITY(e.basket) AS bid')
            ->where('e.basket IN (:ids)')
            ->andWhere('e.node IS NULL')
            ->andWhere('e.shiftedDate IS NULL')
            ->setParameter('ids', $ids)
            ->getQuery()->getScalarResult(), 'bid');
        $closed = array_flip(array_map('intval', $closedIds));

        $weeks = array_map(
            static fn (Basket $b): array => [
                'basket' => $b,
                'active' => $b->getId() === $selected->getId(),
                'past' => $b->getDate() !== null && $b->getDate() < $today,
                'has_activity' => isset($active[$b->getId()]),
                'closed' => isset($closed[$b->getId()]),
            ],
            $window,
        );

        $prevId = $basketRepo->createQueryBuilder('b')
            ->where('b.date < :date')->setParameter('date', $window[0]->getDate()->format('Y-m-d'))
            ->orderBy('b.date', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult()?->getId();
        $lastDate = end($window)->getDate()->format('Y-m-d');
        $nextId = $basketRepo->createQueryBuilder('b')
            ->where('b.date > :date')->setParameter('date', $lastDate)
            ->orderBy('b.date', 'ASC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult()?->getId();

        return ['weeks' => $weeks, 'prev_id' => $prevId, 'next_id' => $nextId];
    }

    /**
     * Toggle de "no recoge" sobre la WeeklyBasket de un socix en una semana
     * (Basket) concreta: cambia el status entre 1 (recoge) y 2 (no recoge),
     * con guarda anti-shift (si hay shift activo, no se permite el toggle:
     * desincronizaría la cascada) y de deadline (jueves 23:59: el viernes ya
     * se está cosechando). Desde el registro sólo se usa para deshacer un
     * salto (2→1), pero el toggle se mantiene genérico.
     */
    #[Route('/historial-cambios/skip', name: 'delivery_shifts_skip_toggle', methods: ['POST'])]
    public function shiftsSkipToggle(
        Request $request,
        PartnerRepository $partnerRepo,
        BasketRepository $basketRepo,
        WeeklyBasketRepository $weeklyBasketRepo,
        WeeklyBasketStatusRepository $statusRepo,
        PartnerDeliveryShiftRepository $shiftRepo,
        EntityManagerInterface $em,
    ): Response {
        $partner = $partnerRepo->find((int) $request->request->get('partner_id'));
        $basket = $basketRepo->find((int) $request->request->get('basket_id'));
        $backToWeek = static fn (): array => $basket instanceof Basket ? ['basket' => $basket->getId()] : [];

        if (!$this->isCsrfTokenValid('delivery_shift_skip', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido.');
            return $this->redirectToRoute('delivery_shifts_index', $backToWeek());
        }

        if (!$partner instanceof Partner || !$basket instanceof Basket) {
            $this->addFlash('error', 'Faltan datos: socix o semana sobre la que actuar.');
            return $this->redirectToRoute('delivery_shifts_index', $backToWeek());
        }

        $wb = $weeklyBasketRepo->findOneBy(['partner' => $partner, 'basket' => $basket]);
        if ($wb === null) {
            $this->addFlash('warning', 'Este socix no tiene cesta esa semana sobre la que actuar.');
            return $this->redirectToRoute('delivery_shifts_index', $backToWeek());
        }

        if ($shiftRepo->findOutgoing($partner, $basket) !== null) {
            $this->addFlash('warning', 'Este socix tiene un cambio de día activo esa semana: cancélalo primero.');
            return $this->redirectToRoute('delivery_shifts_index', $backToWeek());
        }

        if (!$this->isWithinPickupDeadline($basket)) {
            $this->addFlash('error', 'El plazo para esa semana ya terminó (jueves 23:59). No se puede deshacer.');
            return $this->redirectToRoute('delivery_shifts_index', $backToWeek());
        }

        $currentId = $wb->getWeeklyBasketStatus()?->getId();
        $partnerName = trim(($partner->getName() ?? '') . ' ' . ($partner->getSurname() ?? ''));
        $actor = 'gestor:' . $this->getUser()->getId();

        if ($currentId === 1) {
            $wb->setWeeklyBasketStatus($statusRepo->find(2));
            $event = new PartnerEvent($partner, PartnerEvent::TYPE_BASKET_SKIP);
            $event->setActor($actor);
            $em->persist($event);
            $this->addFlash('notice', sprintf('%s no recoge la cesta de esa semana.', $partnerName));
        } elseif ($currentId === 2) {
            $wb->setWeeklyBasketStatus($statusRepo->find(1));
            $event = new PartnerEvent($partner, PartnerEvent::TYPE_BASKET_UNSKIP);
            $event->setActor($actor);
            $em->persist($event);
            $this->addFlash('notice', sprintf('%s vuelve a recoger la cesta de esa semana.', $partnerName));
        } else {
            $this->addFlash('warning', 'Estado de la cesta inesperado, no se puede alternar desde aquí.');
        }

        $em->flush();
        return $this->redirectToRoute('delivery_shifts_index', $backToWeek());
    }

    /**
     * Deadline efectivo del Basket dado, calculado con el mismo criterio
     * que el panel y la regla DeadlineRule: el jueves 23:59 anterior al
     * viernes del Basket (con la salvedad de la fórmula `abs(N-4)` que
     * sólo da el jueves correcto cuando el Basket cae en viernes — caso
     * normal hoy).
     */
    private function isWithinPickupDeadline(Basket $basket): bool
    {
        $date = $basket->getDate();
        if ($date === null) {
            return true;
        }
        $pickup = \DateTimeImmutable::createFromMutable($date);
        $diffDays = abs((int) $pickup->format('N') - 4);
        $deadline = $pickup->setTime(23, 59, 0)->modify(sprintf('-%d days', $diffDays));
        return new \DateTimeImmutable('now') < $deadline;
    }

    #[Route('/historial-cambios/{id}/cancelar', name: 'delivery_shifts_cancel', methods: ['POST'])]
    public function shiftsCancel(
        Request $request,
        PartnerDeliveryShift $shift,
        DeliveryShiftValidator $validator,
        DeliveryShiftApplier $applier,
    ): Response {
        // Volvemos a la semana origen del cambio (donde estaba la fila).
        $weekId = $shift->getFromBasket()->getId();

        if (!$this->isCsrfTokenValid('delivery_shift_cancel_' . $shift->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido.');
            return $this->redirectToRoute('delivery_shifts_index', ['basket' => $weekId]);
        }

        // Las reglas bloquean igual que en apply: deadline y equilibrio.
        // Mientras administración no defina el equilibrio definitivo, ninguna
        // se puede saltar — si admin necesita cancelar fuera de plazo,
        // hablamos antes de reabrir el bypass.
        $violations = $validator->validate($shift->getPartner(), $shift->getFromBasket(), $shift->getToBasket());
        if (!empty($violations)) {
            foreach ($violations as $v) {
                $this->addFlash('error', $v->message);
            }
            return $this->redirectToRoute('delivery_shifts_index', ['basket' => $weekId]);
        }

        $applier->cancel($shift, actor: 'gestor:' . $this->getUser()->getId());

        $this->addFlash('notice', 'Cambio cancelado.');
        return $this->redirectToRoute('delivery_shifts_index', ['basket' => $weekId]);
    }

    /**
     * Entry point del menú lateral: calcula el primer Node (por id ASC) y
     * el próximo Basket (date >= today, o el último pasado si todos han
     * pasado) y redirige a la pantalla concreta. Permite enlazar desde el
     * menú sin hardcodear ids que cambian con el tiempo.
     */
    #[Route('/v2', name: 'delivery_by_node_default', methods: ['GET'])]
    public function byNodeDefault(
        NodeRepository $nodeRepo,
        BasketRepository $basketRepo,
    ): Response {
        $firstNode = $nodeRepo->createQueryBuilder('n')->orderBy('n.id', 'ASC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($firstNode === null) {
            throw $this->createNotFoundException('No hay nodos configurados todavía.');
        }

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $nextBasket = $basketRepo->createQueryBuilder('b')
            ->where('b.date >= :today')
            ->setParameter('today', $today)
            ->orderBy('b.date', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        // Fallback: si todos los baskets han pasado, ir al último para no 404.
        if ($nextBasket === null) {
            $nextBasket = $basketRepo->createQueryBuilder('b')
                ->orderBy('b.date', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        if ($nextBasket === null) {
            throw $this->createNotFoundException('No hay baskets configurados todavía.');
        }

        return $this->redirectToRoute('delivery_by_node', [
            'nodeId' => $firstNode->getId(),
            'basketId' => $nextBasket->getId(),
        ]);
    }

    /**
     * Listado de reparto v2 (sub-fase 8.8c): tabla agrupada Nodo > WBG > Modalidad.
     * Reemplaza la pantalla legacy partner_basket_share_generate_weekly.
     */
    #[Route(
        '/v2/nodo/{nodeId}/{basketId}',
        name: 'delivery_by_node',
        methods: ['GET'],
        requirements: ['nodeId' => '\d+', 'basketId' => '\d+']
    )]
    public function byNode(
        #[MapEntity(id: 'nodeId')] Node $node,
        #[MapEntity(id: 'basketId')] Basket $basket,
        NodeRepository $nodeRepo,
        BasketRepository $basketRepo,
        WeeklyBasketRepository $weeklyBasketRepo,
        WeeklyBasketItemRepository $weeklyBasketItemRepo,
        PartnerBasketShareRepository $partnerBasketShareRepo,
        NodeDeliveryDate $nodeDeliveryDate,
        DeliveryExceptionRepository $deliveryExceptionRepo,
        WeeklyBasketGenerator $generator,
        Request $request,
    ): Response {
        $orden = $request->query->get('orden', 'grupo');
        if (!in_array($orden, ['grupo', 'modalidad', 'alfabetico'], true)) {
            $orden = 'grupo';
        }

        $allNodes = $nodeRepo->findBy([], ['name' => 'ASC']);

        // ¿Este nodo entrega en este Basket? Captura cadencia (un quincenal
        // fuera de fase devuelve false) y excepciones de calendario por nodo
        // o global de admin (cancelación o traslado). Sustituye al hardcode
        // de festivos viernes que existía hasta 8.8e.
        $nodeDelivers = $nodeDeliveryDate->deliversInBasket($basket, $node);

        // Para decidir si materializamos el ciclo entero: nos basta con que
        // no haya una excepción global de cancelación. Si admin cancela sólo
        // un nodo, los demás se materializan; si cancela el ciclo entero
        // (excepción global sin shifted_date), saltamos toda generación.
        $globalException = $deliveryExceptionRepo->findGlobalForBasket($basket);
        $basketGloballyCancelled = $globalException !== null && $globalException->isCancelled();

        // Materialización al vuelo: si este Basket aún no tiene reparto generado
        // (el cron del lunes lo hace proactivamente, pero una semana futura
        // puede no estar hecha), lo generamos ahora. El generador es idempotente
        // —no duplica WeeklyBaskets ya existentes— así que convive con el cron.
        // Sólo se dispara cuando el Basket no tiene NADA: un nodo quincenal
        // vacío en su semana de descanso es legítimo y no debe regenerar.
        if (!$basketGloballyCancelled && $weeklyBasketRepo->countPickedInBasket($basket) === 0) {
            $generator->generateForBasket($basket);
        }

        $weeklyBaskets = $nodeDelivers
            ? $weeklyBasketRepo->findForNodeAndBasket($node, $basket)
            : [];

        // Fecha física de reparto del nodo en este Basket: para Torremocha
        // (semanal viernes) coincide con basket.date; para Madrid (miércoles)
        // cae otro día; null si el nodo es quincenal y este Basket no le toca.
        $physicalDate = $nodeDeliveryDate->physicalDateFor($basket, $node);

        // Mini-timeline del header: 1 ciclo pasado, el actual y 3 futuros.
        // Cada entrada lleva el día REAL del nodo (miércoles para Madrid,
        // viernes para Torremocha) y si el nodo reparte esa semana, para
        // atenuar las semanas vacías de los nodos quincenales.
        //
        // La tira se ancla SIEMPRE en el día seleccionado ($basket), no en
        // ?ref=, para que al pinchar un día (incluso tras navegar con las
        // flechas a una ventana lejana) la tira quede centrada en ese día.
        // El ?ref= sólo lo usa la navegación AJAX de las flechas, que
        // desplaza la ventana sin cambiar el día seleccionado.
        $timelineData = $this->buildTimelineData(
            $basketRepo, $nodeDeliveryDate, $node, $basket,
            $basket->getId(), 'center',
        );

        // Estructura unificada de secciones para el render, según el modo de
        // orden elegido (por grupo de recogida / por modalidad / lista
        // alfabética). Formato común: secciones con subgrupos y filas, para
        // que el template tenga una sola rama de pintado.
        $sections = $this->buildSections($weeklyBaskets, $orden);

        // pbsByWbId[wb.id] = PartnerBasketShare activo del partner en la fecha
        // del basket. Reemplaza a $wb->getPartnerBasketShare() (propiedad
        // transient que no se persiste y devolvería null al recuperar el WB).
        $pbsByWbId = [];
        foreach ($weeklyBaskets as $wb) {
            $partner = $wb->getPartner();
            if ($partner === null) {
                $pbsByWbId[$wb->getId()] = null;
                continue;
            }
            $pbsByWbId[$wb->getId()] = $partnerBasketShareRepo->findActiveForPartner(
                $partner,
                $basket->getDate(),
            );
        }

        // Composición materializada por entrega (WeeklyBasketItem): leemos las
        // líneas en vez de re-derivar del patrón al pintar. Cada entrega trae
        // sus cestas físicas (verdura) y sus docenas (huevos) ya estampadas; un
        // cambio puntual movió la entrega con sus líneas, así que esto refleja
        // la verdad por-entrega, no el patrón ciego al shift.
        $componentAmounts = $weeklyBasketItemRepo->componentAmountsFor($weeklyBaskets);
        $cestasByWbId = [];
        $dozensByWbId = [];
        $eggDeliveryMap = [];
        foreach ($weeklyBaskets as $wb) {
            $items = $componentAmounts[$wb->getId()] ?? [];
            $cestasByWbId[$wb->getId()] = $items[BasketComponent::ID_VEGETABLES] ?? 0.0;
            $dozensByWbId[$wb->getId()] = $items[BasketComponent::ID_EGGS] ?? 0.0;
            // "Lleva huevos" = existe línea de huevos para esta entrega.
            $eggDeliveryMap[$wb->getId()] = isset($items[BasketComponent::ID_EGGS]);
        }

        // node_active_counts[nodeId] = {wbg, socios}: conteo de reparto de ESE
        // día por nodo, para que las pestañas no pinten el total de WBG
        // asignados (engañoso para nodos quincenales que no reparten hoy).
        $nodeActiveCounts = $weeklyBasketRepo->countActiveByNodeForBasket($basket);

        return $this->render('delivery/by_node.html.twig', [
            'node' => $node,
            'basket' => $basket,
            'physical_date' => $physicalDate,
            'node_delivers' => $nodeDelivers,
            'basket_globally_cancelled' => $basketGloballyCancelled,
            'all_nodes' => $allNodes,
            'node_active_counts' => $nodeActiveCounts,
            'timeline' => $timelineData['timeline'],
            'timeline_nav' => $timelineData['nav'],
            'ref_id' => $timelineData['ref_id'],
            'orden' => $orden,
            'sections' => $sections,
            'pbs_by_wb_id' => $pbsByWbId,
            'egg_delivery_map' => $eggDeliveryMap,
            'cestas_by_wb_id' => $cestasByWbId,
            'dozens_by_wb_id' => $dozensByWbId,
            'totals' => $this->computeTotals($weeklyBaskets, $cestasByWbId, $dozensByWbId),
        ]);
    }

    /**
     * Fragmento HTML de la mini-timeline para repintarla por AJAX al pulsar
     * las flechas, sin recargar la página entera (el listado no cambia). El
     * `mode` (back/ahead) desplaza la ventana solapando un solo día.
     */
    #[Route(
        '/v2/nodo/{nodeId}/{basketId}/timeline',
        name: 'delivery_by_node_timeline',
        methods: ['GET'],
        requirements: ['nodeId' => '\d+', 'basketId' => '\d+']
    )]
    public function byNodeTimeline(
        #[MapEntity(id: 'nodeId')] Node $node,
        #[MapEntity(id: 'basketId')] Basket $basket,
        BasketRepository $basketRepo,
        NodeDeliveryDate $nodeDeliveryDate,
        Request $request,
    ): Response {
        $mode = $request->query->get('mode', 'center');
        if (!in_array($mode, ['center', 'back', 'ahead'], true)) {
            $mode = 'center';
        }
        $orden = $request->query->get('orden', 'grupo');
        if (!in_array($orden, ['grupo', 'modalidad', 'alfabetico'], true)) {
            $orden = 'grupo';
        }

        $data = $this->buildTimelineData(
            $basketRepo, $nodeDeliveryDate, $node, $basket,
            $request->query->getInt('ref', 0), $mode,
        );

        return $this->render('delivery/_timeline.html.twig', [
            'node' => $node,
            'basket' => $basket,
            'timeline' => $data['timeline'],
            'timeline_nav' => $data['nav'],
            'ref_id' => $data['ref_id'],
            'orden' => $orden,
        ]);
    }

    /**
     * Estructura unificada de secciones para el template, según el modo de
     * orden. Formato común: cada sección tiene un título (o null en lista
     * plana) y una lista de subgrupos; cada subgrupo tiene filas y, opcional,
     * un label (la modalidad, cuando se agrupa por grupo). Una sola forma de
     * pintar para los tres modos.
     *
     * @param WeeklyBasket[] $weeklyBaskets
     * @param string $orden grupo|modalidad|alfabetico
     * @return list<array{title:?string, subgroups: list<array{bs_id:?int, label:?string, rows: WeeklyBasket[]}>}>
     */
    private function buildSections(array $weeklyBaskets, string $orden): array
    {
        return match ($orden) {
            'modalidad'  => $this->sectionsByModality($weeklyBaskets),
            'alfabetico' => $this->sectionsFlat($weeklyBaskets),
            default      => $this->sectionsByGroup($weeklyBaskets),
        };
    }

    /**
     * Secciones por grupo de recogida (WBG), con subgrupos por modalidad.
     * Confía en el orden del query (wbg.name, basket_share.id, partner.name).
     *
     * @param WeeklyBasket[] $weeklyBaskets
     * @return list<array{title:?string, subgroups: list<array{bs_id:?int, label:?string, rows: WeeklyBasket[]}>}>
     */
    private function sectionsByGroup(array $weeklyBaskets): array
    {
        $byWbg = [];
        foreach ($weeklyBaskets as $wb) {
            $wbg = $wb->getWeeklyBasketGroup();
            $bs = $wb->getBasketShare();
            if ($wbg === null || $bs === null) {
                continue;
            }
            $byWbg[$wbg->getId()] ??= ['title' => $wbg->getName(), 'subs' => []];
            $byWbg[$wbg->getId()]['subs'][$bs->getId()] ??= ['bs_id' => $bs->getId(), 'label' => $bs->getName(), 'rows' => []];
            $byWbg[$wbg->getId()]['subs'][$bs->getId()]['rows'][] = $wb;
        }

        return array_map(
            fn (array $g): array => ['title' => $g['title'], 'subgroups' => array_values($g['subs'])],
            array_values($byWbg),
        );
    }

    /**
     * Secciones por modalidad de cesta; dentro, subgrupos por grupo de
     * recogida (segundo orden), y dentro filas por nombre. Útil para preparar
     * todas las cestas de una modalidad agrupadas por punto de entrega.
     *
     * Caso especial modalidad compartida (bs.id=4): el WBG es subdivisión
     * logística interna, pero el punto de recogida real es el nodo (la
     * pantalla ya está filtrada por nodo). Por eso no se subdivide por WBG
     * y se aplica un pase que pega cada pareja con su sharePartner para
     * que las dos filas salgan seguidas.
     *
     * @param WeeklyBasket[] $weeklyBaskets
     * @return list<array{title:?string, subgroups: list<array{bs_id:?int, label:?string, rows: WeeklyBasket[]}>}>
     */
    private function sectionsByModality(array $weeklyBaskets): array
    {
        $byMod = [];
        foreach ($weeklyBaskets as $wb) {
            $bs = $wb->getBasketShare();
            if ($bs === null) {
                continue;
            }
            $byMod[$bs->getId()] ??= ['title' => $bs->getName(), 'wbs' => []];
            $byMod[$bs->getId()]['wbs'][] = $wb;
        }
        ksort($byMod);

        $result = [];
        foreach ($byMod as $bsId => $m) {
            if ($bsId === 4) {
                $rows = $m['wbs'];
                usort($rows, $this->compareByDisplayName(...));
                [$rows, $pairEnds] = $this->pinSharedPairs($rows);
                $result[] = [
                    'title' => $m['title'],
                    'subgroups' => [[
                        'bs_id' => null,
                        'label' => null,
                        'rows' => $rows,
                        'pair_end_ids' => $pairEnds,
                    ]],
                ];
                continue;
            }

            $subs = [];
            foreach ($m['wbs'] as $wb) {
                $wbg = $wb->getWeeklyBasketGroup();
                $groupId = $wbg?->getId() ?? 0;
                $subs[$groupId] ??= [
                    'bs_id' => null,
                    'label' => $wbg?->getName() ?? 'Sin grupo',
                    'rows' => [],
                ];
                $subs[$groupId]['rows'][] = $wb;
            }
            uasort($subs, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
            foreach ($subs as &$sub) {
                usort($sub['rows'], $this->compareByDisplayName(...));
            }
            unset($sub);

            $result[] = ['title' => $m['title'], 'subgroups' => array_values($subs)];
        }

        return $result;
    }

    /**
     * Pega cada pareja de cesta compartida (Partner.sharePartner) consigo.
     * Mantiene el orden alfabético del "primero" de cada pareja y coloca
     * al compañero justo detrás. Si el compañero no está en la lista
     * (otro nodo, skip, shift fuera), la fila queda suelta en su sitio.
     *
     * Devuelve también el set de wb.id que cierran un grupo (la fila del
     * compañero en parejas de 2, o la propia fila en huérfanos de 1) para
     * que el template pinte un separador entre grupos.
     *
     * @param WeeklyBasket[] $rows ordenados alfabéticamente
     * @return array{0: WeeklyBasket[], 1: array<int, true>}
     */
    private function pinSharedPairs(array $rows): array
    {
        $byPartnerId = [];
        foreach ($rows as $wb) {
            $partner = $wb->getPartner();
            if ($partner !== null) {
                $byPartnerId[$partner->getId()] = $wb;
            }
        }

        $result = [];
        $used = [];
        $pairEnds = [];
        foreach ($rows as $wb) {
            $wbId = $wb->getId();
            if (isset($used[$wbId])) {
                continue;
            }
            $result[] = $wb;
            $used[$wbId] = true;

            $mate = $wb->getPartner()?->getSharePartner();
            $mateWb = $mate !== null ? ($byPartnerId[$mate->getId()] ?? null) : null;
            if ($mateWb !== null && !isset($used[$mateWb->getId()])) {
                $result[] = $mateWb;
                $used[$mateWb->getId()] = true;
                $pairEnds[$mateWb->getId()] = true;
            } else {
                $pairEnds[$wbId] = true;
            }
        }
        return [$result, $pairEnds];
    }

    /**
     * Una sola sección sin título con todas las filas ordenadas por nombre.
     *
     * @param WeeklyBasket[] $weeklyBaskets
     * @return list<array{title:?string, subgroups: list<array{bs_id:?int, label:?string, rows: WeeklyBasket[]}>}>
     */
    private function sectionsFlat(array $weeklyBaskets): array
    {
        $rows = $weeklyBaskets;
        usort($rows, $this->compareByDisplayName(...));

        return [['title' => null, 'subgroups' => [['bs_id' => null, 'label' => null, 'rows' => $rows]]]];
    }

    /**
     * Comparador de WeeklyBasket por el nombre de reparto del socio (natural,
     * insensible a mayúsculas).
     */
    private function compareByDisplayName(WeeklyBasket $a, WeeklyBasket $b): int
    {
        return strnatcasecmp(
            $a->getPartner()?->getNameForDelivery() ?? '',
            $b->getPartner()?->getNameForDelivery() ?? '',
        );
    }

    /**
     * Totales del nodo+día para el resumen destacado de la pantalla.
     *
     * @param WeeklyBasket[] $weeklyBaskets
     * @param array<int,float> $cestasByWbId Cestas físicas por entrega (ítem verdura).
     * @param array<int,float> $dozensByWbId Docenas por entrega (ítem huevos).
     * @return array{socios:int, grupos:int, cestas:float, docenas:float, con_huevos:int}
     */
    private function computeTotals(array $weeklyBaskets, array $cestasByWbId, array $dozensByWbId): array
    {
        $cestas = 0.0;
        $docenas = 0.0;
        $conHuevos = 0;
        $grupos = [];
        foreach ($weeklyBaskets as $wb) {
            // Cestas físicas y docenas salen de la composición materializada: la
            // ponderación ½ de las compartidas y el 0 de "solo huevos" ya están
            // estampados en el ítem (el composer aplicó el peso de la modalidad),
            // así que aquí solo sumamos. Adiós al hack de ids por modalidad.
            $cestas += $cestasByWbId[$wb->getId()] ?? 0.0;
            $wbg = $wb->getWeeklyBasketGroup();
            if ($wbg !== null) {
                $grupos[$wbg->getId()] = true;
            }
            $dozens = $dozensByWbId[$wb->getId()] ?? 0.0;
            if ($dozens > 0) {
                $conHuevos++;
                $docenas += $dozens;
            }
        }
        return [
            'socios' => count($weeklyBaskets),
            'grupos' => count($grupos),
            'cestas' => $cestas,
            'docenas' => $docenas,
            'con_huevos' => $conHuevos,
        ];
    }

    /**
     * Basket de referencia para anclar la mini-timeline: el indicado por
     * `?ref=` si es válido, o el más próximo a hoy (esta semana) como
     * fallback. La carga de página pasa el día seleccionado como ref para
     * centrar la tira en él; la navegación AJAX de las flechas pasa el extremo
     * de la ventana para desplazarla sin cambiar el día seleccionado.
     */
    private function resolveTimelineAnchor(BasketRepository $repo, int $refId): ?Basket
    {
        if ($refId > 0 && ($ref = $repo->find($refId)) instanceof Basket) {
            return $ref;
        }

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        return $repo->createQueryBuilder('b')
            ->where('b.date >= :today')
            ->setParameter('today', $today)
            ->orderBy('b.date', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
            ?? $repo->createQueryBuilder('b')
                ->orderBy('b.date', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
    }

    /** Nº de viernes visibles en la mini-timeline. */
    private const TIMELINE_SIZE = 5;

    /**
     * Ventana de la mini-timeline (5 viernes) alrededor del ancla, según el
     * modo de navegación:
     *  - 'center': 1 anterior + ancla + 3 posteriores (carga inicial / día).
     *  - 'back'  : 4 anteriores + ancla (ancla en el extremo derecho). Lo usa
     *              la flecha ‹: el día más antiguo visible pasa a ser el más
     *              reciente de la nueva ventana (solape de 1).
     *  - 'ahead' : ancla + 4 posteriores (ancla en el extremo izquierdo). Lo
     *              usa la flecha ›.
     *
     * @return Basket[]
     */
    private function surroundingBaskets(BasketRepository $repo, Basket $anchor, string $mode = 'center'): array
    {
        $anchorDate = $anchor->getDate()?->format('Y-m-d');
        if ($anchorDate === null) {
            return [$anchor];
        }

        [$nPrev, $nNext] = match ($mode) {
            'back'  => [self::TIMELINE_SIZE - 1, 0],
            'ahead' => [0, self::TIMELINE_SIZE - 1],
            default => [1, self::TIMELINE_SIZE - 2],
        };

        $previous = $nPrev > 0 ? $repo->createQueryBuilder('b')
            ->where('b.date < :date')->setParameter('date', $anchorDate)
            ->orderBy('b.date', 'DESC')->setMaxResults($nPrev)
            ->getQuery()->getResult() : [];

        $next = $nNext > 0 ? $repo->createQueryBuilder('b')
            ->where('b.date > :date')->setParameter('date', $anchorDate)
            ->orderBy('b.date', 'ASC')->setMaxResults($nNext)
            ->getQuery()->getResult() : [];

        return [...array_reverse($previous), $anchor, ...$next];
    }

    /**
     * Arma los datos de la mini-timeline (chips + flechas) para un nodo y un
     * día seleccionado. Compartido por la página completa y por el fragmento
     * AJAX que repintan las flechas.
     *
     * @return array{timeline: list<array{basket:Basket, date:\DateTimeInterface, delivers:bool, active:bool, past:bool}>, nav: array{prev_ref:?int, next_ref:?int, has_prev:bool, has_next:bool}, ref_id:?int}
     */
    private function buildTimelineData(
        BasketRepository $basketRepo,
        NodeDeliveryDate $nodeDeliveryDate,
        Node $node,
        Basket $selected,
        int $refId,
        string $mode,
    ): array {
        $anchor = $this->resolveTimelineAnchor($basketRepo, $refId);
        $window = $anchor !== null ? $this->surroundingBaskets($basketRepo, $anchor, $mode) : [];
        $today = new \DateTimeImmutable('today');

        $timeline = array_map(
            fn (Basket $b): array => [
                'basket' => $b,
                // Fecha física del nodo aplicando excepciones de calendario
                // (festivo trasladado: 1-may→30-abr, 15-may→14-may). Si el nodo
                // no reparte esa semana o está cancelada, physicalDateFor da null
                // y caemos al día nominal del nodo (la fila se atenúa via 'delivers').
                'date' => $nodeDeliveryDate->physicalDateFor($b, $node) ?? $nodeDeliveryDate->weekdayDateFor($b, $node),
                'delivers' => $nodeDeliveryDate->deliversInBasket($b, $node),
                'active' => $b->getId() === $selected->getId(),
                'past' => $b->getDate() !== null && $b->getDate() < $today,
            ],
            $window,
        );

        $first = $window[0] ?? null;
        $last = $window !== [] ? end($window) : null;

        return [
            'timeline' => $timeline,
            'nav' => [
                'prev_ref' => $first?->getId(),
                'next_ref' => $last?->getId(),
                'has_prev' => $first !== null && $this->basketExistsBeyond($basketRepo, $first, '<'),
                'has_next' => $last !== null && $this->basketExistsBeyond($basketRepo, $last, '>'),
            ],
            'ref_id' => $anchor?->getId(),
        ];
    }

    /**
     * ¿Hay algún Basket más allá del dado en la dirección indicada?
     *
     * @param string $op '<' (anteriores) o '>' (posteriores).
     */
    private function basketExistsBeyond(BasketRepository $repo, Basket $basket, string $op): bool
    {
        $date = $basket->getDate()?->format('Y-m-d');
        if ($date === null) {
            return false;
        }

        return (int) $repo->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where("b.date $op :date")
            ->setParameter('date', $date)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    #[Route('/{basketId}', name: 'delivery_show', methods: ['GET'], requirements: ['basketId' => '\d+'])]
    public function show(
        #[MapEntity(id: 'basketId')] Basket $basket,
        WeeklyBasketGenerator $generator,
        DeliveryExceptionRepository $exceptions,
    ): Response {
        $report = $generator->generateForBasket($basket);
        $exception = $exceptions->findGlobalForBasket($basket);

        return $this->render('delivery/show.html.twig', [
            'basket' => $basket,
            'report' => $report,
            'exception' => $exception,
            'rows' => $this->flattenForTable($report),
        ]);
    }

    /**
     * Aplana las cinco colecciones del report en una sola lista plana para
     * la tabla con DataTables. Cada fila trae todo lo necesario para que
     * la plantilla no haga lógica.
     *
     * @return array<int, array{name:string, surname:string, modality:string, node:?string, group:?string, amount:int}>
     */
    private function flattenForTable(WeeklyDeliveryReport $report): array
    {
        $rows = [];
        $modalities = [
            ['weekly_partners', 'Semanal'],
            ['biweekly_partners', 'Quincenal'],
            ['monthly_partners', 'Mensual'],
            ['old_half_basket_partners', 'Media cesta'],
            ['only_egg_partners', 'Solo huevos'],
        ];

        foreach ($modalities as [$prop, $label]) {
            foreach ($report->$prop as $item) {
                $partner = $item->getPartner();
                $node = $partner->getWeeklyBasketGroup();
                $share = method_exists($item, 'getPartnerBasketShare') ? $item->getPartnerBasketShare() : null;
                $group = $share?->getDeliveryGroup();

                $rows[] = [
                    'name' => $partner->getName() ?? '',
                    'surname' => $partner->getSurname() ?? '',
                    'modality' => $label,
                    'node' => $node?->getName(),
                    'group' => $group,
                    'amount' => (int) $item->getAmount(),
                ];
            }
        }

        return $rows;
    }

    /**
     * Selector de nodos para el listado imprimible de un día (Basket): muestra
     * todos los nodos, premarcando los que reparten ese día, y enlaza a la
     * generación del PDF con los nodos elegidos.
     */
    #[Route('/imprimible/{basketId}', name: 'delivery_printable_select', methods: ['GET'], requirements: ['basketId' => '\d+'])]
    public function printableSelect(
        #[MapEntity(id: 'basketId')] Basket $basket,
        NodeRepository $nodeRepo,
        NodeDeliveryDate $nodeDeliveryDate,
    ): Response {
        $nodes = array_map(
            static fn (Node $node): array => [
                'node' => $node,
                'delivers' => $nodeDeliveryDate->deliversInBasket($basket, $node),
                'physical_date' => $nodeDeliveryDate->physicalDateFor($basket, $node),
            ],
            $nodeRepo->findBy([], ['name' => 'ASC']),
        );

        // Si ningún nodo reparte ese día (festivo global, todos quincenales
        // fuera de fase…) no hay nada que imprimir: la plantilla deshabilita la
        // generación y el PDF queda protegido aguas abajo en printablePdf().
        $anyDelivers = array_filter($nodes, static fn (array $row): bool => $row['delivers']) !== [];

        return $this->render('delivery/printable_select.html.twig', [
            'basket' => $basket,
            'nodes' => $nodes,
            'any_delivers' => $anyDelivers,
        ]);
    }

    /**
     * Listado imprimible (PDF descargable vía dompdf) de un día para los nodos
     * elegidos (?nodes[]=). Cada nodo va en su(s) hoja(s), aislado por salto de
     * página. Reutiliza {@see NodeDeliverySheet} (misma fuente que la pantalla
     * v2) y materializa el reparto al vuelo igual que byNode si aún no existe.
     */
    #[Route('/imprimible/{basketId}/pdf', name: 'delivery_printable_pdf', methods: ['GET'], requirements: ['basketId' => '\d+'])]
    public function printablePdf(
        #[MapEntity(id: 'basketId')] Basket $basket,
        Request $request,
        NodeRepository $nodeRepo,
        NodeDeliverySheet $sheetBuilder,
        NodeDeliveryDate $nodeDeliveryDate,
        WeeklyBasketGenerator $generator,
        WeeklyBasketRepository $weeklyBasketRepo,
        DeliveryExceptionRepository $deliveryExceptionRepo,
    ): Response {
        $nodeIds = array_values(array_filter(array_map('intval', (array) $request->query->all('nodes'))));

        // Materialización al vuelo idéntica a byNode: si el Basket no tiene
        // reparto generado y no está cancelado globalmente, se genera (el
        // generador es idempotente y cubre todos los nodos de una vez).
        $globalException = $deliveryExceptionRepo->findGlobalForBasket($basket);
        $basketGloballyCancelled = $globalException !== null && $globalException->isCancelled();
        if (!$basketGloballyCancelled && $weeklyBasketRepo->countPickedInBasket($basket) === 0) {
            $generator->generateForBasket($basket);
        }

        $sheets = [];
        foreach ($nodeRepo->findBy(['id' => $nodeIds ?: [0]], ['name' => 'ASC']) as $node) {
            if (!$nodeDeliveryDate->deliversInBasket($basket, $node)) {
                continue;
            }
            $sheets[] = [
                'node' => $node,
                'physical_date' => $nodeDeliveryDate->physicalDateFor($basket, $node),
                'sheet' => $sheetBuilder->build($node, $basket),
            ];
        }

        // Ninguno de los nodos elegidos reparte ese día: no tiene sentido un PDF
        // de cero hojas. Volvemos al selector con un aviso. Cubre tanto el caso
        // de que ningún nodo reparta como el de forzar la URL con nodos que no
        // reparten.
        if ($sheets === []) {
            $this->addFlash('warning', 'Ningún nodo reparte ese día: no hay listado que generar.');

            return $this->redirectToRoute('delivery_printable_select', ['basketId' => $basket->getId()]);
        }

        $html = $this->renderView('delivery/printable.html.twig', [
            'basket' => $basket,
            'sheets' => $sheets,
        ]);

        // DejaVu Sans: dompdf necesita una fuente con cobertura latina completa
        // para acentos y la ñ (la fuente por defecto los rompe).
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sprintf('reparto-%s.pdf', $basket->getDate()->format('Y-m-d'));

        return new Response($dompdf->output(), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }
}
