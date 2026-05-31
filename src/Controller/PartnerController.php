<?php

namespace App\Controller;

use App\Custom\WeekOfMonth;
use App\Entity\BasketShare;
use App\Entity\EggAmount;
use App\Entity\EggPeriod;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Form\PartnerBasketShareType;
use App\Form\PartnerType;
use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Repository\BasketRepository;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Repository\PartnerRepository;
use App\Service\Delivery\DeliveryCalendarProjector;
use App\Service\Delivery\DeliveryShiftApplier;
use App\Service\Delivery\DeliveryShiftCandidates;
use App\Service\Delivery\DeliveryShiftValidator;
use App\Service\Delivery\Rule\DeadlineRule;
use App\Service\Delivery\WeeklyBasketComponentEditor;
use App\Service\Delivery\WeeklyBasketGenerator;
use App\Service\Delivery\WeeklyBasketSkipper;
use App\Service\Partner\PartnerShareEventRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Workflow\WorkflowInterface;

#[Route("/gestion/partner")]
#[IsGranted('ROLE_GESTION_SOCIXS')]
class PartnerController extends AbstractController
{
    #[Route("/", name: "partner_index", methods: ["GET"])]
    public function index(
        Request $request,
        PartnerRepository $partnerRepository,
        EntityManagerInterface $em,
        PaginatorInterface $paginator
    ): Response {
        // Pestaña por defecto: ACTIVO. Para ver el listado completo el call
        // site tiene que pedir explícitamente ?status=ALL.
        $rawStatus = $request->query->get('status', Partner::STATUS_ACTIVO);
        $statusFilter = in_array($rawStatus, Partner::STATUSES, true) ? $rawStatus : null;

        // Cast manual de node/wbg porque $request->query->getInt() lanza
        // BadRequestException si el valor es "" (string vacío), cosa que el
        // form GET envía siempre que el radio "Cualquier X" está marcado.
        $filters = [
            'status'     => $statusFilter,
            'modalities' => array_values(array_filter(array_map('intval', (array) $request->query->all('modality')))),
            'cesta'      => in_array($request->query->get('cesta'), ['yes', 'no'], true) ? $request->query->get('cesta') : null,
            'node'       => ((int) $request->query->get('node', '')) ?: null,
            'wbg'        => ((int) $request->query->get('wbg', '')) ?: null,
            'q'          => trim((string) $request->query->get('q', '')) ?: null,
        ];

        $qb = $partnerRepository->findFilteredQb($filters);

        $pagination = $paginator->paginate(
            $qb->getQuery(),
            $request->query->getInt('page', 1),
            25
        );

        $title = match ($statusFilter) {
            Partner::STATUS_ACTIVO  => 'Socias y socios activos',
            Partner::STATUS_PAUSADO => 'Socias y socios pausados',
            Partner::STATUS_BAJA    => 'Socias y socios de baja',
            default                 => 'Listado completo de socias y socios',
        };

        // Conteos para las pestañas. Una sola query agrupada.
        $rawCounts = $partnerRepository->createQueryBuilder('p')
            ->select('p.status AS status, COUNT(p.id) AS total')
            ->groupBy('p.status')
            ->getQuery()
            ->getArrayResult();
        $counts = ['ALL' => 0];
        foreach (Partner::STATUSES as $s) {
            $counts[$s] = 0;
        }
        foreach ($rawCounts as $row) {
            $counts[$row['status']] = (int) $row['total'];
            $counts['ALL'] += (int) $row['total'];
        }

        // Opciones para los popovers de filtro. Las cargo siempre (no son
        // costosas: <10 entidades cada una) para que la UI no se quede sin
        // opciones cuando el listado venga vacío.
        $modalitiesAll = $em->getRepository(BasketShare::class)->findBy([], ['id' => 'ASC']);
        $nodesAll      = $em->getRepository(Node::class)->findBy([], ['name' => 'ASC']);
        // Grupo de recogida depende de nodo: si hay nodo seleccionado, sólo
        // ofrecemos los WBG de ese nodo. Si no, todos.
        $wbgsAll = $filters['node']
            ? $em->getRepository(WeeklyBasketGroup::class)->findBy(['node' => $filters['node']], ['name' => 'ASC'])
            : $em->getRepository(WeeklyBasketGroup::class)->findBy([], ['name' => 'ASC']);

        return $this->render('partner/index.html.twig', [
            'pagination'     => $pagination,
            'title'          => $title,
            'type'           => null,
            'status_filter'  => $statusFilter,
            'statuses'       => Partner::STATUSES,
            'status_counts'  => $counts,
            'filters'        => $filters,
            'modalities_all' => $modalitiesAll,
            'nodes_all'      => $nodesAll,
            'wbgs_all'       => $wbgsAll,
        ]);
    }

    #[Route("/{type}/list/", name: "partner_list", methods: ["GET"])]
    public function list($type, EntityManagerInterface $entityManager)
    {
        if ($type == 1) {
            $partners = $entityManager->getRepository(\App\Entity\Partner::class)->findActiveHasNoBasket();
            $title = 'Listado de socias activas sin cesta';
        } else if ($type == 2) {
            $partners = $entityManager->getRepository(\App\Entity\Partner::class)->findBy(array('is_active' => 0));
            $title = 'Listado de socias que están de baja';
        }

        return $this->render('partner/index.html.twig', [
            'partners' => $partners,
            'title' => $title,
            'type' => $type
        ]);

    }

    #[Route("/evolution", name: "partner_evolution", methods: ["GET"])]
    public function evolution(PartnerRepository $partnerRepository): Response
    {
        // Conteo actual por estado.
        $currentByStatus = [Partner::STATUS_ACTIVO => 0, Partner::STATUS_PAUSADO => 0, Partner::STATUS_BAJA => 0];
        foreach ($partnerRepository->createQueryBuilder('p')
            ->select('p.status AS status, COUNT(p.id) AS total')
            ->groupBy('p.status')
            ->getQuery()->getArrayResult() as $row) {
            $currentByStatus[$row['status']] = (int) $row['total'];
        }
        $currentByStatus['TOTAL'] = array_sum($currentByStatus);

        // Serie temporal de los últimos 13 meses: altas, bajas, neto y total
        // de personas socias activas al cierre de cada mes. Las altas se
        // detectan por inscription_date; las bajas por demote_date. Cuando
        // existan PartnerEvent retroactivos, se podrá afinar este cálculo.
        $monthlyAggregates = $partnerRepository->countMonthlyJoinsAndLeaves(13);

        // Acumulado de activos: el valor de "hoy" lo conocemos, así que
        // proyectamos hacia atrás restando net mes a mes.
        $runningActive = $currentByStatus[Partner::STATUS_ACTIVO];
        $monthsCount = count($monthlyAggregates);
        for ($i = $monthsCount - 1; $i >= 0; $i--) {
            $monthlyAggregates[$i]['active_at_end'] = $runningActive;
            $runningActive -= ($monthlyAggregates[$i]['joins'] - $monthlyAggregates[$i]['leaves']);
        }

        return $this->render('partner/evolution.html.twig', [
            'current' => $currentByStatus,
            'monthly' => $monthlyAggregates,
        ]);
    }


    #[Route("/new", name: "partner_new", methods: ["GET","POST"])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $partner = new Partner();
        $form = $this->createForm(PartnerType::class, $partner);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $partner->setIsActive(1);

            if ($partner->getRegistrationFormFile()) {
                $partner->setHasFile(1);
            }
            $entityManager->persist($partner);
            $entityManager->flush();

            return $this->redirectToRoute('partner_show', array('id' => $partner->getId()));
        }

        return $this->render('partner/new.html.twig', [
            'partner' => $partner,
            'entity' => $partner,
            'form' => $form->createView(),
        ]);
    }

    #[Route("/{id}", name: "partner_show", methods: ["GET"])]
    public function show(
        Partner $partner,
        \App\Repository\PartnerEventRepository $partnerEventRepository,
        EntityManagerInterface $em,
    ): Response {
        $events = $partnerEventRepository->findForPartner($partner);

        // Resuelve los actores "gestor:<id>" a nombres legibles para el histórico.
        // El campo event.actor es un string crudo (p.ej. "gestor:1", "partner:42",
        // "sistema") guardado al emitir el evento. Aquí buscamos los users de los
        // gestores en una sola query y construimos un mapa id -> identifier.
        $gestorIds = [];
        foreach ($events as $event) {
            $actor = method_exists($event, 'getActor') ? $event->getActor() : null;
            if (is_string($actor) && str_starts_with($actor, 'gestor:')) {
                $id = (int) substr($actor, strlen('gestor:'));
                if ($id > 0) {
                    $gestorIds[$id] = true;
                }
            }
        }
        $gestorNames = [];
        if ($gestorIds) {
            $users = $em->getRepository(\App\Entity\User::class)
                ->findBy(['id' => array_keys($gestorIds)]);
            foreach ($users as $user) {
                $gestorNames[$user->getId()] = $user->getUserIdentifier();
            }
        }

        // Usuario de acceso vinculado a este socix (si lo tiene). La relación
        // User->Partner es unidireccional, así que se busca por el lado inverso.
        $linkedUser = $em->getRepository(\App\Entity\User::class)
            ->findOneBy(['partner' => $partner]);

        // Catálogos id -> nombre para que el histórico traduzca el payload de
        // BASKET_CHANGE (que guarda ids crudos) a texto legible, igual que
        // gestor_names hace con los actores. Los catálogos de cesta/huevos son
        // fijos, así que se resuelven por id (no se congela el nombre en el
        // payload como sí se hace con grupos/nodos, que el admin renombra).
        $toNameMap = static function (array $rows): array {
            $map = [];
            foreach ($rows as $row) {
                $map[$row->getId()] = $row->getName();
            }
            return $map;
        };

        return $this->render('partner/show.html.twig', [
            'partner' => $partner,
            'events' => $events,
            'gestor_names' => $gestorNames,
            'linked_user' => $linkedUser,
            'basket_share_names' => $toNameMap($em->getRepository(BasketShare::class)->findAll()),
            'egg_amount_names' => $toNameMap($em->getRepository(EggAmount::class)->findAll()),
            'egg_period_names' => $toNameMap($em->getRepository(EggPeriod::class)->findAll()),
        ]);
    }

    /**
     * Calendario de recogida del socio (B', solo lectura v0): muestra las
     * entregas proyectadas de un mes con sus componentes (verdura/huevos). El
     * mes se navega por query (?year=&month=); por defecto, el mes actual. La
     * edición (materializar al tocar) se monta encima en un paso posterior.
     *
     * @param Partner                   $partner
     * @param Request                   $request
     * @param DeliveryCalendarProjector $projector
     * @return Response
     */
    #[Route("/{id}/calendar", name: "partner_delivery_calendar", methods: ["GET"], requirements: ["id" => "\\d+"])]
    public function deliveryCalendar(
        Partner $partner,
        Request $request,
        DeliveryCalendarProjector $projector,
        DeliveryShiftCandidates $shiftCandidates,
        DeliveryShiftValidator $shiftValidator,
        EntityManagerInterface $em,
    ): Response {
        // Sin mes explícito (entrada desde la ficha), abrimos en el mes de la
        // PRÓXIMA entrega del socio, no en el mes actual: a fin de mes lo de este
        // mes ya no se puede gestionar. Si navega con las flechas, se respeta el
        // mes pedido.
        if ($request->query->has('month')) {
            $default = new \DateTimeImmutable('first day of this month');
            $year = (int) $request->query->get('year', $default->format('Y'));
            $month = (int) $request->query->get('month');
            if ($month < 1 || $month > 12) {
                $year = (int) $default->format('Y');
                $month = (int) $default->format('n');
            }
        } else {
            [$year, $month] = $this->resolveNextDeliveryMonth($partner, $projector);
        }

        $current = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $slots = $projector->projectMonth($partner, $year, $month);

        // Día seleccionado para el panel lateral: el Basket pedido por query (?sel),
        // o por defecto la primera entrega del mes. Solo entregas reales son
        // seleccionables (las celdas vacías no abren panel).
        $selectedBasketId = (int) $request->query->get('sel', 0);
        $selected = $this->resolveSelectedSlot($slots, $selectedBasketId, $current->format('Y-m'));

        // Las entregas pasadas no se editan (lo que ya pasó, pasó); solo se ven.
        $today = new \DateTimeImmutable('today');
        $selectedEditable = $selected !== null && $selected['date'] >= $today;

        // Candidatos de "mover de día" del slot seleccionado: solo si es editable
        // (futura), socio no compartido (R1) y con cesta activa esa semana. Mismo
        // motor que el panel del socio, pero el deadline NO descalifica (gestor).
        $moveCandidates = [];
        if ($selectedEditable && $partner->getSharePartner() === null) {
            $share = $em->getRepository(PartnerBasketShare::class)
                ->findActiveForPartner($partner, $selected['basket']->getDate());
            if ($share !== null) {
                $moveCandidates = $this->calendarMoveCandidates(
                    $partner, $share, $selected['basket'], $shiftCandidates, $shiftValidator,
                );
            }
        }

        // "Recoge en X": X es el NODO de reparto (Torremocha, Madrid…), no el grupo
        // de recogida (Pedrezuela…). Fallback al grupo si un dato legacy no tiene nodo.
        $group = $partner->getWeeklyBasketGroup();
        $pickupPlace = $group?->getNode()?->getName() ?? $group?->getName();

        return $this->render('partner/delivery_calendar.html.twig', [
            'partner' => $partner,
            'group' => $group,
            'pickup_place' => $pickupPlace,
            'slots' => $slots,
            'weeks' => $this->buildMonthWeeks($year, $month, $slots),
            'selected' => $selected,
            'selected_editable' => $selectedEditable,
            'move_candidates' => $moveCandidates,
            'current' => $current,
            'prev' => $current->modify('-1 month'),
            'next' => $current->modify('+1 month'),
        ]);
    }

    /**
     * Mueve toda la entrega de una semana a otra (cambio puntual de día) desde el
     * calendario del gestor. Reusa el motor de PartnerDeliveryShift del panel del
     * socio (candidatos + validador + applier), con dos diferencias de política:
     * el gestor NO tiene deadline (se descarta DeadlineRule) y SÍ puede saltarse
     * las violaciones bypassables (p. ej. equilibrio ≤3), que se avisan pero no
     * bloquean.
     *
     * Guarda R1: las cestas compartidas no cambian de día (lo dicta la alternancia
     * con el otro hogar).
     *
     * @param Partner                  $partner
     * @param int                      $basketId  Semana origen (Basket) de la entrega a mover.
     * @param Request                  $request
     * @param BasketRepository         $basketRepository
     * @param DeliveryShiftValidator   $validator
     * @param DeliveryShiftApplier     $applier
     * @return Response
     */
    #[Route("/{id}/calendar/move/{basketId}", name: "partner_delivery_calendar_move", methods: ["POST"], requirements: ["id" => "\\d+", "basketId" => "\\d+"])]
    public function deliveryCalendarMove(
        Partner $partner,
        int $basketId,
        Request $request,
        BasketRepository $basketRepository,
        DeliveryShiftValidator $validator,
        DeliveryShiftApplier $applier,
    ): Response {
        $from = $basketRepository->find($basketId);
        if ($from === null) {
            throw $this->createNotFoundException('Semana de reparto no encontrada.');
        }

        $backToCalendar = fn (): Response => $this->redirectToRoute('partner_delivery_calendar', [
            'id' => $partner->getId(),
            'year' => $from->getDate()->format('Y'),
            'month' => $from->getDate()->format('n'),
            'sel' => $from->getId(),
        ]);

        if (!$this->isCsrfTokenValid('calendar_move_' . $partner->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $backToCalendar();
        }

        // Las entregas pasadas no se mueven.
        if ($from->getDate() < new \DateTimeImmutable('today')) {
            $this->addFlash('warning', 'Esa entrega ya pasó: no se puede mover.');

            return $backToCalendar();
        }

        // R1: las cestas compartidas no cambian de día.
        if ($partner->getSharePartner() !== null) {
            $this->addFlash('warning', 'Esta cesta es compartida: su día lo marca la alternancia con el otro hogar, no se mueve aquí.');

            return $backToCalendar();
        }

        $to = $basketRepository->find((int) $request->request->get('to_basket_id', 0));
        if ($to === null) {
            $this->addFlash('error', 'Selecciona una fecha destino válida.');

            return $backToCalendar();
        }

        // Política del gestor: el deadline no aplica; solo bloquean las violaciones
        // no-bypassables (p. ej. que el destino no sea un día válido del nodo).
        $violations = array_filter(
            $validator->validate($partner, $from, $to),
            static fn ($v): bool => $v->rule !== DeadlineRule::ID,
        );
        if (!empty($validator->blocking($violations))) {
            foreach ($validator->blocking($violations) as $v) {
                $this->addFlash('error', $v->message);
            }

            return $backToCalendar();
        }

        foreach ($validator->bypassable($violations) as $v) {
            $this->addFlash('warning', $v->message);
        }

        try {
            $applier->apply($partner, $from, $to, actor: 'gestor:' . $this->getUser()?->getId());
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());

            return $backToCalendar();
        }

        $this->addFlash('success', sprintf(
            'Entrega movida del %s al %s.',
            $from->getDate()->format('d/m/Y'),
            $to->getDate()->format('d/m/Y'),
        ));

        // Tras mover, el día con la entrega pasa a ser el destino: se selecciona ese.
        return $this->redirectToRoute('partner_delivery_calendar', [
            'id' => $partner->getId(),
            'year' => $to->getDate()->format('Y'),
            'month' => $to->getDate()->format('n'),
            'sel' => $to->getId(),
        ]);
    }

    /**
     * Saltar / volver a recoger una entrega del calendario (acción de B'). Si la
     * entrega solo estaba prevista (no materializada), la fija primero
     * (materializeShareDelivery) y luego alterna su estado vía WeeklyBasketSkipper
     * — misma semántica que el panel del socio, sin borrar el WeeklyBasket.
     *
     * Guardas: R1 (los compartidos no editan su día), cambio puntual activo esa
     * semana (se desincronizaría) y que el socio tenga cesta activa y su nodo
     * reparta esa semana. Sin deadline: es el gestor.
     *
     * @param Partner                        $partner
     * @param int                            $basketId Semana (Basket) sobre la que actuar.
     * @param Request                        $request
     * @param WeeklyBasketGenerator          $generator
     * @param WeeklyBasketSkipper            $skipper
     * @param PartnerDeliveryShiftRepository $shiftRepository
     * @param EntityManagerInterface         $em
     * @return Response
     */
    #[Route("/{id}/calendar/skip/{basketId}", name: "partner_delivery_calendar_skip", methods: ["POST"], requirements: ["id" => "\\d+", "basketId" => "\\d+"])]
    public function deliveryCalendarSkip(
        Partner $partner,
        int $basketId,
        Request $request,
        WeeklyBasketGenerator $generator,
        WeeklyBasketSkipper $skipper,
        PartnerDeliveryShiftRepository $shiftRepository,
        EntityManagerInterface $em,
    ): Response {
        $basket = $em->getRepository(Basket::class)->find($basketId);
        if ($basket === null) {
            throw $this->createNotFoundException('Semana de reparto no encontrada.');
        }

        $backToCalendar = fn (): Response => $this->redirectToRoute('partner_delivery_calendar', [
            'id' => $partner->getId(),
            'year' => $basket->getDate()->format('Y'),
            'month' => $basket->getDate()->format('n'),
            'sel' => $basket->getId(),
        ]);

        if (!$this->isCsrfTokenValid('calendar_skip_' . $partner->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $backToCalendar();
        }

        $weeklyBasket = $this->resolveCalendarDelivery($partner, $basket, $generator, $shiftRepository, $em);
        if ($weeklyBasket === null) {
            return $backToCalendar();
        }

        $skipped = $skipper->toggle($weeklyBasket, 'gestor:' . $this->getUser()?->getId());
        $em->flush();

        if ($skipped === true) {
            $this->addFlash('success', 'Marcada como NO recogida esa semana.');
        } elseif ($skipped === false) {
            $this->addFlash('success', 'Restaurada: el socio vuelve a recoger esa semana.');
        } else {
            $this->addFlash('warning', 'La entrega está en un estado especial y no se puede alternar.');
        }

        return $backToCalendar();
    }

    /**
     * Activar / desactivar un componente (verdura o huevos) de una entrega
     * concreta del calendario (acción B' "editar por contenido"). Si la entrega
     * solo estaba prevista la fija primero y luego alterna la línea del
     * componente vía WeeklyBasketComponentEditor.
     *
     * @param Partner                        $partner
     * @param int                            $basketId    Semana (Basket).
     * @param int                            $componentId BasketComponent::ID_VEGETABLES | ID_EGGS.
     * @param Request                        $request
     * @param WeeklyBasketGenerator          $generator
     * @param WeeklyBasketComponentEditor    $componentEditor
     * @param PartnerDeliveryShiftRepository $shiftRepository
     * @param EntityManagerInterface         $em
     * @return Response
     */
    #[Route("/{id}/calendar/component/{basketId}/{componentId}", name: "partner_delivery_calendar_component", methods: ["POST"], requirements: ["id" => "\\d+", "basketId" => "\\d+", "componentId" => "\\d+"])]
    public function deliveryCalendarToggleComponent(
        Partner $partner,
        int $basketId,
        int $componentId,
        Request $request,
        WeeklyBasketGenerator $generator,
        WeeklyBasketComponentEditor $componentEditor,
        PartnerDeliveryShiftRepository $shiftRepository,
        EntityManagerInterface $em,
    ): Response {
        if (!in_array($componentId, [BasketComponent::ID_VEGETABLES, BasketComponent::ID_EGGS], true)) {
            throw $this->createNotFoundException('Componente no editable.');
        }

        $basket = $em->getRepository(Basket::class)->find($basketId);
        if ($basket === null) {
            throw $this->createNotFoundException('Semana de reparto no encontrada.');
        }

        $backToCalendar = fn (): Response => $this->redirectToRoute('partner_delivery_calendar', [
            'id' => $partner->getId(),
            'year' => $basket->getDate()->format('Y'),
            'month' => $basket->getDate()->format('n'),
            'sel' => $basket->getId(),
        ]);

        if (!$this->isCsrfTokenValid('calendar_component_' . $partner->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $backToCalendar();
        }

        $weeklyBasket = $this->resolveCalendarDelivery($partner, $basket, $generator, $shiftRepository, $em);
        if ($weeklyBasket === null) {
            return $backToCalendar();
        }

        $result = $componentEditor->toggle($weeklyBasket, $componentId);
        $em->flush();

        $label = $componentId === BasketComponent::ID_EGGS ? 'Huevos' : 'Verdura';
        match ($result) {
            'added' => $this->addFlash('success', $label . ' añadido a esa entrega.'),
            'removed' => $this->addFlash('success', $label . ' quitado de esa entrega.'),
            default => $this->addFlash('warning', 'Esa entrega no contempla ' . strtolower($label) . ' según la cesta del socio.'),
        };

        return $backToCalendar();
    }

    /**
     * Resuelve la entrega editable de un socio en una semana para las acciones
     * del calendario (saltar, toggle de componente): aplica las guardas comunes
     * y materializa la entrega si solo estaba prevista. Devuelve null y deja un
     * flash si alguna guarda lo impide.
     *
     * Guardas: R1 (los compartidos no editan su día), cambio puntual activo esa
     * semana (se desincronizaría), socio con cesta activa y nodo que reparta.
     *
     * @param Partner                        $partner
     * @param Basket                         $basket
     * @param WeeklyBasketGenerator          $generator
     * @param PartnerDeliveryShiftRepository $shiftRepository
     * @param EntityManagerInterface         $em
     * @return WeeklyBasket|null
     */
    private function resolveCalendarDelivery(
        Partner $partner,
        Basket $basket,
        WeeklyBasketGenerator $generator,
        PartnerDeliveryShiftRepository $shiftRepository,
        EntityManagerInterface $em,
    ): ?WeeklyBasket {
        // Las entregas pasadas no se editan.
        if ($basket->getDate() < new \DateTimeImmutable('today')) {
            $this->addFlash('warning', 'Esa entrega ya pasó: no se puede editar.');

            return null;
        }

        // R1: las cestas compartidas no eligen su día (lo dicta la alternancia
        // con el otro hogar).
        if ($partner->getSharePartner() !== null) {
            $this->addFlash('warning', 'Esta cesta es compartida: su día lo marca la alternancia con el otro hogar, no se edita aquí.');

            return null;
        }

        // Cambio puntual activo esa semana: dejaría el shift desincronizado.
        if ($shiftRepository->findOutgoing($partner, $basket) !== null) {
            $this->addFlash('warning', 'Hay un cambio puntual de día activo esa semana: gestiónalo desde los cambios puntuales.');

            return null;
        }

        $share = $em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $basket->getDate());
        if ($share === null) {
            $this->addFlash('warning', 'El socio no tiene cesta activa esa semana.');

            return null;
        }

        $weeklyBasket = $generator->materializeShareDelivery($basket, $share);
        if ($weeklyBasket === null) {
            $this->addFlash('warning', 'El nodo del socio no reparte esa semana.');

            return null;
        }

        return $weeklyBasket;
    }

    /**
     * Resuelve el mes por defecto del calendario: el de la PRÓXIMA entrega del
     * socio (fecha física ≥ hoy). Escanea desde el mes actual hacia delante hasta
     * 6 meses; si no encuentra ninguna entrega futura (socio sin cesta activa o
     * dada de baja), cae al mes actual.
     *
     * @param Partner                   $partner
     * @param DeliveryCalendarProjector $projector
     * @return array{0: int, 1: int} [año, mes]
     */
    private function resolveNextDeliveryMonth(Partner $partner, DeliveryCalendarProjector $projector): array
    {
        $today = new \DateTimeImmutable('today');
        $base = new \DateTimeImmutable('first day of this month');

        for ($i = 0; $i < 6; $i++) {
            $cursor = $base->modify(sprintf('+%d months', $i));
            $year = (int) $cursor->format('Y');
            $month = (int) $cursor->format('n');
            foreach ($projector->projectMonth($partner, $year, $month) as $slot) {
                if ($slot['date'] >= $today) {
                    return [$year, $month];
                }
            }
        }

        return [(int) $base->format('Y'), (int) $base->format('n')];
    }

    /**
     * Elige el slot del calendario que ocupa el panel lateral: el del Basket
     * pedido (?sel); si no, la primera entrega cuya fecha física cae DENTRO del
     * mes mostrado (no la que se cuela del mes anterior por la fecha física del
     * nodo); como último recurso, la primera del listado. Null si no hay entregas.
     *
     * @param list<array{date: \DateTimeInterface, basket: Basket, ...}> $slots
     * @param int                                                        $selectedBasketId 0 = sin preferencia.
     * @param string                                                     $month            Mes mostrado, formato 'Y-m'.
     * @return array{basket: Basket, ...}|null
     */
    private function resolveSelectedSlot(array $slots, int $selectedBasketId, string $month): ?array
    {
        if ($slots === []) {
            return null;
        }

        if ($selectedBasketId > 0) {
            foreach ($slots as $slot) {
                if ($slot['basket']->getId() === $selectedBasketId) {
                    return $slot;
                }
            }
        }

        foreach ($slots as $slot) {
            if ($slot['date']->format('Y-m') === $month) {
                return $slot;
            }
        }

        return $slots[0];
    }

    /**
     * Candidatos de "mover de día" para el calendario del gestor: los días destino
     * que propone DeliveryShiftCandidates filtrando los que tienen alguna violación
     * bloqueante (no-bypassable). El deadline NO descalifica aquí (lo decide el
     * gestor), igual que en el panel del socio. Una violación bypassable (equilibrio)
     * deja pasar al candidato — se avisará al aplicar.
     *
     * @param Partner                  $partner
     * @param PartnerBasketShare       $activeShare
     * @param Basket                   $from
     * @param DeliveryShiftCandidates  $shiftCandidates
     * @param DeliveryShiftValidator   $validator
     * @return Basket[]
     */
    private function calendarMoveCandidates(
        Partner $partner,
        PartnerBasketShare $activeShare,
        Basket $from,
        DeliveryShiftCandidates $shiftCandidates,
        DeliveryShiftValidator $validator,
    ): array {
        $candidates = [];
        foreach ($shiftCandidates->findFor($activeShare, $from) as $candidate) {
            $violations = array_filter(
                $validator->validate($partner, $from, $candidate),
                static fn ($v): bool => $v->rule !== DeadlineRule::ID,
            );
            if (empty($validator->blocking($violations))) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /**
     * Construye la rejilla mensual del calendario (semanas de lunes a domingo) que
     * pinta la pantalla, colocando cada entrega proyectada en la celda de su fecha
     * física. Las semanas íntegramente fuera del mes se descartan.
     *
     * @param int                                                     $year
     * @param int                                                     $month
     * @param list<array{date: \DateTimeInterface, basket: Basket, ...}> $slots
     * @return list<list<array{date: \DateTimeImmutable, inMonth: bool, slot: array|null}>>
     */
    private function buildMonthWeeks(int $year, int $month, array $slots): array
    {
        // Mapa fecha física (Y-m-d) → slot, para colocar la entrega en su celda.
        $byDay = [];
        foreach ($slots as $slot) {
            $byDay[$slot['date']->format('Y-m-d')] = $slot;
        }

        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $last = $first->modify('last day of this month');
        // Lunes en/antes del día 1 (N: 1=lunes .. 7=domingo).
        $cursor = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');

        $weeks = [];
        do {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $week[] = [
                    'date' => $cursor,
                    'inMonth' => $cursor->format('Y-m') === $first->format('Y-m'),
                    'slot' => $byDay[$cursor->format('Y-m-d')] ?? null,
                ];
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $week;
        } while ($cursor <= $last);

        return $weeks;
    }

    #[Route("/{id}/edit", name: "partner_edit", methods: ["GET","POST"])]
    public function edit(Request $request, Partner $partner, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(PartnerType::class, $partner);

        $form->handleRequest($request);


        if ($form->isSubmitted() && $form->isValid()) {
            /*
             * aquí actualizo el grupo del socio para la última cesta si es que ya ha sido creada
             */
            $basket = $entityManager->getRepository(\App\Entity\Basket::class)->findBasketByWeekYear(date('Y-m-d'));//número de cesta actual
            $partner_weekly_basket = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findOneBy(array("basket" => $basket->getId(), 'partner' => $partner));//para ver si ya la he creado, si está en la tabla weekly_basket la cesta actual para este socio
            if ($partner_weekly_basket) {
                $partner_weekly_basket->setWeeklyBasketGroup($partner->getWeeklyBasketGroup());
                $entityManager->persist($partner_weekly_basket);
            }


            $entityManager->flush();

            return $this->redirectToRoute('partner_show', array('id' => $partner->getId()));
        }

        return $this->render('partner/edit.html.twig', [
            'partner' => $partner,
            'entity' => $partner,
            'form' => $form->createView(),
        ]);
    }

    #[Route("/{id}", name: "partner_delete", methods: ["DELETE"])]
    public function delete(Request $request, Partner $partner, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $partner->getId(), $request->request->get('_token'))) {
            $entityManager->remove($partner);
            $entityManager->flush();
        }

        return $this->redirectToRoute('partner_index');

    }

    #[Route("/{id}/{type}/family", name: "family", methods: ["GET"])]
    public function family(Request $request, Partner $partner, $type, EntityManagerInterface $entityManager): Response
    {
        if ($type == 'add_family') {
            $partners = $entityManager->getRepository(\App\Entity\Partner::class)->findFamiliar($partner);
        } else {
            $baskets = $entityManager->getRepository(\App\Entity\PartnerBasketShare::class)->findBy(array('is_active' => 1, 'basket_share' => 4));
            foreach ($baskets as $basket) {
                if (!$basket->getPartner()->getSharePartner()) //solo muestra los que no están ya relacionados
                {
                    $partners[] = $basket->getPartner();
                }
            }
        }


        return $this->render('partner/family.html.twig', [
            'partner' => $partner,
            'partners' => $partners,
            'type' => $type
        ]);

    }

    #[Route("/{partner1_id}/{partner2_id}/add_family", name: "add_family", methods: ["GET"])]
    public function addFamily(Request $request, $partner1_id, $partner2_id, EntityManagerInterface $entityManager): Response
    {
        $session = new Session();


        if ($partner1_id == $partner2_id) {
            $session->getFlashBag()->add(
                'warning',
                'Has seleccionado la/el misma/o socia/o. Debes seleccionar otra/o'
            );
            return $this->redirectToRoute('partner_show', array('id' => $partner1_id));
        }
        $partner1 = $entityManager->getRepository(\App\Entity\Partner::class)->find($partner1_id);
        $partner2 = $entityManager->getRepository(\App\Entity\Partner::class)->find($partner2_id);
        $partner1->addRelative($partner2);
        foreach ($partner2->getPartnerBasketShares() as $share) {
            $entityManager->remove($share);
        }


        $entityManager->persist($partner1);
        $entityManager->persist($partner2);

        $entityManager->flush();

        $session->getFlashBag()->add(
            'success',
            'Se ha añadido correctamente la/el familiar'
        );
        return $this->redirectToRoute('partner_show', array('id' => $partner1_id));

    }

    #[Route("/{partner1_id}/{partner2_id}/remove_family", name: "remove_family", methods: ["GET"])]
    public function removeFamily(Request $request, $partner1_id, $partner2_id, EntityManagerInterface $entityManager): Response
    {
        $session = new Session();
        $partner1 = $entityManager->getRepository(\App\Entity\Partner::class)->find($partner1_id);
        $partner2 = $entityManager->getRepository(\App\Entity\Partner::class)->find($partner2_id);
        $partner1->removeRelative($partner2);


        $entityManager->persist($partner1);
        $entityManager->persist($partner2);

        $entityManager->flush();
        $session->getFlashBag()->add(
            'success',
            'Se ha quitado correctamente la relación'
        );

        return $this->redirectToRoute('partner_show', array('id' => $partner1_id));
    }


    #[Route("/cities", name: "select_city")]
    public function cities(Request $request, EntityManagerInterface $em): Response
    {
        $state = $em->getRepository(\App\Entity\State::class)->findById($request->get('state_id'));
        $cities = $em->getRepository(\App\Entity\City::class)->findByState($state);

        return $this->render('partner/cities.html.twig', [
            'cities' => $cities,
        ]);
    }

    #[Route("/{id}/demote", name: "partner_demote", methods: ["GET"])]
    public function demote(
        Request $request,
        Partner $partner,
        #[Target('partner_lifecycle')] WorkflowInterface $workflow,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$workflow->can($partner, 'leave')) {
            $this->addFlash('warning', sprintf(
                '%s %s ya está de baja.',
                $partner->getName(),
                $partner->getSurname()
            ));
            return $this->redirectToRoute('partner_show', ['id' => $partner->getId()]);
        }

        // Si tiene una cesta activa, mantenemos el flujo legacy: pasar por la
        // pantalla de finalización para que la gestora elija fecha de fin.
        // El workflow aún no se dispara; lo hace finalize() cuando guarde.
        if ($partner->hasActiveBasket()) {
            $basket_id = $partner->getActiveBasket()->getId();
            return $this->redirectToRoute('partner_basket_share_finalize', ['id' => $basket_id]);
        }

        // Sin cesta activa: la baja es directa. El listener emite PartnerEvent
        // y borra WeeklyBasket futuros (ninguno aquí, pero por consistencia).
        $workflow->apply($partner, 'leave');
        $entityManager->flush();

        $this->addFlash('success', sprintf('%s %s ahora está de baja.', $partner->getName(), $partner->getSurname()));
        return $this->redirectToRoute('partner_show', ['id' => $partner->getId()]);
    }

    #[Route("/{id}/promote", name: "partner_promote", methods: ["GET"])]
    public function promote(
        Request $request,
        Partner $partner,
        #[Target('partner_lifecycle')] WorkflowInterface $workflow,
        EntityManagerInterface $entityManager,
    ): Response {
        $transition = match (true) {
            $workflow->can($partner, 'rejoin') => 'rejoin',
            $workflow->can($partner, 'resume') => 'resume',
            default => null,
        };

        if ($transition === null) {
            $this->addFlash('warning', sprintf(
                '%s %s ya está activx.',
                $partner->getName(),
                $partner->getSurname()
            ));
            return $this->redirectToRoute('partner_show', ['id' => $partner->getId()]);
        }

        $workflow->apply($partner, $transition);
        $entityManager->flush();

        $this->addFlash('success', sprintf('%s %s vuelve a estar activx.', $partner->getName(), $partner->getSurname()));
        return $this->redirectToRoute('partner_show', ['id' => $partner->getId()]);
    }

    /** Transiciones admitidas en la acción masiva y su copia de UI. */
    private const BULK_TRANSITIONS = [
        'leave'  => ['verb' => 'dar de baja', 'past' => 'dadx de baja', 'icon' => 'fa-user-times'],
        'pause'  => ['verb' => 'pausar',      'past' => 'pausadx',      'icon' => 'fa-pause'],
        'resume' => ['verb' => 'reactivar',   'past' => 'reactivadx',   'icon' => 'fa-play'],
        'rejoin' => ['verb' => 'reactivar',   'past' => 'reactivadx',   'icon' => 'fa-undo'],
    ];

    /**
     * Primer paso de una transición masiva: muestra la lista de socixs
     * seleccionadxs para que la gestora pueda confirmar visualmente antes
     * de aplicar nada. Útil sobre todo con paginación, donde no se ve toda
     * la selección de un golpe.
     */
    #[Route("/bulk/transition", name: "partner_bulk_transition", methods: ["POST"])]
    public function bulkTransitionConfirm(
        Request $request,
        PartnerRepository $partnerRepository,
    ): Response {
        if (!$this->isCsrfTokenValid('partner_bulk_transition', $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Token CSRF inválido. Vuelve a intentarlo.');
            return $this->redirectToRoute('partner_index');
        }

        $transition = (string) $request->request->get('transition', '');
        if (!isset(self::BULK_TRANSITIONS[$transition])) {
            $this->addFlash('error', 'Transición masiva desconocida.');
            return $this->redirectToRoute('partner_index');
        }

        $ids = $request->request->all('partner_ids');
        if (empty($ids)) {
            $this->addFlash('warning', 'No has seleccionado ningún socix.');
            return $this->redirectToRoute('partner_index');
        }

        $partners = $partnerRepository->findBy(['id' => $ids], ['surname' => 'ASC', 'name' => 'ASC']);

        return $this->render('partner/bulk_transition_confirm.html.twig', [
            'partners' => $partners,
            'transition' => $transition,
            'copy' => self::BULK_TRANSITIONS[$transition],
        ]);
    }

    /**
     * Segundo paso: aplica la transición elegida a cada socix confirmadx.
     * Los que no admitan la transición (por estar ya en otro estado) se
     * cuentan como ignoradxs.
     */
    #[Route("/bulk/transition/apply", name: "partner_bulk_transition_apply", methods: ["POST"])]
    public function bulkTransitionApply(
        Request $request,
        PartnerRepository $partnerRepository,
        #[Target('partner_lifecycle')] WorkflowInterface $workflow,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('partner_bulk_transition_apply', $request->request->get('_token', ''))) {
            $this->addFlash('error', 'Token CSRF inválido. Vuelve a intentarlo.');
            return $this->redirectToRoute('partner_index');
        }

        $transition = (string) $request->request->get('transition', '');
        if (!isset(self::BULK_TRANSITIONS[$transition])) {
            $this->addFlash('error', 'Transición masiva desconocida.');
            return $this->redirectToRoute('partner_index');
        }

        $ids = $request->request->all('partner_ids');
        if (empty($ids)) {
            $this->addFlash('warning', 'La selección estaba vacía.');
            return $this->redirectToRoute('partner_index');
        }

        $partners = $partnerRepository->findBy(['id' => $ids]);
        $copy = self::BULK_TRANSITIONS[$transition];

        $applied = 0;
        $skipped = 0;
        foreach ($partners as $partner) {
            if ($workflow->can($partner, $transition)) {
                $workflow->apply($partner, $transition);
                $applied++;
            } else {
                $skipped++;
            }
        }
        $em->flush();

        $this->addFlash('success', sprintf(
            '%d socix%s %s%s.',
            $applied,
            $applied === 1 ? '' : 's',
            $copy['past'] . ($applied === 1 ? '' : 's'),
            $skipped > 0 ? sprintf(' (%d no se podían procesar y se ignoraron)', $skipped) : ''
        ));

        return $this->redirectToRoute('partner_index');
    }

    /**
     * Pausa temporal del socio: deja de recibir cesta pero sigue siendo socix.
     * Útil para vacaciones, baja médica, etc. El borrado de WeeklyBasket futuros
     * NO se dispara (a diferencia de "leave"); la pausa es reversible vía promote.
     */
    #[Route("/{id}/pause", name: "partner_pause", methods: ["GET"])]
    public function pause(
        Partner $partner,
        #[Target('partner_lifecycle')] WorkflowInterface $workflow,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$workflow->can($partner, 'pause')) {
            $this->addFlash('warning', 'Solo se puede pausar a un socix activx.');
            return $this->redirectToRoute('partner_show', ['id' => $partner->getId()]);
        }

        $workflow->apply($partner, 'pause');
        $entityManager->flush();

        $this->addFlash('success', sprintf('%s %s queda en pausa.', $partner->getName(), $partner->getSurname()));
        return $this->redirectToRoute('partner_show', ['id' => $partner->getId()]);
    }

    #[Route("/{id}/add_basket", name: "partner_add_basket", methods: ["GET","POST"])]
    public function addBasket(
        Request $request,
        Partner $partner,
        EntityManagerInterface $entityManager,
        PartnerShareEventRecorder $shareEventRecorder,
    ): Response {
        $partnerBasketShare = new PartnerBasketShare();
        $partnerBasketShare->setPartner($partner);
        $form = $this->createForm(PartnerBasketShareType::class, $partnerBasketShare);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $partnerBasketShare->setStartDate(new \DateTime($partnerBasketShare->getStartDate()));
            $partnerBasketShare->setIsActive(true);
            $values = $request->get('partner_basket_share');
            if (isset($values['isFreeBasket'])) {

                $partnerBasketShare->setMonthPrice(0);
                $partnerBasketShare->setEggMonthPrice(0);
            } else {

                $partnerBasketShare->setMonthPrice($partnerBasketShare->getBasketShare()->getMonthPrice() * $partnerBasketShare->getAmount());
                if ($partnerBasketShare->getEggAmount()) {
                    $partnerBasketShare->setEggMonthPrice($partnerBasketShare->getEggAmount()->getMonthPrice() * $partnerBasketShare->getAmount());
                } else {
                    $partnerBasketShare->setEggMonthPrice(0);
                }
            }

            if ($partnerBasketShare->getBasketShare()->getId() == 3) {

            } else {
                $partnerBasketShare->setDayMonthOrder(null);
            }
            $entityManager->persist($partnerBasketShare);
            $shareEventRecorder->recordStart($partnerBasketShare);


            $basket = $entityManager->getRepository(\App\Entity\Basket::class)->findBasketByWeekYear(date('Y-m-d'));//número de cesta actual
            if ($partnerBasketShare->getStartDate() <= $basket->getDate()) {
                $control_weekly_basket = $entityManager->getRepository(\App\Entity\WeeklyBasket::class)->findBy(array("basket" => $basket->getId()));//para ver si ya la he creado, si está en la tabla weekly_basket la cesta actual
                if ($control_weekly_basket) {//si ya está creada la lista de esta semana, hay que añadir un registro más con el nuevo socio que empieza esta semana
                    $weekly_basket = new WeeklyBasket();
                    $weekly_basket->setBasket($basket);
                    $weekly_basket->setPartner($partner);
                    $weekly_basket->setAmount($partnerBasketShare->getAmount());
                    $weekly_basket_status = $entityManager->getRepository(\App\Entity\WeeklyBasketStatus::class)->find(1);
                    $weekly_basket->setWeeklyBasketStatus($weekly_basket_status);
                    $weekly_basket->setBasketShare($partnerBasketShare->getBasketShare());
                    // Fallback Torremocha (viernes-ciclo). Deuda: en cuanto entren altas en Madrid,
                    // este controller debe inyectar NodeDeliveryDate y resolver la fecha real del nodo.
                    $weekly_basket->setDeliveryDate($basket->getDate());
                    $entityManager->persist($weekly_basket);
                }
            }

            $entityManager->flush();

            return $this->redirectToRoute('partner_show', array('id' => $partner->getId()));
        }

        return $this->render('partner_basket_share/new.html.twig', [
            'partner_basket_share' => $partnerBasketShare,
            'entity' => $partnerBasketShare,
            'form' => $form->createView(),
            'partner' => $partner
        ]);


    }


    #[Route("/{partner1_id}/{partner2_id}/add_share_partner", name: "add_share_partner", methods: ["GET"])]
    public function addSharePartner(Request $request, $partner1_id, $partner2_id, EntityManagerInterface $entityManager): Response
    {
        $session = new Session();
        if ($partner1_id == $partner2_id) {
            $session->getFlashBag()->add(
                'warning',
                'Has seleccionado la/el misma/o socia/o. Debes seleccionar otra/o'
            );
            return $this->redirectToRoute('partner_show', array('id' => $partner1_id));
        }
        $partner1 = $entityManager->getRepository(\App\Entity\Partner::class)->find($partner1_id);
        $partner2 = $entityManager->getRepository(\App\Entity\Partner::class)->find($partner2_id);

        $partner1->setSharePartner($partner2);
        $partner2->setSharePartner($partner1);

        $entityManager->persist($partner1);
        $entityManager->persist($partner2);

        $entityManager->flush();

        $session->getFlashBag()->add(
            'success',
            'Se ha añadido correctamente la/el compañera/o para compartir cesta'
        );
        return $this->redirectToRoute('partner_show', array('id' => $partner1_id));


    }

    #[Route("/{partner1_id}/{partner2_id}/remove_share_partner", name: "remove_share_partner", methods: ["GET"])]
    public function removeSharePartner(Request $request, $partner1_id, $partner2_id, EntityManagerInterface $entityManager): Response
    {
        $session = new Session();

        $partner1 = $entityManager->getRepository(\App\Entity\Partner::class)->find($partner1_id);
        $partner2 = $entityManager->getRepository(\App\Entity\Partner::class)->find($partner2_id);
        $partner1->setSharePartner(null);
        $partner2->setSharePartner(null);

        $entityManager->persist($partner1);
        $entityManager->persist($partner2);

        $entityManager->flush();

        $session->getFlashBag()->add(
            'success',
            'Se ha eliminado correctamente la relación'
        );
        return $this->redirectToRoute('partner_show', array('id' => $partner1_id));
    }



    #[Route("/generate_historical", name: "partner_generate_historical", methods: ["GET"])]
    public function generateHistorical(EntityManagerInterface $entityManager)
    {
       $partners=$entityManager->getRepository(\App\Entity\Partner::class)->findAll();

       foreach ($partners as $partner)
       {
          $start_date=$partner->getInscriptionDate();
          if ($partner->getPartnerBasketShares()){}
          //$initial_basket=;
       }





        return $this->render('partner/evolution.html.twig', [

        ]);

    }



}

