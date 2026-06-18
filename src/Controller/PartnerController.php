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
use App\Repository\PartnerRepository;
use App\Service\Delivery\CohortChoiceBuilder;
use App\Repository\WeeklyBasketGroupRepository;
use App\Security\MagicLinkMailer;
use App\Security\PartnerAccessPolicy;
use App\Security\PartnerUserProvisioner;
use App\Service\Delivery\ExtraBasketEditor;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Delivery\PickupRelocationOptions;
use App\Service\Delivery\PickupRelocator;
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

