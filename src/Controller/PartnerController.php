<?php

namespace App\Controller;

use App\Custom\WeekOfMonth;
use App\Entity\BasketShare;
use App\Entity\EggAmount;
use App\Entity\EggPeriod;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketExtra;
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
use App\Service\Delivery\CohortChoiceBuilder;
use App\Service\Delivery\DeliveryCalendarProjector;
use App\Service\Delivery\DeliveryCalendarViewBuilder;
use App\Service\Delivery\DeliveryShiftApplier;
use App\Service\Delivery\PartnerMonthResetter;
use App\Repository\WeeklyBasketGroupRepository;
use App\Security\MagicLinkMailer;
use App\Security\PartnerAccessPolicy;
use App\Security\PartnerUserProvisioner;
use App\Service\Delivery\ExtraBasketEditor;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Delivery\PickupRelocationOptions;
use App\Service\Delivery\PickupRelocator;
use App\Service\Delivery\WeeklyBasketComponentEditor;
use App\Service\Delivery\WeeklyBasketGenerator;
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
        // El filtro "por confirmar" es transversal al estado: los pendientes
        // están en baja, así que al activarlo ignoramos la pestaña de estado
        // para verlos todos (si no, ACTIVO + por confirmar daría vacío).
        $needsReview = $request->query->getBoolean('needs_review');

        // Pestaña por defecto: ACTIVO. Para ver el listado completo el call
        // site tiene que pedir explícitamente ?status=ALL.
        $rawStatus = $request->query->get('status', Partner::STATUS_ACTIVO);
        $statusFilter = $needsReview ? null : (in_array($rawStatus, Partner::STATUSES, true) ? $rawStatus : null);

        // Cast manual de node/wbg porque $request->query->getInt() lanza
        // BadRequestException si el valor es "" (string vacío), cosa que el
        // form GET envía siempre que el radio "Cualquier X" está marcado.
        $filters = [
            'status'     => $statusFilter,
            'modalities' => array_values(array_filter(array_map('intval', (array) $request->query->all('modality')))),
            'cesta'      => in_array($request->query->get('cesta'), ['yes', 'no'], true) ? $request->query->get('cesta') : null,
            'eggs'       => in_array($request->query->get('eggs'), ['yes', 'no'], true) ? $request->query->get('eggs') : null,
            'node'       => ((int) $request->query->get('node', '')) ?: null,
            'wbg'        => ((int) $request->query->get('wbg', '')) ?: null,
            'q'          => trim((string) $request->query->get('q', '')) ?: null,
            'needs_review' => $needsReview ?: null,
        ];

        $qb = $partnerRepository->findFilteredQb($filters);

        $pagination = $paginator->paginate(
            $qb->getQuery(),
            $request->query->getInt('page', 1),
            25,
            [
                // Orden por defecto: nombre asc (la columna "Socix" muestra
                // "nombre apellido", así que ordenar por nombre es lo que el
                // usuario espera). Apellido como desempate estable: Knp encadena
                // varios campos con '+', no admite array (lanzaría
                // InvalidValueException "Cannot sort with array parameter").
                'defaultSortFieldName' => 'p.name+p.surname',
                'defaultSortDirection' => 'asc',
                // Sólo estos valores exactos pueden llegar por ?sort= (anti-inyección);
                // el subscriber compara el string completo contra esta lista.
                'sortFieldWhitelist'   => ['p.name+p.surname', 'p.name', 'p.inscription_date', 'p.status'],
            ]
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

        // Pendientes de confirmar: alimenta el chip de acceso del listado.
        $needsReviewCount = (int) $partnerRepository->count(['needs_review' => true]);

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
            'needs_review_count' => $needsReviewCount,
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
        // Alta sólo con datos de persona: la cesta Y el grupo de recogida se
        // asignan después desde la ficha ("añadir cesta"), que es el único
        // camino de creación de cesta (pasa por reconcile). Pedido por
        // administración (reunión 2026-06-11, punto 3).
        $form = $this->createForm(PartnerType::class, $partner, [
            'include_pickup_group' => false,
        ]);
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
        BasketRepository $basketRepo,
        NodeDeliveryDate $nodeDeliveryDate,
        PickupRelocationOptions $relocationOptions,
        PartnerAccessPolicy $accessPolicy,
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

        // Cestas que este socix paga (dona) a otrxs: PBS activas cuyo
        // payer_partner es él. Se muestra como bloque de sólo lectura en su
        // ficha; el origen de la verdad (asignar/quitar pagador) vive en la
        // ficha del receptor.
        $donatedBaskets = $em->getRepository(PartnerBasketShare::class)
            ->findBy(['payer_partner' => $partner, 'is_active' => 1]);

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
            'extra_weeks' => $this->extraBasketWeeks($partner, $basketRepo, $nodeDeliveryDate),
            'relocate_weeks' => $relocationOptions->weeksForPartner($partner),
            'relocate_groups' => $relocationOptions->groupsForPartner($partner),
            'donated_baskets' => $donatedBaskets,
            // Cuenta utilizable por email canónico (puede ser la de un familiar
            // con buzón compartido, a diferencia de linked_user que va por la
            // relación User->Partner). Alimenta la tarjeta "Acceso a la web".
            'access_user' => $accessPolicy->accountFor($partner),
            'self_registration_open' => $accessPolicy->isSelfRegistrationOpen(),
        ]);
    }

    /**
     * Da acceso a la web a este socix aunque el alta global esté cerrada:
     * provisiona su User (o recupera el existente) saltándose el toggle de
     * configuración, y opcionalmente le envía el magic-link en el momento.
     * Es el mecanismo de despliegue selectivo previo a abrir el grifo.
     *
     * @param Request                $request     Lleva send_link (opcional) y el token CSRF.
     * @param Partner                $partner     Socix (de la ruta).
     * @param PartnerUserProvisioner $provisioner
     * @param MagicLinkMailer        $magicLinkMailer
     */
    #[Route("/{id}/grant-access", name: "partner_grant_access", methods: ["POST"], requirements: ["id" => "\\d+"])]
    #[IsGranted('ROLE_ADMIN')]
    public function grantAccess(
        Request $request,
        Partner $partner,
        PartnerUserProvisioner $provisioner,
        PartnerAccessPolicy $accessPolicy,
        MagicLinkMailer $magicLinkMailer,
    ): Response {
        $back = ['id' => $partner->getId()];

        if (!$this->isCsrfTokenValid('partner_grant_access', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');
            return $this->redirectToRoute('partner_show', $back);
        }

        // Distinguir crear de recuperar: con un doble submit el flash no debe
        // decir "creado" sobre una cuenta que ya existía.
        $alreadyExisted = $accessPolicy->accountFor($partner) !== null;

        $user = $provisioner->provision($partner);
        if ($user === null) {
            $this->addFlash('warning', 'No se puede dar acceso: el socix no tiene email.');
            return $this->redirectToRoute('partner_show', $back);
        }

        $state = $alreadyExisted ? 'La cuenta ya existía' : 'Acceso creado';
        if ($request->request->getBoolean('send_link')) {
            $magicLinkMailer->send($user);
            $this->addFlash('success', sprintf('%s; enlace enviado a %s.', $state, $user->getEmail()));
        } else {
            $this->addFlash('success', sprintf('%s para %s (sin enviar nada).', $state, $user->getEmail()));
        }

        return $this->redirectToRoute('partner_show', $back);
    }

    /**
     * (Re)envía el magic-link de acceso a la cuenta de este socix. Para
     * socixs sin cuenta está el botón "dar acceso" ({@see grantAccess()}).
     *
     * @param Request             $request         Lleva el token CSRF.
     * @param Partner             $partner         Socix (de la ruta).
     * @param PartnerAccessPolicy $accessPolicy
     * @param MagicLinkMailer     $magicLinkMailer
     */
    #[Route("/{id}/send-access-link", name: "partner_send_access_link", methods: ["POST"], requirements: ["id" => "\\d+"])]
    #[IsGranted('ROLE_ADMIN')]
    public function sendAccessLink(
        Request $request,
        Partner $partner,
        PartnerAccessPolicy $accessPolicy,
        MagicLinkMailer $magicLinkMailer,
    ): Response {
        $back = ['id' => $partner->getId()];

        if (!$this->isCsrfTokenValid('partner_send_access_link', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');
            return $this->redirectToRoute('partner_show', $back);
        }

        $user = $accessPolicy->accountFor($partner);
        if ($user === null) {
            $this->addFlash('warning', 'Este socix aún no tiene cuenta de acceso. Usa "Dar acceso" primero.');
            return $this->redirectToRoute('partner_show', $back);
        }

        // Una cuenta bloqueada no puede entrar (UserChecker la corta en el
        // login): enviar el enlace sería un fallo silencioso para el admin.
        if (!$user->isEnabled()) {
            $this->addFlash('warning', sprintf('La cuenta %s está bloqueada. Desbloquéala antes de enviarle el enlace.', $user->getEmail()));
            return $this->redirectToRoute('partner_show', $back);
        }

        if (!$magicLinkMailer->send($user)) {
            $this->addFlash('warning', 'La cuenta no tiene email; no se ha podido enviar el enlace.');
            return $this->redirectToRoute('partner_show', $back);
        }

        $this->addFlash('success', sprintf('Enlace de acceso enviado a %s.', $user->getEmail()));
        return $this->redirectToRoute('partner_show', $back);
    }

    /**
     * Traslada puntualmente la recogida de un socix a otro nodo/grupo en una semana
     * concreta (la cesta que ya tiene esa semana). El socix viene de la ruta; el gestor
     * elige semana y grupo destino. Delega en PickupRelocator.
     *
     * @param Request           $request   Lleva basket_id, group_id y el token CSRF.
     * @param Partner           $partner   Socix (de la ruta).
     * @param BasketRepository  $basketRepo
     * @param WeeklyBasketGroupRepository $groupRepo
     * @param PickupRelocator   $relocator
     */
    #[Route("/{id}/relocate-pickup", name: "partner_relocate_pickup", methods: ["POST"])]
    public function relocatePickup(
        Request $request,
        Partner $partner,
        BasketRepository $basketRepo,
        WeeklyBasketGroupRepository $groupRepo,
        PickupRelocator $relocator,
    ): Response {
        $back = ['id' => $partner->getId()];

        if (!$this->isCsrfTokenValid('partner_relocate_pickup', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');
            return $this->redirectToRoute('partner_show', $back);
        }

        $basket = $basketRepo->find((int) $request->request->get('basket_id'));
        $group = $groupRepo->find((int) $request->request->get('group_id'));
        if (!$basket instanceof Basket || !$group instanceof WeeklyBasketGroup) {
            $this->addFlash('warning', 'Faltan datos: la semana o el grupo de destino.');
            return $this->redirectToRoute('partner_show', $back);
        }

        try {
            $relocator->relocate($partner, $basket, $group, 'gestor:' . $this->getUser()->getId());
        } catch (\LogicException $e) {
            $this->addFlash('warning', $e->getMessage());
            return $this->redirectToRoute('partner_show', $back);
        }

        $this->addFlash('success', sprintf('Traslado hecho: esa semana recogerá en "%s".', $group->getName()));
        return $this->redirectToRoute('partner_show', $back);
    }

    /**
     * Semanas (Basket) a las que se puede añadir una cesta extra a este socix desde su
     * ficha: las próximas en las que su NODO REPARTE (dibujadas o generadas). La cesta extra
     * es un override ({@see \App\Entity\PartnerBasketExtra}), así que se puede planificar a
     * futuro: la proyección la suma al dibujar y la materialización al congelar, sin piedra
     * parcial. La etiqueta usa la fecha física del nodo (null = el nodo no reparte esa semana,
     * p. ej. quincenal fuera de fase → se omite).
     *
     * @param Partner          $partner
     * @param BasketRepository $basketRepo
     * @param NodeDeliveryDate $nodeDeliveryDate
     * @return list<array{id:int, date:\DateTimeInterface}> Semanas ofrecibles, en orden.
     */
    private function extraBasketWeeks(
        Partner $partner,
        BasketRepository $basketRepo,
        NodeDeliveryDate $nodeDeliveryDate,
    ): array {
        $node = $partner->getWeeklyBasketGroup()?->getNode();
        $from = new \DateTime('today');
        $to = (clone $from)->modify('+10 weeks');

        $weeks = [];
        foreach ($basketRepo->findBetweenDates($from, $to) as $basket) {
            // Semanas en las que el nodo del socio REPARTE (físicamente): physicalDateFor da
            // null si esa semana no le toca (cadencia/excepción), y entonces se omite. No se
            // exige que esté generada: el override funciona también sobre el dibujo.
            $physical = $node !== null ? $nodeDeliveryDate->physicalDateFor($basket, $node) : null;
            if ($physical === null) {
                continue;
            }
            $weeks[] = ['id' => $basket->getId(), 'date' => $physical];
        }

        return $weeks;
    }

    /**
     * Añade (o ajusta) la cesta extra puntual de un socix en una semana concreta,
     * desde su ficha. El socix viene fijado por la ruta; el gestor elige la semana
     * (cualquiera en que su nodo reparta, dibujada o generada — ver extraBasketWeeks)
     * y la composición final (cestas de verdura + docenas de huevos). Delega en
     * ExtraBasketEditor.
     *
     * @param Request           $request Lleva basket_id, cestas, docenas, nota y CSRF.
     * @param Partner           $partner Socix (de la ruta).
     * @param BasketRepository  $basketRepo
     * @param ExtraBasketEditor $extraBasketEditor
     */
    #[Route("/{id}/extra-basket", name: "partner_add_extra_basket", methods: ["POST"])]
    public function addExtraBasket(
        Request $request,
        Partner $partner,
        BasketRepository $basketRepo,
        ExtraBasketEditor $extraBasketEditor,
    ): Response {
        $back = ['id' => $partner->getId()];

        if (!$this->isCsrfTokenValid('partner_extra_basket', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');
            return $this->redirectToRoute('partner_show', $back);
        }

        $basket = $basketRepo->find((int) $request->request->get('basket_id'));
        if (!$basket instanceof Basket) {
            $this->addFlash('warning', 'Falta la semana sobre la que añadir la cesta extra.');
            return $this->redirectToRoute('partner_show', $back);
        }

        $addAmounts = [
            BasketComponent::ID_VEGETABLES => (string) $request->request->get('cestas', '0'),
            BasketComponent::ID_EGGS => (string) $request->request->get('docenas', '0'),
        ];
        if ((float) $addAmounts[BasketComponent::ID_VEGETABLES] <= 0
            && (float) $addAmounts[BasketComponent::ID_EGGS] <= 0) {
            $this->addFlash('warning', 'Indica al menos una cantidad de cestas o de huevos a añadir.');
            return $this->redirectToRoute('partner_show', $back);
        }

        $note = trim((string) $request->request->get('nota', '')) ?: null;

        try {
            $extraBasketEditor->addToDelivery(
                $partner,
                $basket,
                $addAmounts,
                $note,
                'gestor:' . $this->getUser()->getId(),
            );
        } catch (\LogicException $e) {
            $this->addFlash('warning', $e->getMessage());
            return $this->redirectToRoute('partner_show', $back);
        }

        $this->addFlash('success', sprintf('Cesta extra registrada para la semana del %s.', $basket->getDate()?->format('d/m/Y')));
        return $this->redirectToRoute('partner_show', $back);
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
        DeliveryCalendarViewBuilder $viewBuilder,
    ): Response {
        // El gestor puede arrastrar (withDropTargets: true). La resolución del mes,
        // la proyección, la papelera y la rejilla viven en el builder compartido.
        $month = $request->query->has('month') ? (int) $request->query->get('month') : null;
        $year = $request->query->has('year') ? (int) $request->query->get('year') : null;
        $selectedBasketId = (int) $request->query->get('sel', 0);

        $view = $viewBuilder->build($partner, $year, $month, $selectedBasketId, withDropTargets: true);

        return $this->render('partner/delivery_calendar.html.twig', $view);
    }

    /**
     * Mueve toda la entrega de una semana a otra desde el calendario del gestor. No
     * hay "deshacer": mover una cesta de vuelta a su día de patrón es simplemente
     * otro destino (lo resuelve DeliveryShiftApplier::move). Reusa el applier de
     * PartnerDeliveryShift, pero NO la ventana estrecha del autoservicio (WindowRule):
     * el gestor puede determinar cualquier combinación de repartos (caso Franco).
     *
     * Guardas estructurales: no compartido (R1); la cesta se mueve hasta el DÍA
     * ANTERIOR a su recogida (no el mismo día ni después); el nodo reparte el día
     * destino y éste es futuro; y el destino está libre, salvo que sea el día de
     * patrón de la propia cesta (volver atrás), que sí lleva una entrega "saltada"
     * de origen del cambio.
     *
     * @param Partner                   $partner
     * @param int                       $basketId  Semana (Basket) donde la cesta está ahora.
     * @param Request                   $request
     * @param BasketRepository          $basketRepository
     * @param WeeklyBasketGenerator     $generator Para la fecha física y que el nodo reparta.
     * @param DeliveryCalendarProjector $projector Para la ocupación del destino (misma regla que los candidatos).
     * @param DeliveryShiftApplier      $applier
     * @param EntityManagerInterface    $em
     * @return Response
     */
    #[Route("/{id}/calendar/move/{basketId}", name: "partner_delivery_calendar_move", methods: ["POST"], requirements: ["id" => "\\d+", "basketId" => "\\d+"])]
    public function deliveryCalendarMove(
        Partner $partner,
        int $basketId,
        Request $request,
        BasketRepository $basketRepository,
        WeeklyBasketGenerator $generator,
        DeliveryCalendarProjector $projector,
        DeliveryShiftApplier $applier,
        EntityManagerInterface $em,
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

        // R1: las cestas compartidas no cambian de día.
        if ($partner->getSharePartner() !== null) {
            $this->addFlash('warning', 'Esta cesta es compartida: su día lo marca la alternancia con el otro hogar, no se mueve aquí.');

            return $backToCalendar();
        }

        $share = $em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $from->getDate());
        if ($share === null) {
            $this->addFlash('error', 'El socio no tiene cesta activa.');

            return $backToCalendar();
        }

        $today = new \DateTimeImmutable('today');

        // Deadline: la cesta se mueve hasta el día ANTERIOR a su recogida. Si se
        // recoge hoy (o ya pasó), no se mueve. Se mide sobre la fecha FÍSICA.
        $fromPhysical = $generator->projectShareDelivery($from, $share)['deliveryDate'] ?? $from->getDate();
        if ($fromPhysical <= $today) {
            $this->addFlash('warning', 'Esa cesta se recoge hoy o ya pasó: ya no se puede mover.');

            return $backToCalendar();
        }

        $to = $basketRepository->find((int) $request->request->get('to_basket_id', 0));
        if ($to === null) {
            $this->addFlash('error', 'Selecciona una fecha destino válida.');

            return $backToCalendar();
        }

        // Invariante estructural (sustituye a WindowRule para el gestor): el nodo del
        // socio debe repartir el día destino.
        $toProjection = $generator->projectShareDelivery($to, $share);
        if ($toProjection === null) {
            $this->addFlash('error', 'El nodo del socio no reparte ese día: elige otra fecha.');

            return $backToCalendar();
        }

        // El destino también debe ser futuro (mismo deadline del día anterior).
        $toPhysical = $toProjection['deliveryDate'] ?? $to->getDate();
        if ($toPhysical <= $today) {
            $this->addFlash('error', 'No puedes mover la cesta a hoy ni a una fecha pasada.');

            return $backToCalendar();
        }

        // ¿Mover SOLO un componente (verdura u huevos)? Caso SANTOS: la verdura se mueve
        // a una semana que ya tiene huevo, y el huevo de esa semana se respeta. El choque
        // es por COMPONENTE (el destino no puede llevar YA ese componente; los demás sí).
        $componentId = (int) $request->request->get('component_id', 0);
        if (!in_array($componentId, [BasketComponent::ID_VEGETABLES, BasketComponent::ID_EGGS], true)) {
            $componentId = 0;
        }
        if ($componentId !== 0) {
            $component = $em->getRepository(BasketComponent::class)->find($componentId);
            $label = $componentId === BasketComponent::ID_EGGS ? 'huevos' : 'verdura';

            // Único choque real: que el socio ya recoja ESE componente ese día (duplicado).
            // Un día "no recoge"/vacío es destino válido — mover el componente ahí lo revive.
            $alreadyHas = false;
            foreach ($projector->projectMonth($partner, (int) $to->getDate()->format('Y'), (int) $to->getDate()->format('n')) as $s) {
                if ($s['basket']->getId() !== $to->getId()) {
                    continue;
                }
                foreach ($s['items'] as $line) {
                    if ($line['component']->getId() === $componentId) {
                        $alreadyHas = true;
                        break;
                    }
                }
                break;
            }
            if ($alreadyHas) {
                $this->addFlash('error', sprintf('El socio ya recoge %s ese día.', $label));

                return $backToCalendar();
            }

            try {
                $applier->moveComponent($partner, $component, $from, $to, actor: 'gestor:' . $this->getUser()?->getId());
            } catch (\LogicException $e) {
                $this->addFlash('error', $e->getMessage());

                return $backToCalendar();
            }

            $this->addFlash('success', sprintf(
                'Movida la %s del %s al %s.',
                $label,
                $from->getDate()->format('d/m/Y'),
                $to->getDate()->format('d/m/Y'),
            ));

            return $this->redirectToRoute('partner_delivery_calendar', [
                'id' => $partner->getId(),
                'year' => $to->getDate()->format('Y'),
                'month' => $to->getDate()->format('n'),
                'sel' => $to->getId(),
            ]);
        }

        // Sin choque: el destino no puede tener ya una entrega REAL del socio (ítems no
        // vacíos) — un hueco por día. Un día vacío / "no recoge" SÍ vale (mover la cesta
        // ahí lo revive). Excepción: el día ORIGINAL de la propia cesta (volver = quitar
        // el cambio). Se usa la proyección de mes (shift-aware) para ver también entregas
        // previstas sin materializar.
        $shiftRepo = $em->getRepository(\App\Entity\PartnerDeliveryShift::class);
        $patternOrigin = $shiftRepo->findIncoming($partner, $from)?->getFromBasket() ?? $from;
        $toDate = $to->getDate();
        foreach ($projector->projectMonth($partner, (int) $toDate->format('Y'), (int) $toDate->format('n')) as $s) {
            if ($s['basket']->getId() !== $to->getId()) {
                continue;
            }
            if ($to->getId() !== $patternOrigin->getId() && !empty($s['items'])) {
                $this->addFlash('error', 'El socio ya tiene una entrega ese día.');

                return $backToCalendar();
            }
            break;
        }

        try {
            $applier->move($partner, $from, $to, actor: 'gestor:' . $this->getUser()?->getId());
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());

            return $backToCalendar();
        }

        if ($to->getId() === $patternOrigin->getId()) {
            $this->addFlash('success', sprintf('La cesta vuelve a su día: %s.', $to->getDate()->format('d/m/Y')));
        } else {
            $this->addFlash('success', sprintf(
                'Cesta movida del %s al %s.',
                $from->getDate()->format('d/m/Y'),
                $to->getDate()->format('d/m/Y'),
            ));
        }

        // Tras mover, el día con la cesta pasa a ser el destino: se selecciona ese.
        return $this->redirectToRoute('partner_delivery_calendar', [
            'id' => $partner->getId(),
            'year' => $to->getDate()->format('Y'),
            'month' => $to->getDate()->format('n'),
            'sel' => $to->getId(),
        ]);
    }

    /**
     * Recuperar una entrega aparcada ("no recoge") COLOCÁNDOLA en un día ELEGIDO. A
     * diferencia de des-saltar (que la devuelve a su día de patrón), aquí el gestor arrastra
     * la tarjeta de la papelera a una celda libre concreta y la cesta se coloca AHÍ. Quita
     * el acople "se recupera siempre sobre el origen": la papelera deja de estar atada al día.
     *
     * Mecánica: cancelar el intent de "no recoge" y, si el destino es OTRO día, mover la
     * cesta de su día de patrón (origen) al elegido (move = shift patrón→destino, que
     * materializa la entrega con su composición de patrón). Si el destino ES el origen,
     * simplemente se re-materializa allí.
     *
     * @param Partner                        $partner
     * @param int                            $basketId  Semana ORIGEN (la del intent "no recoge").
     * @param Request                        $request
     * @param BasketRepository               $basketRepository
     * @param WeeklyBasketGenerator          $generator
     * @param PartnerDeliveryShiftRepository $shiftRepository
     * @param DeliveryShiftApplier           $applier
     * @param EntityManagerInterface         $em
     * @return Response
     */
    #[Route("/{id}/calendar/recover/{basketId}", name: "partner_delivery_calendar_recover", methods: ["POST"], requirements: ["id" => "\\d+", "basketId" => "\\d+"])]
    public function deliveryCalendarRecover(
        Partner $partner,
        int $basketId,
        Request $request,
        BasketRepository $basketRepository,
        WeeklyBasketGenerator $generator,
        PartnerDeliveryShiftRepository $shiftRepository,
        DeliveryShiftApplier $applier,
        EntityManagerInterface $em,
    ): Response {
        $origin = $basketRepository->find($basketId);
        if ($origin === null) {
            throw $this->createNotFoundException('Semana de reparto no encontrada.');
        }

        $backToCalendar = fn (Basket $sel): Response => $this->redirectToRoute('partner_delivery_calendar', [
            'id' => $partner->getId(),
            'year' => $sel->getDate()->format('Y'),
            'month' => $sel->getDate()->format('n'),
            'sel' => $sel->getId(),
        ]);

        if (!$this->isCsrfTokenValid('calendar_move_' . $partner->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $backToCalendar($origin);
        }

        $skip = $shiftRepository->findOutgoing($partner, $origin);
        if ($skip === null || !$skip->isSkip()) {
            $this->addFlash('warning', 'Esa entrega ya no está aparcada.');

            return $backToCalendar($origin);
        }

        $share = $em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $origin->getDate());
        if ($share === null) {
            $this->addFlash('error', 'El socio no tiene cesta activa.');

            return $backToCalendar($origin);
        }

        $to = $basketRepository->find((int) $request->request->get('to_basket_id', 0));
        if ($to === null) {
            $this->addFlash('error', 'Selecciona una fecha destino válida.');

            return $backToCalendar($origin);
        }

        $today = new \DateTimeImmutable('today');
        $toProjection = $generator->projectShareDelivery($to, $share);
        if ($toProjection === null) {
            $this->addFlash('error', 'El nodo del socio no reparte ese día: elige otra fecha.');

            return $backToCalendar($origin);
        }
        if (($toProjection['deliveryDate'] ?? $to->getDate()) <= $today) {
            $this->addFlash('error', 'No puedes recuperar la cesta en hoy ni en una fecha pasada.');

            return $backToCalendar($origin);
        }

        $actor = 'gestor:' . $this->getUser()?->getId();

        // ¿La cesta aparcada era de SOLO-HUEVO? Lo es si en su semana ORIGEN el socio NO era
        // candidato a cesta (caso SANTOS: huevo semanal, verdura mensual → sus semanas sin
        // verdura). Sin esto, recuperarla sobre una semana de cesta le recompondría verdura
        // de la nada. projectForBasket = candidatos de patrón (cohorte/cadencia/nodo),
        // agnóstico a shifts; el applier no puede pedirlo (dependería del generador → ciclo).
        $originIsCestaWeek = false;
        foreach ($generator->projectForBasket($origin) as $candidate) {
            if ($candidate->getPartner()->getId() === $partner->getId()) {
                $originIsCestaWeek = true;
                break;
            }
        }

        // Colocar en el día ELEGIDO re-apuntando el propio intent (no se toca el día origen
        // ni una posible cesta movida encima de él). recoverSkipToDay cubre también el caso
        // "de vuelta a su propio día".
        $applier->recoverSkipToDay($skip, $to, !$originIsCestaWeek, $actor);
        $this->addFlash('success', $to->getId() === $origin->getId()
            ? sprintf('Recuperada en su día: %s.', $origin->getDate()->format('d/m/Y'))
            : sprintf('Recuperada el %s.', $to->getDate()->format('d/m/Y')));

        return $backToCalendar($to);
    }

    /**
     * Resetear a su PATRÓN el calendario de recogida de un socio en un mes: deshace los
     * cambios puntuales (mover / no recoge / recuperar) y los estados enredados, dejando las
     * entregas como las generaría el listado. Red de seguridad para cuando algo se rompe.
     *
     * Accesible a quien gestiona socixs (la guarda de clase #[IsGranted('ROLE_GESTION_SOCIXS')]
     * ya lo cubre). R1: en compartidas no aplica (lo rechaza el servicio).
     *
     * @param Partner               $partner
     * @param Request               $request
     * @param PartnerMonthResetter  $resetter
     * @return Response
     */
    #[Route("/{id}/calendar/reset", name: "partner_delivery_calendar_reset", methods: ["POST"], requirements: ["id" => "\\d+"])]
    public function deliveryCalendarReset(
        Partner $partner,
        Request $request,
        PartnerMonthResetter $resetter,
    ): Response {
        $year = (int) $request->request->get('year');
        $month = (int) $request->request->get('month');

        $backToCalendar = fn (): Response => $this->redirectToRoute('partner_delivery_calendar', [
            'id' => $partner->getId(),
            'year' => $year ?: null,
            'month' => $month ?: null,
        ]);

        if (!$this->isCsrfTokenValid('calendar_reset_' . $partner->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $backToCalendar();
        }
        if ($month < 1 || $month > 12 || $year < 2000) {
            $this->addFlash('error', 'Mes inválido.');

            return $backToCalendar();
        }

        try {
            $resetter->reset($partner, $year, $month);
        } catch (\LogicException $e) {
            $this->addFlash('warning', $e->getMessage());

            return $backToCalendar();
        }

        $this->addFlash('success', 'Calendario del mes restaurado a su patrón.');

        return $backToCalendar();
    }

    /**
     * Saltar / volver a recoger ("papelera") una entrega del calendario. El "no recoge"
     * es un INTENT durable sin destino (PartnerDeliveryShift to=null): LIBERA el día
     * (applySkipIntent retira el WeeklyBasket si la semana estaba generada) y la entrega
     * aparcada vive solo en el intent, recuperable. Volver a recoger cancela el intent y,
     * si la semana está generada, re-materializa la entrega para devolver al socio al
     * listado. Mismo mecanismo para semanas generadas y sin generar — el día queda libre
     * en ambos casos, sin WB clavado en estado "no recoge".
     *
     * Guardas: R1 (los compartidos no editan su día), socio con cesta activa, no pasada.
     * Un cambio de DÍA (mover) activo esa semana se gestiona desde "mover", no aquí. Sin
     * deadline: es el gestor.
     *
     * @param Partner                        $partner
     * @param int                            $basketId Semana (Basket) sobre la que actuar.
     * @param Request                        $request
     * @param WeeklyBasketGenerator          $generator
     * @param PartnerDeliveryShiftRepository $shiftRepository
     * @param DeliveryShiftApplier           $applier
     * @param EntityManagerInterface         $em
     * @return Response
     */
    #[Route("/{id}/calendar/skip/{basketId}", name: "partner_delivery_calendar_skip", methods: ["POST"], requirements: ["id" => "\\d+", "basketId" => "\\d+"])]
    public function deliveryCalendarSkip(
        Partner $partner,
        int $basketId,
        Request $request,
        WeeklyBasketGenerator $generator,
        PartnerDeliveryShiftRepository $shiftRepository,
        DeliveryShiftApplier $applier,
        ExtraBasketEditor $extraBasketEditor,
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

        $actor = 'gestor:' . $this->getUser()?->getId();

        // CESTA EXTRA: si el día existe SOLO por una cesta extra (el patrón del socio no le da
        // entrega esa semana), "no recoge" = QUITAR la extra (cancelarla), no un skip de patrón
        // (que no tendría cesta que aparcar). Se comprueba antes de las guardas porque un
        // no-suscriptor (sin cesta activa) no las pasaría, y su extra hay que poder quitarla.
        // Si el patrón SÍ le da cesta esa semana (la extra va encima), sigue el flujo normal.
        if (!$request->request->getBoolean('recover')
            && $extraBasketEditor->hasExtra($partner, $basket)
            && !$this->patternDelivers($partner, $basket, $generator, $em)
        ) {
            $extraBasketEditor->removeExtra($partner, $basket, $actor);
            $this->addFlash('success', 'Cesta extra quitada: esa semana no recoge.');

            return $backToCalendar();
        }

        $generated = $em->getRepository(WeeklyBasket::class)->findOneBy(['basket' => $basket]) !== null;
        $outgoing = $shiftRepository->findOutgoing($partner, $basket);
        $incoming = $shiftRepository->findIncoming($partner, $basket);

        // VOLVER A RECOGER: acción EXPLÍCITA (botón "Volver a recoger" del panel sobre una
        // entrega aparcada), marcada con el flag `recover`. NO se infiere de findOutgoing:
        // un día puede tener a la vez un "no recoge" saliente y una cesta entrante (tras un
        // swap), y el arrastre de "no recoge" debe aparcar la entrante, no recuperar la otra.
        if ($request->request->getBoolean('recover')) {
            if ($outgoing !== null && $outgoing->isSkip()) {
                $applier->cancelSkipIntent($outgoing, $actor);
                if ($generated) {
                    $share = $em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $basket->getDate());
                    if ($share !== null) {
                        $generator->materializeShareDelivery($basket, $share);
                        $em->flush();
                    }
                }
                $this->addFlash('success', 'Restaurada: el socio vuelve a recoger esa semana.');
            } else {
                $this->addFlash('warning', 'Esa semana no tiene ninguna entrega aparcada que recuperar.');
            }

            return $backToCalendar();
        }

        // NO RECOGE: guardas comunes (no pasada, no compartida R1, cesta activa).
        if ($this->ungeneratedEditGuard($partner, $basket, $em) === null) {
            return $backToCalendar();
        }

        // La cesta MOSTRADA en este día puede haber VENIDO movida de otro (cambio entrante).
        // Eso es lo que el gestor ve y quiere no-recoger, y tiene PRIORIDAD sobre cualquier
        // shift SALIENTE de este mismo día: tras un swap (p. ej. 1↔15) ambos días son origen
        // Y destino a la vez, y lo que se recoge ese día es la cesta entrante. "No recoge"
        // re-apunta ese movimiento entrante a un skip (O→X pasa a O→null), aparcándola.
        if ($incoming !== null) {
            $applier->skipMovedDelivery($incoming, $actor);
            $this->addFlash('success', 'Marcada como NO recogida esa semana.');

            return $backToCalendar();
        }

        // Ya hay un "no recoge" saliente de este día (sin entrante): la cesta ya está
        // aparcada. (Raro de alcanzar desde la UI: un día así sale en la papelera, no en la
        // rejilla; defensivo.)
        if ($outgoing !== null && $outgoing->isSkip()) {
            $this->addFlash('warning', 'Esa semana ya está marcada como NO recoge.');

            return $backToCalendar();
        }

        // Sin entrante pero con un move saliente: la cesta de este día YA se fue a otra
        // fecha (este día está vacío). No hay nada que no-recoger aquí; se gestiona desde
        // el día destino.
        if ($outgoing !== null) {
            $this->addFlash('warning', 'La cesta de esta semana ya está movida a otro día: gestiónala desde ahí.');

            return $backToCalendar();
        }

        // Día normal con su cesta de patrón.
        $applier->applySkipIntent($partner, $basket, null, $actor);
        $this->addFlash('success', 'Marcada como NO recogida esa semana.');

        return $backToCalendar();
    }

    /**
     * ¿El PATRÓN del socio le da una entrega esa semana (independiente de extras)? Sirve para
     * decidir si un "no recoge" sobre un día que tiene cesta extra debe quitar la extra (día
     * que solo existe por ella) o saltar la entrega de patrón (la extra iba encima).
     *
     * @param Partner               $partner
     * @param Basket                $basket
     * @param WeeklyBasketGenerator $generator
     * @param EntityManagerInterface $em
     * @return bool
     */
    private function patternDelivers(Partner $partner, Basket $basket, WeeklyBasketGenerator $generator, EntityManagerInterface $em): bool
    {
        $share = $em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $basket->getDate());

        return $share !== null && $generator->projectShareDelivery($basket, $share) !== null;
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
        DeliveryShiftApplier $applier,
        DeliveryCalendarProjector $projector,
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

        $actor = 'gestor:' . $this->getUser()?->getId();
        $label = $componentId === BasketComponent::ID_EGGS ? 'Huevos' : 'Verdura';

        // Semana GENERADA: se alterna la línea de componente del WeeklyBasket (directo).
        if ($em->getRepository(WeeklyBasket::class)->findOneBy(['basket' => $basket]) !== null) {
            $weeklyBasket = $this->resolveCalendarDelivery($partner, $basket, $generator, $shiftRepository, $applier, $em);
            if ($weeklyBasket === null) {
                return $backToCalendar();
            }
            $result = $componentEditor->toggle($weeklyBasket, $componentId);
            $em->flush();
            match ($result) {
                'added' => $this->addFlash('success', $label . ' añadido a esa entrega.'),
                'removed' => $this->addFlash('success', $label . ' quitado de esa entrega.'),
                default => $this->addFlash('warning', 'Esa entrega no contempla ' . strtolower($label) . ' según la cesta del socio.'),
            };

            return $backToCalendar();
        }

        // Semana SIN GENERAR: quitar/añadir el componente se guarda como intent durable
        // (sin destino), no se materializa. Alterna: si ya hay un "quitar" de ese
        // componente, se cancela (vuelve); si no, se crea — pero solo si el patrón da ese
        // componente esa semana (no se "quita" algo que no tocaba).
        if ($this->ungeneratedEditGuard($partner, $basket, $em) === null) {
            return $backToCalendar();
        }

        $existingDrop = null;
        foreach ($shiftRepository->findComponentIntentsFromBasket($partner, $basket) as $intent) {
            if ($intent->isSkip() && $intent->getComponent()?->getId() === $componentId) {
                $existingDrop = $intent;
                break;
            }
        }
        if ($existingDrop !== null) {
            $applier->cancelSkipIntent($existingDrop, $actor);
            $this->addFlash('success', $label . ' añadido a esa entrega.');

            return $backToCalendar();
        }

        // ¿El patrón da ese componente esta semana? (slot proyectado, ya con huevo-extra
        // e intents reflejados). Si no lo da, no hay nada que quitar.
        $delivers = false;
        foreach ($projector->projectMonth($partner, (int) $basket->getDate()->format('Y'), (int) $basket->getDate()->format('n')) as $s) {
            if ($s['basket']->getId() !== $basket->getId()) {
                continue;
            }
            foreach ($s['items'] as $line) {
                if ($line['component']->getId() === $componentId) {
                    $delivers = true;
                    break;
                }
            }
            break;
        }
        if (!$delivers) {
            $this->addFlash('warning', 'Esa entrega no contempla ' . strtolower($label) . ' según la cesta del socio.');

            return $backToCalendar();
        }

        $applier->applySkipIntent($partner, $basket, $em->getRepository(BasketComponent::class)->find($componentId), $actor);
        $this->addFlash('success', $label . ' quitado de esa entrega.');

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
     * @param DeliveryShiftApplier           $applier
     * @param EntityManagerInterface         $em
     * @return WeeklyBasket|null
     */
    private function resolveCalendarDelivery(
        Partner $partner,
        Basket $basket,
        WeeklyBasketGenerator $generator,
        PartnerDeliveryShiftRepository $shiftRepository,
        DeliveryShiftApplier $applier,
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

        // Cambio puntual de ENTREGA ENTERA (mover) activo esa semana: dejaría el shift
        // desincronizado. (El gate de "semana sin generar" se retiró: ahora editar
        // contenido en semanas sin generar pasa por intents — ver los endpoints de
        // saltar / componente, que ramifican generado/sin-generar antes de llamar aquí.)
        if ($shiftRepository->findOutgoing($partner, $basket) !== null) {
            $this->addFlash('warning', 'Hay un cambio puntual de día activo esa semana: gestiónalo desde los cambios puntuales.');

            return null;
        }

        $share = $em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $basket->getDate());
        if ($share === null) {
            $this->addFlash('warning', 'El socio no tiene cesta activa esa semana.');

            return null;
        }

        // Destino de un cambio puntual (la cesta viene movida aquí): se materializa por
        // el applier para que la composición —y sobre todo los huevos— viaje del origen,
        // no se recomponga desde el patrón de esta semana (que en una semana sin huevo
        // los perdería). materializeShareDelivery solo vale para la entrega natural.
        $incoming = $shiftRepository->findIncoming($partner, $basket);
        if ($incoming !== null) {
            return $applier->materializeShiftDestination($incoming);
        }

        $weeklyBasket = $generator->materializeShareDelivery($basket, $share);
        if ($weeklyBasket === null) {
            $this->addFlash('warning', 'El nodo del socio no reparte esa semana.');

            return null;
        }

        return $weeklyBasket;
    }

    /**
     * Guardas comunes para EDITAR contenido en una semana SIN GENERAR (vía intent, no
     * materializa): no pasada, no compartida (R1) y socio con cesta activa. Devuelve el
     * share, o null + flash si alguna guarda lo impide. No mira el listado (ese branch
     * lo decide el endpoint) ni los shifts salientes (el skip los gestiona él mismo).
     *
     * @return PartnerBasketShare|null
     */
    private function ungeneratedEditGuard(Partner $partner, Basket $basket, EntityManagerInterface $em): ?PartnerBasketShare
    {
        if ($basket->getDate() < new \DateTimeImmutable('today')) {
            $this->addFlash('warning', 'Esa entrega ya pasó: no se puede editar.');

            return null;
        }
        if ($partner->getSharePartner() !== null) {
            $this->addFlash('warning', 'Esta cesta es compartida: su día lo marca la alternancia con el otro hogar, no se edita aquí.');

            return null;
        }
        $share = $em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $basket->getDate());
        if ($share === null) {
            $this->addFlash('warning', 'El socio no tiene cesta activa esa semana.');

            return null;
        }

        return $share;
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
        // Solo se eliminan fichas PENDIENTES de confirmar (needs_review): son
        // las importadas del histórico de producción, sin nada colgando. Un
        // socix consolidado conserva su histórico (cestas, reparto, eventos);
        // a ese se le da de BAJA, no se borra. Guard server-side, no basta con
        // ocultar el botón.
        if (!$partner->isNeedsReview()) {
            $this->addFlash('warning', 'Solo se pueden eliminar socixs pendientes de confirmar. A un socix consolidado se le da de baja para conservar su histórico, no se elimina.');

            return $this->redirectToRoute('partner_show', ['id' => $partner->getId()]);
        }

        if ($this->isCsrfTokenValid('delete' . $partner->getId(), $request->request->get('_token'))) {
            $entityManager->remove($partner);
            $entityManager->flush();
            $this->addFlash('notice', 'Ficha pendiente eliminada.');
        }

        return $this->redirectToRoute('partner_index');

    }

    #[Route("/{id}/{type}/family", name: "family", methods: ["GET"])]
    public function family(Request $request, Partner $partner, $type, EntityManagerInterface $entityManager): Response
    {
        if ($type == 'add_family') {
            $partners = $entityManager->getRepository(\App\Entity\Partner::class)->findFamiliar($partner);
        } elseif ($type == 'set_payer') {
            // Pagador (donante) de la cesta: cualquier socix activx distintx del
            // receptor. La cesta la sigue recibiendo $partner; sólo cambia quién
            // figura como pagador (payer_partner) de su PartnerBasketShare.
            $partners = array_values(array_filter(
                $entityManager->getRepository(Partner::class)
                    ->findBy(['status' => Partner::STATUS_ACTIVO], ['name' => 'ASC']),
                static fn (Partner $p) => $p->getId() !== $partner->getId()
            ));
        } else {
            // add_share_partner: la pareja de una compartida es OTRA compartida de la
            // MISMA modalidad (4/6/7) sin pareja todavía — comparten una sola cesta
            // alternándose. Antes se ofrecía sólo basket_share=4 (semanal compartida),
            // así que las quincenales/mensuales compartidas no encontraban con quién
            // emparejar.
            $shareTypeId = $partner->getActiveBasket()?->getBasketShare()?->getId();
            $partners = [];
            if ($shareTypeId !== null) {
                $baskets = $entityManager->getRepository(\App\Entity\PartnerBasketShare::class)
                    ->findBy(['is_active' => 1, 'basket_share' => $shareTypeId]);
                foreach ($baskets as $basket) {
                    $candidate = $basket->getPartner();
                    if ($candidate->getId() !== $partner->getId() && !$candidate->getSharePartner()) {
                        $partners[] = $candidate;
                    }
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

        // Un familiar es secundario y NO tiene cesta propia (vive en el principal).
        // findFamiliar ya no ofrece a quien tenga cesta activa, así que esto es una
        // red defensiva: si llega igualmente (URL a mano, dato heredado), rechazamos
        // en vez de destruir su PartnerBasketShare en silencio.
        $hasActiveBasket = $partner2->getPartnerBasketShares()->exists(
            fn ($i, PartnerBasketShare $share) => $share->getIsActive()
        );
        if ($hasActiveBasket) {
            $session->getFlashBag()->add(
                'warning',
                sprintf(
                    'No se puede asociar a %s %s como familiar: tiene una cesta propia activa. '
                    . 'Dale de baja la cesta antes de vincularla a una familia.',
                    $partner2->getName(),
                    $partner2->getSurname()
                )
            );
            return $this->redirectToRoute('partner_show', array('id' => $partner1_id));
        }

        $partner1->addRelative($partner2);

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
        CohortChoiceBuilder $cohortChoiceBuilder,
        WeeklyBasketGenerator $generator,
    ): Response {
        $partnerBasketShare = new PartnerBasketShare();
        $partnerBasketShare->setPartner($partner);

        // El alta de socio ya no fija grupo de recogida: si el socio no lo
        // tiene, se elige aquí mismo. El grupo manda sobre el resto del form
        // (su nodo decide qué modalidades caben y el turno A/B), así que la
        // página se recarga con ?group=N al cambiarlo y, en el POST, el form
        // se construye con el grupo enviado para que las choices validen
        // contra lo realmente ofrecido.
        $askPickupGroup = $partner->getWeeklyBasketGroup() === null;
        $pickupGroup = $partner->getWeeklyBasketGroup();
        if ($askPickupGroup) {
            $submitted = $request->request->all('partner_basket_share');
            $groupId = (int) ($submitted['pickupGroup'] ?? $request->query->get('group'));
            if ($groupId > 0) {
                // Un id inexistente deja $pickupGroup a NULL: el form se
                // construye sin grupo y el NotBlank del campo lo rechaza. La
                // barrera real contra ids arbitrarios es el propio EntityType,
                // que en el POST sólo acepta grupos del catálogo.
                $pickupGroup = $entityManager->getRepository(WeeklyBasketGroup::class)->find($groupId);
            }
        }

        $cohort = $cohortChoiceBuilder->forNode($pickupGroup?->getNode());
        $form = $this->createForm(PartnerBasketShareType::class, $partnerBasketShare, [
            'cohort_choices' => $cohort['cohortChoices'],
            'exclude_weekly_shares' => $cohort['excludeWeeklyShares'],
            'ask_pickup_group' => $askPickupGroup,
            'pickup_group' => $pickupGroup,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($askPickupGroup) {
                $partner->setWeeklyBasketGroup($form->get('pickupGroup')->getData());
            }
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
            $entityManager->flush();

            // En las semanas YA generadas desde su alta, materializa al socio SÓLO
            // donde su patrón (modalidad + cohorte + nodo) lo coloca, componiendo sus
            // items; las semanas aún sin generar se dibujan al vuelo (proyección). Antes
            // se creaba aquí un WeeklyBasket a mano que ignoraba la cohorte y nacía vacío
            // —el "no recoge" fantasma de las altas a mitad de mes—.
            $generator->reconcilePartnerFrom($partner, $partnerBasketShare->getStartDate());

            // Una cesta compartida alterna entre DOS hogares: si nace sin pareja, lleva
            // directo a elegir con quién comparte en vez de a la ficha, para que no se
            // quede huérfana (sin pareja no materializa bien la verdura).
            if (in_array($partnerBasketShare->getBasketShare()->getId(), BasketShare::IDS_SHARED, true)
                && $partner->getSharePartner() === null) {
                $this->addFlash('info', 'Es una cesta compartida: indica con quién la comparte.');
                return $this->redirectToRoute('family', ['id' => $partner->getId(), 'type' => 'add_share_partner']);
            }

            return $this->redirectToRoute('partner_show', array('id' => $partner->getId()));
        }

        return $this->render('partner_basket_share/new.html.twig', [
            'partner_basket_share' => $partnerBasketShare,
            'entity' => $partnerBasketShare,
            'form' => $form->createView(),
            'partner' => $partner,
            'node_is_biweekly' => $cohort['nodeIsBiweekly'],
            'node_name' => $cohort['nodeName'],
            'node_dates_label' => $cohort['nodeDatesLabel'],
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

        // Defensa: sólo se emparejan dos compartidas de la MISMA modalidad (4/6/7)
        // que aún no tengan pareja. Comparten una única cesta alternándose; emparejar
        // modalidades distintas o sobre una ya emparejada deja datos incoherentes.
        $bs1 = $partner1->getActiveBasket()?->getBasketShare()?->getId();
        $bs2 = $partner2->getActiveBasket()?->getBasketShare()?->getId();
        if ($bs1 === null || !in_array($bs1, BasketShare::IDS_SHARED, true) || $bs1 !== $bs2
            || $partner1->getSharePartner() !== null || $partner2->getSharePartner() !== null) {
            $session->getFlashBag()->add(
                'warning',
                'Sólo se pueden emparejar dos cestas compartidas de la misma modalidad que aún no tengan pareja.'
            );
            return $this->redirectToRoute('partner_show', array('id' => $partner1_id));
        }

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

    /**
     * Asigna un pagador externo (donante) a la cesta activa del receptor. El
     * receptor ($partner1) sigue recibiendo físicamente la cesta; el donante
     * ($partner2) queda como payer_partner de su PartnerBasketShare y será quien
     * figure en cobros cuando exista esa fase. Modela la donación nominada de
     * cuota de cesta entre socixs.
     *
     * @param int                    $partner1_id Receptor de la cesta (de la ruta).
     * @param int                    $partner2_id Donante/pagador elegido en el picker.
     * @param EntityManagerInterface $entityManager
     * @return Response Redirige a la ficha del receptor.
     */
    #[Route("/{partner1_id}/{partner2_id}/set_payer", name: "set_payer", methods: ["GET"])]
    public function setPayer($partner1_id, $partner2_id, EntityManagerInterface $entityManager): Response
    {
        if ($partner1_id == $partner2_id) {
            $this->addFlash('warning', 'El pagador debe ser otrx socix distintx del receptor.');
            return $this->redirectToRoute('partner_show', ['id' => $partner1_id]);
        }

        $receptor = $entityManager->getRepository(Partner::class)->find($partner1_id);
        $payer = $entityManager->getRepository(Partner::class)->find($partner2_id);
        if (!$receptor instanceof Partner || !$payer instanceof Partner) {
            $this->addFlash('warning', 'Socix no encontradx.');
            return $this->redirectToRoute('partner_show', ['id' => $partner1_id]);
        }

        $basket = $receptor->getActiveBasket();
        if (!$basket instanceof PartnerBasketShare) {
            $this->addFlash('warning', 'El receptor no tiene una cesta activa a la que asignar pagador.');
            return $this->redirectToRoute('partner_show', ['id' => $partner1_id]);
        }

        $basket->setPayerPartner($payer);
        $entityManager->flush();

        $this->addFlash('success', sprintf(
            'La cesta de %s %s la paga ahora %s %s.',
            $receptor->getName(), $receptor->getSurname(), $payer->getName(), $payer->getSurname()
        ));
        return $this->redirectToRoute('partner_show', ['id' => $partner1_id]);
    }

    /**
     * Revierte la cesta del receptor a "la paga ella misma": limpia el
     * payer_partner de su PartnerBasketShare activa.
     *
     * @param Partner                $partner Receptor (de la ruta).
     * @param EntityManagerInterface $entityManager
     * @return Response Redirige a la ficha del receptor.
     */
    #[Route("/{id}/clear_payer", name: "clear_payer", methods: ["GET"], requirements: ["id" => "\\d+"])]
    public function clearPayer(Partner $partner, EntityManagerInterface $entityManager): Response
    {
        $basket = $partner->getActiveBasket();
        if (!$basket instanceof PartnerBasketShare) {
            $this->addFlash('warning', 'El socix no tiene una cesta activa.');
            return $this->redirectToRoute('partner_show', ['id' => $partner->getId()]);
        }

        $basket->setPayerPartner(null);
        $entityManager->flush();

        $this->addFlash('success', 'La cesta vuelve a pagarla la propia socix.');
        return $this->redirectToRoute('partner_show', ['id' => $partner->getId()]);
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

