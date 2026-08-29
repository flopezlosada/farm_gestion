<?php

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\User;
use App\Entity\VolunteerCall;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerEvent;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerSignup;
use App\Form\VolunteerCategoryType;
use App\Form\VolunteerOfferType;
use App\Repository\PartnerRepository;
use App\Repository\VolunteerCallRepository;
use App\Repository\VolunteerCategoryRepository;
use App\Repository\VolunteerEventRepository;
use App\Repository\VolunteerOfferRepository;
use App\Repository\VolunteerSignupRepository;
use App\Security\VolunteerOfferVoter;
use App\Service\Volunteering\OfferRepeatDates;
use App\Service\Volunteering\VolunteerAudienceResolver;
use App\Service\Volunteering\VolunteerCallNotifier;
use App\Service\Volunteering\VolunteerEventRecorder;
use App\Service\Volunteering\VolunteerOfferChangeNotifier;
use App\Service\Volunteering\VolunteerOfferSnapshot;
use App\Service\Volunteering\VolunteerScope;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestión del voluntariado: publicar tareas, ver quién se apunta, cerrarlas y
 * pedir gente.
 *
 * ROL PROPIO Y NO EL DE SOCIXS, y es una decisión de privacidad, no de orden.
 * Quien coordina el reparto de los viernes necesita saber quién viene ese
 * viernes; darle `ROLE_GESTION_SOCIXS` para eso le abriría las fichas, DNIs y
 * domicilios de los 246 socixs. Es el mismo criterio con el que las encuestas se
 * separaron de socixs en `security.yaml`: mínimo privilegio.
 *
 * QUIÉN PUEDE TOCAR QUÉ lo decide {@see VolunteerOfferVoter} por tarea, no este
 * atributo: quien coordina un área puede con las suyas y con ninguna más. El
 * `IsGranted` de la clase sólo abre la puerta.
 *
 * Y LA LECTURA TAMBIÉN VA POR ÁREAS ({@see VolunteerScope}): quien coordina
 * huerta no ve las tareas del reparto ni su gente. "Sin mezcla" es el requisito,
 * y el filtro vive en las consultas y no en los controllers para que ninguna
 * vista futura pueda saltárselo por descuido — un filtro de permisos que falta
 * no da error, simplemente enseña lo que no debía.
 *
 * Sólo administración (`ROLE_GESTION_VOLUNTARIADO_EDIT`, y ROLE_ADMIN por
 * jerarquía) ve el conjunto.
 */
#[Route('/gestion/voluntariado')]
#[IsGranted('ROLE_GESTION_VOLUNTARIADO')]
#[IsGranted('FEATURE_VOLUNTEERING')]
class VolunteeringController extends AbstractController
{
    /** Cuántos días atrás se miran las tareas ya hechas en el listado. */
    private const RECENT_DAYS = 60;

    /**
     * Las tareas: lo que falta por cerrar, lo que viene y lo que ya se hizo.
     */
    #[Route('', name: 'volunteering_index', methods: ['GET'])]
    public function index(
        Request $request,
        VolunteerOfferRepository $offers,
        VolunteerCategoryRepository $categories,
        PaginatorInterface $paginator,
        VolunteerScope $scopeOf,
    ): Response {
        $now = new \DateTime();
        $scope = $request->query->getAlpha('ver') ?: 'upcoming';
        $categoryId = $request->query->getInt('tipo') ?: null;
        $category = null !== $categoryId ? $categories->find($categoryId) : null;
        $query = trim((string) $request->query->get('q'));
        $mine = $scopeOf->categories();

        $pagination = $paginator->paginate(
            $offers->listQb($scope, $category, $query, $now, $mine)->getQuery(),
            $request->query->getInt('page', 1),
            25
        );

        return $this->render('Volunteering/index.html.twig', [
            'pagination' => $pagination,
            'counts' => $offers->counts($now, $mine),
            // El filtro de área sólo ofrece las suyas: enseñar áreas que no
            // puede ver es prometer un filtro que devuelve cero.
            'categories' => $mine ?? $categories->findActive(),
            'scope' => $scope,
            'current' => $category,
            'q' => $query,
            'now' => $now,
            'coordinates_something' => $scopeOf->coordinatesSomething(),
            'sees_everything' => $scopeOf->seesEverything(),
        ]);
    }

    /**
     * La bolsa: quién hay para cada tipo de trabajo.
     *
     * Es lo primero que echa en falta quien coordina un área, y hasta ahora no
     * estaba: la aplicación sabía decir quién se apuntó a UNA tarea, pero no a
     * quién se puede llamar para huerta. Sin eso, coordinar obliga a salirse de
     * la herramienta y tirar del grupo de WhatsApp.
     *
     * Enseña también a quien no ha declarado nada y a quien ha pedido que no le
     * avisen, cada uno en su sitio: el cuadro completo es lo que permite ver si
     * un área está sostenida por dos personas.
     */
    #[Route('/gente', name: 'volunteering_pool', methods: ['GET'])]
    public function pool(
        Request $request,
        PartnerRepository $partners,
        VolunteerCategoryRepository $categories,
        VolunteerSignupRepository $signups,
        PaginatorInterface $paginator,
        VolunteerScope $scopeOf,
    ): Response {
        $filter = $request->query->getInt('tipo') ?: null;
        $category = null !== $filter ? $categories->find($filter) : null;
        $scope = $request->query->getAlpha('ver') ?: 'declared';
        $query = trim((string) $request->query->get('q'));
        $mine = $scopeOf->categories();

        $year = (int) date('Y');
        $from = new \DateTime(sprintf('%d-01-01 00:00:00', $year));
        $to = new \DateTime(sprintf('%d-12-31 23:59:59', $year));

        $pagination = $paginator->paginate(
            $partners->volunteeringPoolQb($scope, $category, $query, $mine)->getQuery(),
            $request->query->getInt('page', 1),
            25,
            [
                // Por nombre y NO por lo que ha hecho cada quien: una tabla
                // ordenada por aportación es un ranking, y un ranking expulsa a
                // quien no puede competir. Por eso tampoco es ordenable.
                'defaultSortFieldName' => 'p.name+p.surname',
                'defaultSortDirection' => 'asc',
            ]
        );

        return $this->render('Volunteering/pool.html.twig', [
            'pagination' => $pagination,
            'categories' => $mine ?? $categories->findActive(),
            'current' => $category,
            'scope' => $scope,
            'counts' => $partners->volunteeringPoolCounts($mine),
            'coordinates_something' => $scopeOf->coordinatesSomething(),
            // Veces que ha participado cada quien, en un solo mapa: preguntarlo
            // por socix sería el N+1 más caro del módulo.
            'participation' => $signups->participationByPartner($from, $to),
            'year' => $year,
            'q' => $query,
        ]);
    }

    /**
     * El rastro de actividad: qué ha pasado en el voluntariado y quién lo hizo.
     *
     * Filtrado por área, que es el requisito: quien coordina el reparto ve lo
     * que pasa en el reparto y nada más. Sólo administración ve el conjunto.
     */
    #[Route('/actividad', name: 'volunteering_activity', methods: ['GET'])]
    public function activity(
        Request $request,
        VolunteerEventRepository $events,
        PaginatorInterface $paginator,
        VolunteerScope $scopeOf,
        PartnerRepository $partners,
    ): Response {
        $type = $request->query->getAlpha('tipo') ?: null;
        $type = null !== $type && isset(VolunteerEvent::LABELS[$type]) ? $type : null;

        // El historial de una persona: no hay ficha de socix dentro del módulo,
        // así que se entra aquí desde su fila en "Quién hay".
        $who = $request->query->getInt('socix') ?: null;
        $who = null !== $who ? $partners->find($who) : null;

        $pagination = $paginator->paginate(
            $events->feedQb($scopeOf->categories(), $type, $who)->getQuery(),
            $request->query->getInt('page', 1),
            30
        );

        return $this->render('Volunteering/activity.html.twig', [
            'pagination' => $pagination,
            'who' => $who,
            // Los nombres sólo de la página visible: resolver los de toda la
            // tabla sería traer cuentas que no se van a pintar.
            'actor_names' => $events->actorNames(iterator_to_array($pagination)),
            'labels' => VolunteerEvent::LABELS,
            'type' => $type,
            'coordinates_something' => $scopeOf->coordinatesSomething(),
            'sees_everything' => $scopeOf->seesEverything(),
        ]);
    }

    /**
     * Publicar una tarea nueva.
     *
     * Sin gate de rol en la ruta: quien coordina un área también publica tareas
     * suyas, y no tiene el rol global de escritura. El permiso se comprueba
     * DESPUÉS de rellenar el formulario, cuando ya se sabe de qué área es la
     * tarea — antes no hay nada sobre lo que decidir.
     */
    #[Route('/nueva', name: 'volunteering_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
        OfferRepeatDates $repeatDates,
    ): Response {
        $offer = (new VolunteerOffer())->setCreatedBy($this->getUser());
        $form = $this->createForm(VolunteerOfferType::class, $offer, ['with_repeat' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Publicar una tarea de un área que no coordinas es lo mismo que
            // editar la de otra persona. Se comprueba aquí porque hasta ahora la
            // oferta no tenía categorías que mirar.
            $this->denyAccessUnlessGranted(VolunteerOfferVoter::EDIT, $offer);

            $em->persist($offer);
            $events->forOffer($offer, VolunteerEvent::TYPE_OFFER_CREATED, ['status' => $offer->getStatus()]);

            // Repetir en el mismo paso que crear: "voy a dar de alta el reparto
            // de los viernes" es una sola decisión, y obligar a guardar primero y
            // repetir después desde la ficha la parte en dos sin motivo.
            $copies = $this->repeatOnCreate($offer, $form, $em, $events, $repeatDates);

            $em->flush();
            $this->addFlash('success', 0 === $copies
                ? 'Tarea creada.'
                : sprintf('Tarea creada, con %d copias más en borrador. Revísalas y publícalas.', $copies));

            return 0 === $copies
                ? $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()])
                : $this->redirectToRoute('volunteering_index');
        }

        return $this->render('Volunteering/form.html.twig', [
            'offer' => $offer,
            'form' => $form->createView(),
            'is_new' => true,
        ]);
    }

    /**
     * Crea las copias que pida el formulario de alta, si pide alguna.
     *
     * No valida nada: la coherencia de los dos campos —que haya fecha final, y
     * que la cadencia del reparto sólo se use con punto de recogida— la
     * comprueba {@see VolunteerOfferType}, así que aquí ya llegan bien o no se
     * llega.
     *
     * @param VolunteerOffer         $offer       la tarea recién creada
     * @param FormInterface          $form        el formulario de alta, ya validado
     * @param EntityManagerInterface $em          para persistir las copias
     * @param VolunteerEventRecorder $events      para dejar rastro de la repetición
     * @param OfferRepeatDates       $repeatDates quién sabe en qué fechas copiar
     *
     * @return int cuántas copias se han creado
     */
    private function repeatOnCreate(
        VolunteerOffer $offer,
        FormInterface $form,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
        OfferRepeatDates $repeatDates,
    ): int {
        $cadence = $form->get('repeatCadence')->getData();
        $until = $form->get('repeatUntil')->getData();

        if (null === $cadence || !$until instanceof \DateTimeInterface) {
            return 0;
        }

        $dates = $repeatDates->compute($offer, $cadence, $until);

        foreach ($dates as $date) {
            $em->persist($offer->copyForDate($date));
        }

        if ([] !== $dates) {
            $events->forOffer($offer, VolunteerEvent::TYPE_OFFER_REPEATED, [
                'times' => \count($dates),
                'cadence' => $cadence,
                'until' => $until->format('Y-m-d'),
            ]);
        }

        return \count($dates);
    }

    /**
     * Una tarea: sus datos, quién se ha apuntado y los avisos que se han
     * mandado por ella.
     */
    #[Route('/{id}', name: 'volunteering_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        VolunteerOffer $offer,
        VolunteerCallRepository $calls,
        VolunteerAudienceResolver $audience,
        PartnerRepository $partners,
        VolunteerEventRepository $events,
        OfferRepeatDates $repeatDates,
    ): Response {
        $sent = $calls->sentScopes($offer);
        $history = $events->historyFor($offer);

        return $this->render('Volunteering/show.html.twig', [
            'offer' => $offer,
            'sent_scopes' => $sent,
            // Qué cadencias admite ESTA tarea: la del calendario de reparto sólo
            // existe si ocurre en un punto de recogida.
            'repeat_cadences' => $repeatDates->cadencesFor($offer),
            // Para anotar a mano a quien organizó la tarea o vino sin apuntarse.
            'all_partners' => $partners->findBy(
                ['status' => Partner::STATUS_ACTIVO],
                ['name' => 'ASC', 'surname' => 'ASC']
            ),
            // Cuánta gente recibiría el aviso general, para que quien pulsa el
            // botón vea el número ANTES de molestar a media asociación.
            'everyone_count' => $audience->count($offer, VolunteerCall::SCOPE_EVERYONE),
            'everyone_sent' => \in_array(VolunteerCall::SCOPE_EVERYONE, $sent, true),
            // El historial de ESTA tarea. Sin él, el rastro sólo se podía
            // consultar en el listado global, que es justo donde no estás
            // cuando quieres saber qué le ha pasado a una tarea concreta.
            'history' => $history,
            'actor_names' => $events->actorNames($history),
        ]);
    }

    /**
     * Editar una tarea.
     *
     * Si el cambio afecta a quien ya se apuntó —se anula, se mueve de fecha o
     * cambia de sitio— se le avisa. Sin eso, anular una tarea deja a alguien
     * plantándose allí para nada, y esa persona, que es justo la que sí
     * colabora, no vuelve.
     */
    #[Route('/{id}/editar', name: 'volunteering_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::EDIT, subject: 'offer')]
    public function edit(
        Request $request,
        VolunteerOffer $offer,
        EntityManagerInterface $em,
        VolunteerOfferChangeNotifier $changes,
        VolunteerEventRecorder $events,
    ): Response {
        // La foto se toma ANTES de handleRequest: después, la entidad ya lleva
        // los valores nuevos y el original se ha perdido.
        $before = VolunteerOfferSnapshot::of($offer);

        $form = $this->createForm(VolunteerOfferType::class, $offer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Anular se registra aparte de editar: no es el mismo hecho y en el
            // rastro se busca de forma distinta.
            $events->forOffer(
                $offer,
                $before->wasCancelledIn($offer)
                    ? VolunteerEvent::TYPE_OFFER_CANCELLED
                    : VolunteerEvent::TYPE_OFFER_UPDATED,
                [
                    'moved' => $before->movedIn($offer),
                    'relocated' => $before->relocatedIn($offer),
                ]
            );

            $em->flush();

            $notified = $changes->notifyChanges($offer, $before);

            $this->addFlash(
                'success',
                $notified > 0
                    ? sprintf('Tarea actualizada. Se ha avisado a %d persona(s) que se habían apuntado.', $notified)
                    : 'Tarea actualizada.'
            );

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        return $this->render('Volunteering/form.html.twig', [
            'offer' => $offer,
            'form' => $form->createView(),
            'is_new' => false,
        ]);
    }

    /**
     * Repetir esta tarea hasta una fecha.
     *
     * Es lo que hace el módulo usable para lo que más se repite: el reparto es
     * SEMANAL, y crear "descargar cestas en La Cabrera" cincuenta y dos veces a
     * mano no lo va a hacer nadie.
     *
     * Cadencia y fecha final, no una regla de recurrencia. Karrot modela series
     * con RRULE de iCal, potente y caro; OpenOlitor simplemente duplica a una
     * lista de fechas. Esto es lo segundo: las copias nacen sueltas, así que
     * cambiar o anular una no toca a las demás — que es justo lo que hace falta
     * cuando cae un festivo en medio. Por eso tampoco se guarda aquí nada de la
     * serie: {@see OfferRepeatDates} sólo devuelve fechas.
     *
     * Las fechas las calcula ese servicio, no este método. Cuando la tarea
     * ocurre en un punto de recogida puede pedir las del calendario de reparto
     * de verdad, y eso ya no es "sumar días" ni cabe en un controller.
     */
    #[Route('/{id}/repetir', name: 'volunteering_repeat', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::EDIT, subject: 'offer')]
    public function repeat(
        Request $request,
        VolunteerOffer $offer,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
        OfferRepeatDates $repeatDates,
    ): Response {
        if (!$this->isCsrfTokenValid('volunteering_repeat', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        if (null === $offer->getStartsAt()) {
            $this->addFlash('error', 'Esta tarea no tiene fecha, así que no se puede repetir.');

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        $cadence = (string) $request->request->get('cadence');
        $until = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $request->request->get('until'));

        if (false === $until || !\in_array($cadence, $repeatDates->cadencesFor($offer), true)) {
            $this->addFlash('error', 'Elige cada cuánto se repite y hasta cuándo.');

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        $dates = $repeatDates->compute($offer, $cadence, $until);

        if ([] === $dates) {
            // Sin fechas no es un error: puede que la fecha final sea anterior a
            // la tarea, o que el punto de recogida no vuelva a repartir en ese
            // plazo. Decirlo es más útil que crear cero copias en silencio.
            $this->addFlash('warning', 'No hay ninguna fecha que repetir en ese plazo. Revisa hasta cuándo la quieres.');

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        foreach ($dates as $date) {
            $em->persist($offer->copyForDate($date));
        }

        // Un evento por la repetición, no uno por copia: cincuenta y dos filas
        // diciendo lo mismo enterrarían el resto del rastro.
        $events->forOffer($offer, VolunteerEvent::TYPE_OFFER_REPEATED, [
            'times' => \count($dates),
            'cadence' => $cadence,
            'until' => $until->format('Y-m-d'),
        ]);

        $em->flush();

        $this->addFlash(
            'success',
            OfferRepeatDates::CADENCE_DELIVERY === $cadence
                ? sprintf('Creadas %d copias en borrador, en los días que ese punto reparte. Revísalas y publícalas.', \count($dates))
                : sprintf('Creadas %d copias, en borrador. Revísalas —ojo a los festivos— y publícalas.', \count($dates))
        );

        return $this->redirectToRoute('volunteering_index');
    }

    /**
     * Avisar a TODA la asociación de que hace falta gente para esta tarea.
     *
     * Este alcance no lo abre nunca el automatismo, y no por prudencia técnica:
     * el permiso de notificaciones del navegador se pierde una vez y para
     * siempre, así que gastarlo tiene que ser una decisión de alguien que sabe
     * que la cosa lo merece. Un automatismo no distingue "falta gente para la
     * plantación" de "si no vienen se pierde la cosecha".
     */
    #[Route('/{id}/avisar-a-todxs', name: 'volunteering_notify_everyone', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::EDIT, subject: 'offer')]
    public function notifyEveryone(
        Request $request,
        VolunteerOffer $offer,
        VolunteerCallNotifier $notifier,
        VolunteerEventRecorder $events,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('volunteering_notify', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        $call = $notifier->dispatch(
            $offer,
            VolunteerCall::SCOPE_EVERYONE,
            $this->getUser(),
            new \DateTimeImmutable()
        );

        if (null === $call) {
            $this->addFlash('warning', 'No se ha avisado a nadie: o ya se avisó a todo el mundo por esta tarea, o no queda nadie a quien avisar.');
        } else {
            $events->forOffer($offer, VolunteerEvent::TYPE_CALL_SENT, [
                'scope' => $call->getScope(),
                'recipients' => $call->getRecipients(),
            ]);
            $em->flush();

            $this->addFlash('success', sprintf('Aviso enviado a %d socix(s).', $call->getRecipients()));
        }

        return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
    }

    /**
     * Anotar a alguien en una tarea: quien la organizó, o quien vino sin
     * haberse apuntado.
     *
     * Resuelve dos huecos que se notan en cuanto se usa esto de verdad:
     *
     *  - COORDINAR NO COMPUTABA NADA. Quien organiza el reparto todos los
     *    viernes no se apunta a las tareas, las monta, así que su contador
     *    salía a cero. La gente que más sostiene el voluntariado era
     *    precisamente la que no aparecía.
     *  - Quien apareció sin apuntarse tampoco constaba, y eso pasa constantemente.
     *
     * Se anota ya como asistido: si alguien lo está registrando a mano después,
     * es porque sabe que ocurrió.
     */
    #[Route('/{id}/anotar', name: 'volunteering_add_person', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::EDIT, subject: 'offer')]
    public function addPerson(
        Request $request,
        VolunteerOffer $offer,
        PartnerRepository $partners,
        VolunteerSignupRepository $signups,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
    ): Response {
        if (!$this->isCsrfTokenValid('volunteering_add_person', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        $partner = $partners->find($request->request->getInt('partner'));
        if (null === $partner) {
            $this->addFlash('error', 'No he encontrado a esa persona.');

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        $coordinated = 'coordinator' === $request->request->get('role');
        $minutes = $request->request->getInt('minutes') ?: null;

        // Reutiliza la inscripción si ya existía: el UNIQUE (offer, partner) no
        // admite dos, y quien se apuntó y además acabó coordinando es un caso
        // normal, no un error.
        $signup = $signups->findOneFor($offer, $partner) ?? (new VolunteerSignup())
            ->setOffer($offer)
            ->setPartner($partner);

        $signup
            ->reopen()
            ->setRole($coordinated ? VolunteerSignup::ROLE_COORDINATOR : VolunteerSignup::ROLE_PARTICIPANT)
            ->confirmAttendance(VolunteerSignup::SOURCE_MANAGER, $minutes);

        $em->persist($signup);
        $events->forOffer($offer, VolunteerEvent::TYPE_PERSON_ADDED, [
            'role' => $signup->getRole(),
            'minutes' => $signup->getCreditedMinutes(),
        ], $partner);
        $em->flush();

        $this->addFlash('success', sprintf(
            '%s %s anotadx%s.',
            $partner->getName(),
            $partner->getSurname(),
            $coordinated ? ' como quien lo organizó' : ''
        ));

        return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
    }

    /**
     * Cerrar una tarea ya pasada: decir quién fue y quién no.
     *
     * Mientras no se cierra, no computa horas a nadie: el `attended` a null es
     * significativo, y así olvidarse de cerrar una tarea no infla el contador de
     * nadie a base de gente que se apuntó y no apareció.
     */
    #[Route('/{id}/cerrar', name: 'volunteering_close', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::EDIT, subject: 'offer')]
    public function close(Request $request, VolunteerOffer $offer, EntityManagerInterface $em, VolunteerEventRecorder $events): Response
    {
        if (!$this->isCsrfTokenValid('volunteering_close', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        $attended = array_map('intval', (array) $request->request->all('attended'));
        $counted = 0;

        foreach ($offer->getSignups() as $signup) {
            if ($signup->isCancelled()) {
                continue;
            }

            $wentThere = \in_array($signup->getId(), $attended, true);

            if ($wentThere) {
                ++$counted;
            }

            // Sólo se toca lo que cambia. Sin esto, cerrar una tarea que alguien
            // ya había confirmado desde su panel la reescribiría como "lo puso
            // gestión" y se perdería el rastro de que lo dijo quien fue — que es
            // justo lo que distingue una tarea que se cerró sola de una que hubo
            // que perseguir.
            if ($signup->getAttended() === $wentThere) {
                continue;
            }

            $wentThere
                ? $signup->confirmAttendance(VolunteerSignup::SOURCE_MANAGER)
                : $signup->markAbsent(VolunteerSignup::SOURCE_MANAGER);

            // Un evento por persona y no uno por cierre: el rastro tiene que
            // poder responder "¿a quién se le computaron horas y quién lo
            // dijo?", y un único evento del cierre no lo responde.
            $events->forOffer(
                $offer,
                $wentThere ? VolunteerEvent::TYPE_ATTENDED : VolunteerEvent::TYPE_ABSENT,
                ['minutes' => $signup->getCreditedMinutes(), 'role' => $signup->getRole()],
                $signup->getPartner()
            );
        }

        $em->flush();
        $this->addFlash('success', sprintf('Tarea cerrada: %d persona(s) con horas computadas.', $counted));

        return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
    }

    /**
     * El catálogo de tipos de trabajo.
     */
    #[Route('/categorias', name: 'volunteering_categories', methods: ['GET'])]
    #[IsGranted('ROLE_GESTION_VOLUNTARIADO_EDIT')]
    public function categories(
        Request $request,
        VolunteerCategoryRepository $categories,
        PaginatorInterface $paginator,
    ): Response {
        $scope = $request->query->getAlpha('ver') ?: 'active';
        $query = trim((string) $request->query->get('q'));

        return $this->render('Volunteering/categories.html.twig', [
            'pagination' => $paginator->paginate(
                $categories->listQb($scope, $query)->getQuery(),
                $request->query->getInt('page', 1),
                25
            ),
            'counts' => $categories->counts(),
            'scope' => $scope,
            'q' => $query,
        ]);
    }

    /**
     * Crear un tipo de trabajo, en su propia pantalla.
     *
     * Estaba pegado debajo del listado, y ahí un formulario compite con la
     * tabla por la atención y alarga la página sin motivo: se entra a mirar
     * mucho más a menudo que a crear.
     */
    #[Route('/categorias/nueva', name: 'volunteering_category_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_GESTION_VOLUNTARIADO_EDIT')]
    public function newCategory(Request $request, EntityManagerInterface $em, VolunteerEventRecorder $events): Response
    {
        $category = new VolunteerCategory();
        $form = $this->createForm(VolunteerCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($category);
            $events->forCategory($category, VolunteerEvent::TYPE_CATEGORY_CREATED, [
                'name' => $category->getName(),
                'coordinators' => $this->coordinatorNames($category),
            ]);
            $em->flush();
            $this->addFlash('success', 'Tipo de trabajo creado.');

            return $this->redirectToRoute('volunteering_categories');
        }

        return $this->render('Volunteering/category_form.html.twig', [
            'category' => $category,
            'form' => $form->createView(),
            'is_new' => true,
        ]);
    }

    /**
     * Editar una categoría.
     */
    #[Route('/categorias/{id}/editar', name: 'volunteering_category_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_VOLUNTARIADO_EDIT')]
    public function editCategory(
        Request $request,
        VolunteerCategory $category,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
        VolunteerEventRepository $eventLog,
    ): Response
    {
        // Quién coordinaba ANTES: cambiar la coordinación de un área es el
        // cambio que más conviene tener registrado, y después del submit ya no
        // se puede saber quién estaba.
        $before = $this->coordinatorNames($category);
        $history = $eventLog->historyForCategory($category);

        $form = $this->createForm(VolunteerCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $after = $this->coordinatorNames($category);

            $events->forCategory(
                $category,
                $before === $after
                    ? VolunteerEvent::TYPE_CATEGORY_UPDATED
                    : VolunteerEvent::TYPE_COORDINATORS_CHANGED,
                $before === $after
                    ? ['name' => $category->getName(), 'active' => $category->isActive()]
                    : ['before' => $before, 'after' => $after]
            );

            $em->flush();
            $this->addFlash('success', 'Tipo de trabajo actualizado.');

            return $this->redirectToRoute('volunteering_categories');
        }

        return $this->render('Volunteering/category_form.html.twig', [
            'category' => $category,
            'form' => $form->createView(),
            'is_new' => false,
            'history' => $history,
            'actor_names' => $eventLog->actorNames($history),
        ]);
    }

    /**
     * Retirar o volver a ofrecer un tipo de trabajo, desde el propio listado.
     *
     * El estado se veía pero no había cómo cambiarlo sin entrar a editar y
     * buscar una casilla. Es la acción más frecuente sobre un catálogo y merece
     * estar a un clic.
     *
     * No se borra nunca: un tipo borrado se llevaría por delante el histórico de
     * tareas que lo usaron y las preferencias de quien lo tuviera marcado.
     */
    #[Route('/categorias/{id}/estado', name: 'volunteering_category_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_VOLUNTARIADO_EDIT')]
    public function toggleCategory(
        Request $request,
        VolunteerCategory $category,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
    ): Response {
        if (!$this->isCsrfTokenValid('volunteering_category_toggle', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('volunteering_categories');
        }

        $category->setActive(!$category->isActive());

        $events->forCategory($category, VolunteerEvent::TYPE_CATEGORY_UPDATED, [
            'name' => $category->getName(),
            'active' => $category->isActive(),
        ]);

        $em->flush();

        $this->addFlash('success', $category->isActive()
            ? sprintf('"%s" vuelve a ofrecerse.', $category->getName())
            : sprintf('"%s" queda retirado. El histórico se conserva.', $category->getName()));

        return $this->redirectToRoute('volunteering_categories');
    }

    /**
     * Los nombres de quienes coordinan un área, para dejarlos en el rastro.
     *
     * Nombres y no ids: un registro tiene que poder leerse dentro de dos años,
     * cuando esa cuenta a lo mejor ya no existe.
     *
     * @param VolunteerCategory $category el área
     *
     * @return list<string> los nombres, ordenados para poder comparar antes y después
     */
    private function coordinatorNames(VolunteerCategory $category): array
    {
        $names = array_map(
            static fn (User $user): string => $user->getDisplayName(),
            $category->getCoordinators()->toArray()
        );

        sort($names);

        return $names;
    }
}
