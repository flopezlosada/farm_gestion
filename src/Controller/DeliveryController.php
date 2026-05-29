<?php

namespace App\Controller;

use App\Entity\Basket;
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
use App\Repository\WeeklyBasketRepository;
use App\Repository\WeeklyBasketStatusRepository;
use App\Service\Delivery\DeliveryShiftApplier;
use App\Service\Delivery\DeliveryShiftCandidates;
use App\Service\Delivery\DeliveryShiftValidator;
use App\Service\Delivery\EggDeliveryResolver;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Delivery\WeeklyBasketGenerator;
use App\Service\Delivery\WeeklyDeliveryReport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
 * Bajo el mismo prefijo se cuelga la gestión de cambios puntuales de
 * viernes (DeliveryShift): un admin puede registrar el cambio que pide
 * un socio por canal externo y/o cancelar uno en curso, con opción de
 * forzar reglas bypassables del validator.
 */
#[Route('/gestion/reparto')]
#[IsGranted('ROLE_GESTION_SOCIXS')]
class DeliveryController extends AbstractController
{
    #[Route('/cambios-viernes', name: 'delivery_shifts_index', methods: ['GET'])]
    public function shiftsIndex(
        Request $request,
        PartnerDeliveryShiftRepository $shiftRepo,
        PartnerRepository $partnerRepo,
        BasketRepository $basketRepo,
        WeeklyBasketRepository $weeklyBasketRepo,
    ): Response {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        // Shifts (cambios de viernes) cuyo viernes destino aún no ha pasado.
        $shifts = $shiftRepo->createQueryBuilder('s')
            ->innerJoin('s.fromBasket', 'fb')
            ->innerJoin('s.toBasket', 'tb')
            ->where('tb.date >= :today')
            ->setParameter('today', $today)
            ->orderBy('fb.date', 'ASC')
            ->getQuery()
            ->getResult();

        // "No recoge esta semana" (WeeklyBasket en status=2) que no son
        // cascada de un shift: si la WeeklyBasket está en status 2 porque hay
        // un PartnerDeliveryShift que la origina, el shift ya aparece en la
        // tabla y meter la WB sería duplicar.
        $skips = $weeklyBasketRepo->createQueryBuilder('wb')
            ->innerJoin('wb.basket', 'b')
            ->where('wb.weekly_basket_status = :statusNoRecoge')
            ->andWhere('b.date >= :today')
            ->andWhere('NOT EXISTS (
                SELECT 1 FROM \App\Entity\PartnerDeliveryShift s2
                WHERE s2.partner = wb.partner AND s2.fromBasket = wb.basket
            )')
            ->setParameter('statusNoRecoge', 2)
            ->setParameter('today', $today)
            ->orderBy('b.date', 'ASC')
            ->getQuery()
            ->getResult();

        $upcomingBaskets = $basketRepo->createQueryBuilder('b')
            ->where('b.date >= :today')
            ->setParameter('today', $today)
            ->orderBy('b.date', 'ASC')
            ->setMaxResults(16)
            ->getQuery()
            ->getResult();

        // Preseleccionar un socix tras una acción (redirect ?partner=ID) para
        // que el form se recargue ya cargado con su estado actual y el admin
        // no tenga que volver a buscarlo en el datalist.
        $preselectedId = $request->query->getInt('partner', 0);
        $preselected = $preselectedId > 0 ? $partnerRepo->find($preselectedId) : null;

        return $this->render('delivery/shifts_index.html.twig', [
            'shifts' => $shifts,
            'skips' => $skips,
            'partners' => $partnerRepo->findBy([], ['surname' => 'ASC', 'name' => 'ASC']),
            'upcoming_baskets' => $upcomingBaskets,
            'preselected_partner' => $preselected instanceof Partner ? $preselected : null,
        ]);
    }

    /**
     * Endpoint para el form dinámico: dado un partner, devuelve qué
     * cambio puede hacerse sobre su próxima cesta. Permite al JS de la
     * pantalla rellenar los selectores de origen y destino según la
     * modalidad del socio sin repintar la página.
     *
     * Respuesta:
     *   - modality          1=semanal, 2=quincenal, 3=mensual, 4=semanal compartida, 5=sólo huevos
     *   - modality_label    Texto legible de la modalidad.
     *   - from_basket       { id, date } del próximo viernes que le toca, o null.
     *   - to_candidates     Lista [{ id, date }] de viernes destino aceptados por
     *                       las reglas (excluyendo violaciones de deadline para
     *                       no descartar al candidato cuando el plazo expiró —
     *                       el front debe marcar el bloqueo aparte).
     *   - blocked           true si hay candidatos pero las reglas no los permiten.
     *   - deadline_passed   true si el deadline para cambios sobre el próximo
     *                       viernes ya pasó.
     */
    #[Route('/cambios-viernes/candidates/{partnerId}', name: 'delivery_shifts_candidates', methods: ['GET'], requirements: ['partnerId' => '\d+'])]
    public function shiftsCandidates(
        int $partnerId,
        PartnerRepository $partnerRepo,
        WeeklyBasketRepository $weeklyBasketRepo,
        PartnerBasketShareRepository $shareRepo,
        PartnerDeliveryShiftRepository $shiftRepo,
        DeliveryShiftCandidates $candidatesService,
        DeliveryShiftValidator $validator,
    ): JsonResponse {
        $partner = $partnerRepo->find($partnerId);
        if (!$partner instanceof Partner) {
            return new JsonResponse(['error' => 'Partner no encontrado.'], 404);
        }

        $share = $shareRepo->findActiveForPartner($partner);
        $modality = $share?->getBasketShare()?->getId();
        $modalityLabel = $share?->getBasketShare()?->getName() ?? 'Sin cesta activa';

        $next = $weeklyBasketRepo->findNextForPartner($partner);
        $from = $next?->getBasket();

        $fromPayload = $from !== null
            ? [
                'id' => $from->getId(),
                'date' => $from->getDate()->format('Y-m-d'),
                'status_id' => $next?->getWeeklyBasketStatus()?->getId(),
            ]
            : null;

        $existingShift = ($from !== null) ? $shiftRepo->findOutgoing($partner, $from) : null;
        $existingShiftPayload = $existingShift !== null
            ? [
                'id' => $existingShift->getId(),
                'to_date' => $existingShift->getToBasket()->getDate()->format('Y-m-d'),
                // CSRF dependiente del id, ya pre-calculado para que el front
                // pueda enviarlo sin tener que ir a otro endpoint.
                'csrf' => $this->container->get('security.csrf.token_manager')
                    ->getToken('delivery_shift_cancel_' . $existingShift->getId())->getValue(),
            ]
            : null;

        $toCandidates = [];
        $blocked = false;
        // El deadline jueves 23:59 es ahora bloqueante también para admin: si
        // la próxima cesta lo ha pasado, el front pintará el InfoCard "Plazo
        // cerrado" y no se ofrecerán acciones (apply/cancel/skip ya bloquean
        // en backend coherentemente).
        $deadlinePassed = ($from !== null) && !$this->isWithinPickupDeadline($from);

        // Si ya hay shift activo, no calculamos candidatos: el shift manda y
        // la UI sólo debe ofrecer "deshacer".
        if (!$deadlinePassed && $existingShift === null && $from !== null && $share !== null) {
            foreach ($candidatesService->findFor($share, $from) as $candidate) {
                $violations = $validator->validate($partner, $from, $candidate);
                if (empty($violations)) {
                    $toCandidates[] = ['id' => $candidate->getId(), 'date' => $candidate->getDate()->format('Y-m-d')];
                } else {
                    $blocked = true;
                }
            }
        }

        return new JsonResponse([
            'modality' => $modality,
            'modality_label' => $modalityLabel,
            'from_basket' => $fromPayload,
            'existing_shift' => $existingShiftPayload,
            'to_candidates' => $toCandidates,
            'blocked' => $blocked,
            'deadline_passed' => $deadlinePassed,
        ]);
    }

    #[Route('/cambios-viernes/aplicar', name: 'delivery_shifts_apply', methods: ['POST'])]
    public function shiftsApply(
        Request $request,
        PartnerRepository $partnerRepo,
        BasketRepository $basketRepo,
        WeeklyBasketRepository $weeklyBasketRepo,
        DeliveryShiftValidator $validator,
        DeliveryShiftApplier $applier,
    ): Response {
        $partner = $partnerRepo->find((int) $request->request->get('partner_id'));

        if (!$this->isCsrfTokenValid('delivery_shift_apply', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido.');
            return $this->redirectToRoute('delivery_shifts_index', $partner instanceof Partner ? ['partner' => $partner->getId()] : []);
        }

        $from = $basketRepo->find((int) $request->request->get('from_basket_id'));
        $to = $basketRepo->find((int) $request->request->get('to_basket_id'));

        if (!$partner instanceof Partner || !$from instanceof Basket || !$to instanceof Basket) {
            $this->addFlash('error', 'Faltan datos del cambio: socio, viernes origen o viernes destino.');
            return $this->redirectToRoute('delivery_shifts_index', $partner instanceof Partner ? ['partner' => $partner->getId()] : []);
        }

        // Guarda defensiva: si la WeeklyBasket origen está marcada como "no
        // recoge" (status=2), no se puede registrar un shift sobre ella —
        // habría que volver a recoger primero. La UI ya enmascara esta
        // acción pero el endpoint también la rechaza.
        $existingWb = $weeklyBasketRepo->findOneBy(['basket' => $from, 'partner' => $partner]);
        if ($existingWb !== null && $existingWb->getWeeklyBasketStatus()?->getId() === 2) {
            $this->addFlash('warning', 'Este socix ya tiene marcado que no recoge esta semana. Si quieres registrar el cambio, primero restituye la recogida.');
            return $this->redirectToRoute('delivery_shifts_index', $partner instanceof Partner ? ['partner' => $partner->getId()] : []);
        }

        // Todas las violaciones bloquean para admin: deadline (jueves 23:59,
        // el viernes ya se está cosechando) y equilibrio (pendiente de cierre
        // por administración). Si admin decide más adelante poder saltárselas,
        // se reactiva un canal explícito.
        $violations = $validator->validate($partner, $from, $to);
        if (!empty($violations)) {
            foreach ($violations as $v) {
                $this->addFlash('error', $v->message);
            }
            return $this->redirectToRoute('delivery_shifts_index', $partner instanceof Partner ? ['partner' => $partner->getId()] : []);
        }

        try {
            $applier->apply($partner, $from, $to, actor: 'gestor:' . $this->getUser()->getId());
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('delivery_shifts_index', $partner instanceof Partner ? ['partner' => $partner->getId()] : []);
        }

        $this->addFlash('notice', sprintf(
            'Cambio aplicado: %s recoge el %s en lugar del %s.',
            trim(($partner->getName() ?? '') . ' ' . ($partner->getSurname() ?? '')),
            $to->getDate()->format('d/m/Y'),
            $from->getDate()->format('d/m/Y'),
        ));
        return $this->redirectToRoute('delivery_shifts_index', ['partner' => $partner->getId()]);
    }

    /**
     * Toggle de "no recoge esta semana" desde admin para un socix concreto.
     * Es el equivalente admin del `panel_basket_skip_toggle`: cambia el
     * status de la próxima WeeklyBasket entre 1 (recoge) y 2 (no recoge),
     * con guarda anti-shift (si hay shift activo, no se permite el toggle:
     * desincronizaría la cascada). Bloquea fuera del deadline jueves 23:59:
     * el viernes ya se está cosechando, ningún cambio tiene sentido.
     */
    #[Route('/cambios-viernes/skip', name: 'delivery_shifts_skip_toggle', methods: ['POST'])]
    public function shiftsSkipToggle(
        Request $request,
        PartnerRepository $partnerRepo,
        WeeklyBasketRepository $weeklyBasketRepo,
        WeeklyBasketStatusRepository $statusRepo,
        PartnerDeliveryShiftRepository $shiftRepo,
        EntityManagerInterface $em,
    ): Response {
        $partner = $partnerRepo->find((int) $request->request->get('partner_id'));

        if (!$this->isCsrfTokenValid('delivery_shift_skip', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido.');
            return $this->redirectToRoute('delivery_shifts_index', $partner instanceof Partner ? ['partner' => $partner->getId()] : []);
        }

        if (!$partner instanceof Partner) {
            $this->addFlash('error', 'Falta el socix sobre el que actuar.');
            return $this->redirectToRoute('delivery_shifts_index', $partner instanceof Partner ? ['partner' => $partner->getId()] : []);
        }

        $next = $weeklyBasketRepo->findNextForPartner($partner);
        if ($next === null) {
            $this->addFlash('warning', 'Este socix no tiene una próxima cesta sobre la que actuar.');
            return $this->redirectToRoute('delivery_shifts_index', $partner instanceof Partner ? ['partner' => $partner->getId()] : []);
        }

        if ($shiftRepo->findOutgoing($partner, $next->getBasket()) !== null) {
            $this->addFlash('warning', 'Este socix tiene un cambio puntual activo: cancélalo primero si quieres alterar la próxima cesta.');
            return $this->redirectToRoute('delivery_shifts_index', $partner instanceof Partner ? ['partner' => $partner->getId()] : []);
        }

        if (!$this->isWithinPickupDeadline($next->getBasket())) {
            $this->addFlash('error', 'El plazo para esta semana ya terminó (jueves 23:59). No se puede registrar el cambio.');
            return $this->redirectToRoute('delivery_shifts_index', ['partner' => $partner->getId()]);
        }

        $currentId = $next->getWeeklyBasketStatus()?->getId();
        $partnerName = trim(($partner->getName() ?? '') . ' ' . ($partner->getSurname() ?? ''));
        $actor = 'gestor:' . $this->getUser()->getId();

        if ($currentId === 1) {
            $next->setWeeklyBasketStatus($statusRepo->find(2));
            $event = new PartnerEvent($partner, PartnerEvent::TYPE_BASKET_SKIP);
            $event->setActor($actor);
            $em->persist($event);
            $this->addFlash('notice', sprintf('%s no recoge la cesta de esta semana.', $partnerName));
        } elseif ($currentId === 2) {
            $next->setWeeklyBasketStatus($statusRepo->find(1));
            $event = new PartnerEvent($partner, PartnerEvent::TYPE_BASKET_UNSKIP);
            $event->setActor($actor);
            $em->persist($event);
            $this->addFlash('notice', sprintf('%s vuelve a recoger la cesta de esta semana.', $partnerName));
        } else {
            $this->addFlash('warning', 'Estado de la cesta inesperado, no se puede alternar desde aquí.');
        }

        $em->flush();
        return $this->redirectToRoute('delivery_shifts_index', ['partner' => $partner->getId()]);
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

    #[Route('/cambios-viernes/{id}/cancelar', name: 'delivery_shifts_cancel', methods: ['POST'])]
    public function shiftsCancel(
        Request $request,
        PartnerDeliveryShift $shift,
        DeliveryShiftValidator $validator,
        DeliveryShiftApplier $applier,
    ): Response {
        $partnerId = $shift->getPartner()->getId();

        if (!$this->isCsrfTokenValid('delivery_shift_cancel_' . $shift->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido.');
            return $this->redirectToRoute('delivery_shifts_index', ['partner' => $partnerId]);
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
            return $this->redirectToRoute('delivery_shifts_index', ['partner' => $partnerId]);
        }

        $applier->cancel($shift, actor: 'gestor:' . $this->getUser()->getId());

        $this->addFlash('notice', 'Cambio cancelado.');
        return $this->redirectToRoute('delivery_shifts_index', ['partner' => $partnerId]);
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
        PartnerBasketShareRepository $partnerBasketShareRepo,
        EggDeliveryResolver $eggResolver,
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

        // Mini-timeline del header: 2 ciclos pasados, el actual y 3 futuros.
        // Cada entrada lleva el día REAL del nodo (miércoles para Madrid,
        // viernes para Torremocha) y si el nodo reparte esa semana, para
        // atenuar las semanas vacías de los nodos quincenales.
        $timelineData = $this->buildTimelineData(
            $basketRepo, $nodeDeliveryDate, $node, $basket,
            $request->query->getInt('ref', 0), 'center',
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

        // eggDeliveryMap[wb.id] = bool. Permite al template ocultar la columna
        // huevos cuando el resolver dice que no toca esta semana.
        $eggDeliveryMap = [];
        foreach ($weeklyBaskets as $wb) {
            $share = $pbsByWbId[$wb->getId()] ?? null;
            $eggDeliveryMap[$wb->getId()] = $share !== null
                ? $eggResolver->delivers($share, $basket)
                : false;
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
            'totals' => $this->computeTotals($weeklyBaskets, $eggDeliveryMap, $pbsByWbId),
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
     * @param array<int,bool> $eggDeliveryMap
     * @param array<int,?\App\Entity\PartnerBasketShare> $pbsByWbId
     * @return array{socios:int, grupos:int, cestas:float, docenas:float, con_huevos:int}
     */
    private function computeTotals(array $weeklyBaskets, array $eggDeliveryMap, array $pbsByWbId): array
    {
        $cestas = 0.0;
        $docenas = 0.0;
        $conHuevos = 0;
        $grupos = [];
        foreach ($weeklyBaskets as $wb) {
            // Cestas físicas según modalidad: solo-huevos (5) no lleva cesta;
            // compartidas (4 semanal, 6 quincenal, 7 mensual) son ½ (dos
            // familias, una cesta); el resto, 1. Multiplicado por amount: un
            // socio puede recibir varias cestas (ej. Mjose, David = 2).
            // BasketShare ids: 1 semanal, 2 quincenal, 3 mensual, 4 semanal
            // compartida, 5 solo huevos, 6 quincenal compartida, 7 mensual compartida.
            $cestas += ($wb->getAmount() ?? 1) * match ($wb->getBasketShare()?->getId()) {
                5 => 0.0,
                4, 6, 7 => 0.5,
                default => 1.0,
            };
            $wbg = $wb->getWeeklyBasketGroup();
            if ($wbg !== null) {
                $grupos[$wbg->getId()] = true;
            }
            if ($eggDeliveryMap[$wb->getId()] ?? false) {
                $conHuevos++;
                $eggAmount = ($pbsByWbId[$wb->getId()] ?? null)?->getEggAmount();
                if ($eggAmount !== null) {
                    $docenas += $eggAmount->getDozens();
                }
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
     * `?ref=` si es válido, o el más próximo a hoy (esta semana). Estable: no
     * depende del Basket seleccionado, así que pinchar un día no desplaza la
     * tira; para moverse por el calendario están las flechas (que cambian ref).
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
                'date' => $nodeDeliveryDate->weekdayDateFor($b, $node),
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
}
