<?php

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\User;
use App\Entity\VolunteerCall;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerEvent;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerPlace;
use App\Entity\VolunteerShift;
use App\Entity\VolunteerSignup;
use App\Form\VolunteerCategoryType;
use App\Form\VolunteerOfferType;
use App\Form\VolunteerPlaceType;
use App\Form\VolunteerShiftType;
use App\Repository\PartnerRepository;
use App\Repository\VolunteerCallRepository;
use App\Repository\VolunteerCategoryRepository;
use App\Repository\VolunteerEventRepository;
use App\Repository\VolunteerOfferRepository;
use App\Repository\VolunteerPlaceRepository;
use App\Repository\VolunteerShiftRepository;
use App\Repository\VolunteerSignupRepository;
use App\Security\VolunteerOfferVoter;
use App\Service\Volunteering\CreditedTime;
use App\Service\Volunteering\ShiftGenerator;
use App\Service\Volunteering\TaskCoordinator;
use App\Service\Volunteering\VolunteerAudienceResolver;
use App\Service\Volunteering\VolunteerCallNotifier;
use App\Service\Volunteering\VolunteerEventRecorder;
use App\Service\Volunteering\VolunteerOfferChangeNotifier;
use App\Service\Volunteering\VolunteerOfferSnapshot;
use App\Service\Volunteering\VolunteerScope;
use App\Service\Volunteering\VolunteerShiftSnapshot;
use App\Service\Volunteering\VolunteerSuggester;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestión del voluntariado: definir trabajos, abrir sus turnos, ver quién se
 * apunta, pasar lista y pedir gente.
 *
 * DOS LISTADOS, PORQUE SON DOS PREGUNTAS. El de la portada del módulo es de
 * TURNOS: qué viene, qué está sin confirmar, qué se hizo y qué se quedó sin
 * cubrir — las cuatro se contestan con fechas. El de tareas es el catálogo de
 * definiciones, y se usa para retocar el trabajo en sí. Mezclarlos era lo que
 * hacía el módulo antes de tener turnos, y por eso no podía expresar un trabajo
 * que se hace dos veces al día.
 *
 * ROL PROPIO Y NO EL DE SOCIXS, y es una decisión de privacidad, no de orden.
 * Quien coordina el reparto de los viernes necesita saber quién viene ese
 * viernes; darle `ROLE_GESTION_SOCIXS` para eso le abriría las fichas, DNIs y
 * domicilios de los 246 socixs. Es el mismo criterio con el que las encuestas se
 * separaron de socixs en `security.yaml`: mínimo privilegio.
 *
 * QUIÉN PUEDE TOCAR QUÉ lo decide {@see VolunteerOfferVoter} por tarea, no este
 * atributo: quien coordina un área puede con las suyas y con ninguna más. El
 * `IsGranted` de la clase sólo abre la puerta. El voter acepta también un turno
 * y resuelve su tarea, así que las pantallas de turno no tienen que acordarse.
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
    /** Cuántas tareas se pintan por bloque en la ficha de un área. */
    private const CATEGORY_TASKS = 8;

    /** Cuántos eventos se pintan en el histórico de la ficha de un área. */
    private const CATEGORY_EVENTS = 15;

    /**
     * Los turnos: lo que viene, lo que falta por cerrar y lo que ya se hizo.
     */
    #[Route('', name: 'volunteering_index', methods: ['GET'])]
    public function index(
        Request $request,
        VolunteerShiftRepository $shifts,
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
            $shifts->listQb($scope, $category, $query, $now, $mine)->getQuery(),
            $request->query->getInt('page', 1),
            25
        );

        return $this->render('Volunteering/index.html.twig', [
            'pagination' => $pagination,
            'counts' => $shifts->counts($now, $mine),
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
     * El catálogo de trabajos: qué hace la asociación, en qué estado está cada
     * cosa y cuál hay que retocar.
     */
    #[Route('/tareas', name: 'volunteering_tasks', methods: ['GET'])]
    public function tasks(
        Request $request,
        VolunteerOfferRepository $offers,
        VolunteerCategoryRepository $categories,
        PaginatorInterface $paginator,
        VolunteerScope $scopeOf,
    ): Response {
        $scope = $request->query->getAlpha('ver') ?: VolunteerOffer::STATUS_PUBLISHED;
        $categoryId = $request->query->getInt('tipo') ?: null;
        $category = null !== $categoryId ? $categories->find($categoryId) : null;
        $query = trim((string) $request->query->get('q'));
        $mine = $scopeOf->categories();

        return $this->render('Volunteering/tasks.html.twig', [
            'pagination' => $paginator->paginate(
                $offers->listQb($scope, $category, $query, $mine)->getQuery(),
                $request->query->getInt('page', 1),
                25
            ),
            'counts' => $offers->counts($mine),
            'categories' => $mine ?? $categories->findActive(),
            'scope' => $scope,
            'current' => $category,
            'q' => $query,
            'now' => new \DateTime(),
            'coordinates_something' => $scopeOf->coordinatesSomething(),
            'sees_everything' => $scopeOf->seesEverything(),
        ]);
    }

    /**
     * La bolsa: quién hay para cada área.
     *
     * Es lo primero que echa en falta quien coordina un área: la aplicación sabe
     * decir quién se apuntó a UN turno, pero no a quién se puede llamar para
     * huerta. Sin eso, coordinar obliga a salirse de la herramienta y tirar del
     * grupo de WhatsApp.
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
        VolunteerCategoryRepository $categories,
    ): Response {
        $type = $request->query->getAlpha('tipo') ?: null;
        $type = null !== $type && isset(VolunteerEvent::LABELS[$type]) ? $type : null;

        // El historial de una persona: no hay ficha de socix dentro del módulo,
        // así que se entra aquí desde su fila en "Quién hay".
        $who = $request->query->getInt('socix') ?: null;
        $who = null !== $who ? $partners->find($who) : null;

        // Filtro por área, que es por dónde se entra desde su ficha. El
        // parámetro se llama `area` y no `tipo` porque `tipo` ya es el tipo de
        // EVENTO en esta pantalla, y reusarlo dejaría dos filtros peleándose por
        // el mismo nombre.
        $areaId = $request->query->getInt('area') ?: null;
        $area = null !== $areaId ? $categories->find($areaId) : null;

        // El alcance de quien mira; null significa que lo ve todo.
        $mine = $scopeOf->categories();

        // SE INTERSECA con ese alcance, no lo sustituye: pedir por URL un área
        // que no es tuya tiene que devolver vacío, no abrirla. Un filtro que
        // amplía permisos no da error, sólo enseña lo que no debía.
        $restrict = $mine;
        if (null !== $area) {
            $restrict = (null === $mine || \in_array($area, $mine, true)) ? [$area] : [];
        }

        $pagination = $paginator->paginate(
            $events->feedQb($restrict, $type, $who)->getQuery(),
            $request->query->getInt('page', 1),
            30
        );

        return $this->render('Volunteering/activity.html.twig', [
            'pagination' => $pagination,
            'who' => $who,
            'area' => $area,
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
     * Definir un trabajo nuevo.
     *
     * Sin gate de rol en la ruta: quien coordina un área también publica tareas
     * suyas, y no tiene el rol global de escritura. El permiso se comprueba
     * DESPUÉS de rellenar el formulario, cuando ya se sabe de qué área es la
     * tarea — antes no hay nada sobre lo que decidir.
     *
     * NACE EN BORRADOR SIEMPRE, y los turnos se abren ya: así se pueden repasar
     * —y anular el que caiga en festivo— antes de que la tarea empiece a pedir
     * gente. Publicar es el gesto siguiente, y tiene su propio botón.
     */
    #[Route('/nueva', name: 'volunteering_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
        ShiftGenerator $generator,
        TaskCoordinator $coordination,
    ): Response {
        $offer = (new VolunteerOffer())->setCreatedBy($this->getUser());
        $form = $this->createForm(VolunteerOfferType::class, $offer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Publicar una tarea de un área que no coordinas es lo mismo que
            // editar la de otra persona. Se comprueba aquí porque hasta ahora la
            // tarea no tenía categorías que mirar.
            $this->denyAccessUnlessGranted(VolunteerOfferVoter::EDIT, $offer);

            // Si el área tiene una sola persona coordinándola, ya sabemos quién
            // monta esto y no hace falta preguntarlo. Con varias, se eligió en
            // el formulario y esto no toca nada.
            $coordination->assignIfObvious($offer);

            $em->persist($offer);
            $events->forOffer($offer, VolunteerEvent::TYPE_OFFER_CREATED, ['status' => $offer->getStatus()]);

            $created = $generator->generate($offer);
            if ([] !== $created) {
                $events->forOffer($offer, VolunteerEvent::TYPE_SHIFTS_OPENED, ['times' => \count($created)]);
            }

            $em->flush();

            $this->addFlash('success', sprintf(
                'Tarea creada en borrador, con %d turno(s) abiertos. Repásalos y publícala.',
                \count($created)
            ));

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        return $this->render('Volunteering/form.html.twig', [
            'offer' => $offer,
            'form' => $form->createView(),
            'is_new' => true,
        ]);
    }

    /**
     * Una tarea: qué es, cómo se repite, y sus turnos.
     *
     * LO QUE MANDA ES EL TRABAJO PENDIENTE. De una tarea continua no interesan
     * sus doscientos turnos: interesan los que hay que cerrar —que si se
     * acumulan dejan a la gente sin horas— y el siguiente. De ahí el orden de la
     * pantalla.
     */
    #[Route('/tarea/{id}', name: 'volunteering_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::VIEW, subject: 'offer')]
    public function show(
        VolunteerOffer $offer,
        VolunteerCallRepository $calls,
        VolunteerEventRepository $events,
        ShiftGenerator $generator,
    ): Response {
        $now = new \DateTime();
        $history = $events->historyFor($offer);

        return $this->render('Volunteering/show.html.twig', [
            'offer' => $offer,
            'now' => $now,
            'to_close' => $offer->getShiftsToClose($now),
            'upcoming' => $offer->getUpcomingShifts($now),
            // Cuántos turnos alcanzaría la receta si se abrieran ahora: es lo
            // que dice si merece la pena el botón de extender.
            'horizon_days' => ShiftGenerator::HORIZON_DAYS,
            'pending_moments' => \count($generator->moments($offer, $now)),
            'calls' => $calls->findForOffer($offer),
            // El historial de ESTA tarea. Sin él, el rastro sólo se podía
            // consultar en el listado global, que es justo donde no estás
            // cuando quieres saber qué le ha pasado a una tarea concreta.
            'history' => $history,
            'actor_names' => $events->actorNames($history),
        ]);
    }

    /**
     * Editar la definición de un trabajo.
     *
     * Al guardar, los turnos se ponen al día con la receta: se abren los que
     * falten y se retiran los que la receta ya no dicta. Los que tienen gente
     * apuntada NO se retiran —alguien cuenta con ese día— y se dice cuántos son,
     * para que quien edita decida si los anula a mano.
     *
     * Si el cambio afecta a quien ya se apuntó —se anula, se para o cambia de
     * sitio— se le avisa. Sin eso, anular una tarea deja a alguien plantándose
     * allí para nada, y esa persona, que es justo la que sí colabora, no vuelve.
     */
    #[Route('/tarea/{id}/editar', name: 'volunteering_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::EDIT, subject: 'offer')]
    public function edit(
        Request $request,
        VolunteerOffer $offer,
        EntityManagerInterface $em,
        VolunteerOfferChangeNotifier $changes,
        VolunteerEventRecorder $events,
        TaskCoordinator $coordination,
        ShiftGenerator $generator,
    ): Response {
        // La foto se toma ANTES de handleRequest: después, la entidad ya lleva
        // los valores nuevos y el original se ha perdido.
        $before = VolunteerOfferSnapshot::of($offer);

        $form = $this->createForm(VolunteerOfferType::class, $offer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $events->forOffer($offer, VolunteerEvent::TYPE_OFFER_UPDATED, [
                'relocated' => $before->relocatedIn($offer),
            ]);

            // Al cambiarle el área a una tarea que no tenía coordinador, puede
            // que ahora sí se sepa quién la lleva.
            $coordination->assignIfObvious($offer);

            $sync = $generator->sync($offer);

            $em->flush();

            $notified = $changes->notifyChanges($offer, $before);

            $this->addFlash('success', $this->describeSync($sync, $notified));

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        return $this->render('Volunteering/form.html.twig', [
            'offer' => $offer,
            'form' => $form->createView(),
            'is_new' => false,
        ]);
    }

    /**
     * Publicar, parar, reanudar o anular una tarea.
     *
     * UN SOLO SITIO PARA LOS CUATRO, porque son la misma decisión con distinto
     * signo y comparten lo que hay que hacer después: dejar rastro, avisar a
     * quien esté apuntado y contarlo. Repartirlos en cuatro acciones era tener
     * cuatro sitios donde olvidarse del aviso.
     *
     * PUBLICAR ES EL GESTO QUE MOLESTA A LA ASOCIACIÓN, y por eso no está en el
     * formulario: se hace desde la ficha, donde la pantalla puede decir a cuánta
     * gente se le va a pedir esto antes de darle al botón.
     */
    #[Route('/tarea/{id}/estado', name: 'volunteering_status', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::EDIT, subject: 'offer')]
    public function status(
        Request $request,
        VolunteerOffer $offer,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
        VolunteerOfferChangeNotifier $changes,
    ): Response {
        // Vuelve a la pantalla desde la que se pulsó. Desde la ficha de un área
        // llega su id; sin él, a la ficha de la tarea.
        $fromCategory = $request->request->getInt('from_category') ?: null;
        $back = null !== $fromCategory
            ? $this->redirectToRoute('volunteering_category_show', ['id' => $fromCategory])
            : $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);

        if (!$this->isCsrfTokenValid('volunteering_status', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $back;
        }

        $wanted = (string) $request->request->get('estado');

        // Lista blanca y no confianza en el POST: `setStatus()` acepta cualquier
        // cadena y la validación de la entidad no corre en este camino, así que
        // sin esto un valor cualquiera dejaría la tarea en un estado que ninguna
        // consulta reconoce y desaparecería de todas las pantallas.
        if (!\in_array($wanted, VolunteerOffer::STATUSES, true)) {
            $this->addFlash('error', 'Ese estado no existe.');

            return $back;
        }

        $before = VolunteerOfferSnapshot::of($offer);
        $offer->setStatus($wanted);

        $events->forOffer($offer, match ($wanted) {
            VolunteerOffer::STATUS_PUBLISHED => VolunteerEvent::TYPE_OFFER_PUBLISHED,
            VolunteerOffer::STATUS_PAUSED => VolunteerEvent::TYPE_OFFER_PAUSED,
            VolunteerOffer::STATUS_CANCELLED => VolunteerEvent::TYPE_OFFER_CANCELLED,
            default => VolunteerEvent::TYPE_OFFER_UPDATED,
        }, ['status' => $wanted]);

        $em->flush();

        $notified = $changes->notifyChanges($offer, $before);

        $this->addFlash('success', match ($wanted) {
            VolunteerOffer::STATUS_PUBLISHED => 'Tarea publicada: ya se puede apuntar la gente.',
            VolunteerOffer::STATUS_PAUSED => $this->withNotified('Tarea parada. Sus turnos por venir dejan de pedir gente.', $notified),
            VolunteerOffer::STATUS_CANCELLED => $this->withNotified('Tarea anulada. Se conserva con su historia.', $notified),
            default => 'Tarea vuelta a borrador.',
        });

        return $back;
    }

    /**
     * Abrir más turnos de una tarea que se repite: extiende la serie hasta donde
     * llegue el horizonte.
     *
     * Existe porque los turnos se materializan por tramos y no de golpe. Lo
     * normal es que lo haga el planificador; el botón está para no tener que
     * esperar al cron cuando alguien acaba de ampliar la fecha final.
     */
    #[Route('/tarea/{id}/turnos', name: 'volunteering_open_shifts', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::EDIT, subject: 'offer')]
    public function openShifts(
        Request $request,
        VolunteerOffer $offer,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
        ShiftGenerator $generator,
    ): Response {
        if (!$this->isCsrfTokenValid('volunteering_open_shifts', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        $created = $generator->generate($offer);

        if ([] !== $created) {
            $events->forOffer($offer, VolunteerEvent::TYPE_SHIFTS_OPENED, ['times' => \count($created)]);
        }

        $em->flush();

        $this->addFlash([] === $created ? 'warning' : 'success', [] === $created
            ? 'No hay turnos nuevos que abrir: la receta ya está cubierta hasta donde alcanza.'
            : sprintf('Abiertos %d turno(s).', \count($created)));

        return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
    }

    /**
     * Añadir un turno a mano, para el día que la receta no cubre: la plantación
     * del sábado que viene, la tarde que hay que recuperar.
     */
    #[Route('/tarea/{id}/turno-nuevo', name: 'volunteering_shift_new', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::EDIT, subject: 'offer')]
    public function newShift(
        Request $request,
        VolunteerOffer $offer,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
    ): Response {
        $shift = new VolunteerShift();
        $form = $this->createForm(VolunteerShiftType::class, $shift);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Marcado a mano: la receta no lo dicta, así que el sync no puede
            // retirarlo la próxima vez que se guarde la tarea.
            $shift->setManual(true);

            $offer->addShift($shift);
            $em->persist($shift);

            $events->forOffer($offer, VolunteerEvent::TYPE_SHIFTS_OPENED, [
                'times' => 1,
                'by_hand' => true,
            ]);

            $em->flush();
            $this->addFlash('success', 'Turno añadido.');

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        return $this->render('Volunteering/shift_form.html.twig', [
            'offer' => $offer,
            'shift' => $shift,
            'form' => $form->createView(),
            'is_new' => true,
        ]);
    }

    /**
     * Un turno: quién viene, pasar lista, y los avisos que se han mandado por
     * él.
     *
     * La pantalla se organiza por la FASE del turno
     * ({@see VolunteerShift::getPhase()}), porque los tres momentos piden
     * trabajos distintos: antes se persiguen plazas, el día se pasa lista,
     * después se imputan horas. Aquí eso se nota en que hay datos que sólo se
     * cargan cuando sirven para algo — las sugerencias de a quién pedirle no se
     * calculan para un turno que ya pasó.
     */
    #[Route('/turno/{id}', name: 'volunteering_shift', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::VIEW, subject: 'shift')]
    public function shift(
        VolunteerShift $shift,
        VolunteerCallRepository $calls,
        VolunteerAudienceResolver $audience,
        PartnerRepository $partners,
        VolunteerSignupRepository $signups,
        VolunteerSuggester $suggester,
    ): Response {
        $sent = $calls->sentScopes($shift);
        $phase = $shift->getPhase();
        $year = (int) date('Y');

        return $this->render('Volunteering/shift.html.twig', [
            'shift' => $shift,
            'offer' => $shift->getOffer(),
            'phase' => $phase,
            'sent_scopes' => $sent,
            // Lo que cada persona lleva hecho, en UNA consulta para toda la
            // tabla: preguntarlo fila a fila sería un N+1 con tantas consultas
            // como gente apuntada.
            'participation' => $signups->participationByPartner(
                new \DateTime(sprintf('%d-01-01 00:00:00', $year)),
                new \DateTime(sprintf('%d-12-31 23:59:59', $year))
            ),
            // A quién pedírselo. Sólo mientras siga faltando gente: en un turno
            // pasado la lista no serviría para nada y costaría dos consultas.
            'suggested' => VolunteerShift::PHASE_OPEN === $phase && $shift->hasRoom()
                ? $suggester->forShift($shift)
                : [],
            // Para anotar a mano a quien organizó el turno o vino sin apuntarse.
            'all_partners' => $partners->findBy(
                ['status' => Partner::STATUS_ACTIVO],
                ['name' => 'ASC', 'surname' => 'ASC']
            ),
            // Cuánta gente recibiría el aviso general, para que quien pulsa el
            // botón vea el número ANTES de molestar a media asociación.
            'everyone_count' => $audience->count($shift, VolunteerCall::SCOPE_EVERYONE),
            'everyone_sent' => \in_array(VolunteerCall::SCOPE_EVERYONE, $sent, true),
        ]);
    }

    /**
     * Mover un turno de fecha, hora, plazas o minutos.
     *
     * A quien esté apuntado se le avisa del cambio de fecha: es el aviso que
     * menos se puede saltar, porque si no esa persona se planta el día que ya no
     * es.
     */
    #[Route('/turno/{id}/editar', name: 'volunteering_shift_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::EDIT, subject: 'shift')]
    public function editShift(
        Request $request,
        VolunteerShift $shift,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
        VolunteerOfferChangeNotifier $changes,
    ): Response {
        $offer = $shift->getOffer();
        $before = VolunteerShiftSnapshot::of($shift);

        $form = $this->createForm(VolunteerShiftType::class, $shift);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Tocado a mano: a partir de ahora este turno le gana a la receta y
            // el sync no lo retira. Sin esto, mover el turno de un viernes con
            // asamblea se deshacía al guardar cualquier cosa de la tarea.
            $shift->setManual(true);

            if (null !== $offer && $before->movedIn($shift)) {
                $events->forOffer($offer, VolunteerEvent::TYPE_SHIFT_MOVED, [
                    'before' => $before->startsAt?->format('d/m/Y H:i'),
                    'after' => $shift->getStartsAt()?->format('d/m/Y H:i'),
                ]);
            }

            $em->flush();

            $notified = $changes->notifyShiftChanges($shift, $before);

            $this->addFlash('success', $this->withNotified('Turno actualizado.', $notified));

            return $this->redirectToRoute('volunteering_shift', ['id' => $shift->getId()]);
        }

        return $this->render('Volunteering/shift_form.html.twig', [
            'offer' => $offer,
            'shift' => $shift,
            'form' => $form->createView(),
            'is_new' => false,
        ]);
    }

    /**
     * Anular un turno, o volver a ponerlo en pie.
     *
     * ES EL GESTO DEL FESTIVO, y el que hace que la receta pueda vivir en la
     * tarea: "esto se hace los viernes" sigue siendo verdad aunque el viernes 25
     * de diciembre no se haga. La fila no se borra —quien estuviera apuntado
     * tiene que poder ver qué pasó— y el generador no la vuelve a crear.
     */
    #[Route('/turno/{id}/anular', name: 'volunteering_shift_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::EDIT, subject: 'shift')]
    public function cancelShift(
        Request $request,
        VolunteerShift $shift,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
        VolunteerOfferChangeNotifier $changes,
    ): Response {
        if (!$this->isCsrfTokenValid('volunteering_shift_cancel', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('volunteering_shift', ['id' => $shift->getId()]);
        }

        $offer = $shift->getOffer();
        $before = VolunteerShiftSnapshot::of($shift);
        $reopen = $request->request->getBoolean('reactivar');

        if ($reopen) {
            $shift->reopen();
        } else {
            $reason = trim((string) $request->request->get('motivo'));
            $shift->cancel('' !== $reason ? mb_substr($reason, 0, 160) : null);
        }

        if (null !== $offer) {
            $events->forOffer($offer, VolunteerEvent::TYPE_SHIFT_CANCELLED, [
                'when' => $shift->getStartsAt()?->format('d/m/Y H:i'),
                'again' => $reopen,
                'name' => $shift->getCancelledReason(),
            ]);
        }

        $em->flush();

        $notified = $changes->notifyShiftChanges($shift, $before);

        $this->addFlash('success', $reopen
            ? 'Turno de nuevo en pie.'
            : $this->withNotified('Turno anulado.', $notified));

        return $this->redirectToRoute('volunteering_shift', ['id' => $shift->getId()]);
    }

    /**
     * Apuntar gente de fuera en un turno: un grupo de estudiantes, gente de otra
     * asociación, quien pasaba por allí.
     *
     * VA EN EL TURNO Y NO EN LA TAREA, y ahí estaba el error: que un martes
     * vinieran tres estudiantes no dice nada del martes siguiente. Y va como
     * acción de la lista de quién viene, no como campo del formulario de alta:
     * es un dato del día, y en el alta sólo estorbaba.
     */
    #[Route('/turno/{id}/gente-de-fuera', name: 'volunteering_shift_guests', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::EDIT, subject: 'shift')]
    public function guests(
        Request $request,
        VolunteerShift $shift,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('volunteering_shift_guests', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('volunteering_shift', ['id' => $shift->getId()]);
        }

        // Con getInt() un campo vacío revienta con un 400: `filter_var('',
        // FILTER_VALIDATE_INT)` falla y InputBag lo convierte en
        // BadRequestException.
        $howMany = (int) $request->request->get('cuantos');
        $note = trim((string) $request->request->get('quienes'));

        $shift->setGuests(min(99, max(0, $howMany)));
        $shift->setGuestsNote('' !== $note ? mb_substr($note, 0, 160) : null);

        $em->flush();

        $this->addFlash('success', 0 === $shift->getGuests()
            ? 'Quitada la gente de fuera de este turno.'
            : sprintf('Apuntada gente de fuera: %d.', $shift->getGuests()));

        return $this->redirectToRoute('volunteering_shift', ['id' => $shift->getId()]);
    }

    /**
     * Avisar a TODA la asociación de que hace falta gente para este turno.
     *
     * Este alcance no lo abre nunca el automatismo, y no por prudencia técnica:
     * el permiso de notificaciones del navegador se pierde una vez y para
     * siempre, así que gastarlo tiene que ser una decisión de alguien que sabe
     * que la cosa lo merece. Un automatismo no distingue "falta gente para la
     * plantación" de "si no vienen se pierde la cosecha".
     */
    #[Route('/turno/{id}/avisar-a-todxs', name: 'volunteering_notify_everyone', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::EDIT, subject: 'shift')]
    public function notifyEveryone(
        Request $request,
        VolunteerShift $shift,
        VolunteerCallNotifier $notifier,
        VolunteerEventRecorder $events,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('volunteering_notify', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('volunteering_shift', ['id' => $shift->getId()]);
        }

        $call = $notifier->dispatch(
            $shift,
            VolunteerCall::SCOPE_EVERYONE,
            $this->getUser(),
            new \DateTimeImmutable()
        );

        if (null === $call) {
            $this->addFlash('warning', 'No se ha avisado a nadie: o ya se avisó a todo el mundo por este turno, o no queda nadie a quien avisar.');
        } else {
            $offer = $shift->getOffer();
            if (null !== $offer) {
                $events->forOffer($offer, VolunteerEvent::TYPE_CALL_SENT, [
                    'scope' => $call->getScope(),
                    'recipients' => $call->getRecipients(),
                ]);
            }
            $em->flush();

            $this->addFlash('success', sprintf('Aviso enviado a %d socix(s).', $call->getRecipients()));
        }

        return $this->redirectToRoute('volunteering_shift', ['id' => $shift->getId()]);
    }

    /**
     * Anotar a alguien en un turno: quien lo organizó, o quien vino sin haberse
     * apuntado.
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
    #[Route('/turno/{id}/anotar', name: 'volunteering_add_person', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::EDIT, subject: 'shift')]
    public function addPerson(
        Request $request,
        VolunteerShift $shift,
        PartnerRepository $partners,
        VolunteerSignupRepository $signups,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
    ): Response {
        if (!$this->isCsrfTokenValid('volunteering_add_person', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('volunteering_shift', ['id' => $shift->getId()]);
        }

        $partner = $partners->find($request->request->getInt('partner'));
        if (null === $partner) {
            $this->addFlash('error', 'No he encontrado a esa persona.');

            return $this->redirectToRoute('volunteering_shift', ['id' => $shift->getId()]);
        }

        // Reutiliza la inscripción si ya existía: el UNIQUE (shift, partner) no
        // admite dos.
        $existing = $signups->findOneFor($shift, $partner);

        // Quien ya consta como que fue no se vuelve a anotar. Sin esto, un
        // segundo envío —doble clic, volver atrás en el navegador, o
        // sencillamente no acordarse— le reescribía las horas en silencio y sin
        // dejar rastro de que había pasado dos veces.
        if (null !== $existing && !$existing->isCancelled() && true === $existing->getAttended()) {
            $this->addFlash('warning', sprintf(
                '%s %s ya constaba en este turno, con %s h. Si hay que corregirle las horas, hazlo en la lista de arriba.',
                $partner->getName(),
                $partner->getSurname(),
                str_replace('.', ',', (string) (($existing->getCreditedMinutes() ?? 0) / 60))
            ));

            return $this->redirectToRoute('volunteering_shift', ['id' => $shift->getId()]);
        }

        // Con getInt() esto reventaba con un 400: `filter_var('', FILTER_VALIDATE_INT)`
        // falla y InputBag lo convierte en BadRequestException — la página se
        // caía al dejar el campo vacío.
        $minutes = CreditedTime::minutesFromHours($request->request->get('hours'));

        // Las horas se dicen, NO se deducen del turno. Anotar a alguien a mano
        // es un acto deliberado —quien lo hace sabe cuánto estuvo esa persona— y
        // rellenar el hueco por él escondía dos cosas: cuánto se estaba
        // imputando, y que en una tarea sin fijar lo que vale se anotaba a
        // alguien aportando cero.
        if (null === $minutes || $minutes <= 0) {
            $this->addFlash('error', 'Pon cuántas horas se le computan: sin eso no se puede anotar a nadie.');

            return $this->redirectToRoute('volunteering_shift', ['id' => $shift->getId()]);
        }

        $signup = $existing ?? (new VolunteerSignup())
            ->setShift($shift)
            ->setPartner($partner);

        $signup
            ->reopen()
            ->confirmAttendance(VolunteerSignup::SOURCE_MANAGER, $minutes);

        $em->persist($signup);

        $offer = $shift->getOffer();
        if (null !== $offer) {
            $events->forOffer($offer, VolunteerEvent::TYPE_PERSON_ADDED, [
                'role' => $signup->getRole(),
                'minutes' => $signup->getCreditedMinutes(),
            ], $partner);
        }

        $em->flush();

        $this->addFlash('success', sprintf('%s %s anotadx.', $partner->getName(), $partner->getSurname()));

        return $this->redirectToRoute('volunteering_shift', ['id' => $shift->getId()]);
    }

    /**
     * Quitar una inscripción del turno.
     *
     * NO ES LO MISMO QUE "no fue", y por eso es un gesto aparte. Que alguien se
     * apuntara y no apareciera es un hecho que conviene conservar: dice que la
     * plaza se quedó sin cubrir y es el dato que explica por qué un turno salió
     * mal. Esto otro es para cuando la inscripción NO DEBERÍA EXISTIR —se anotó
     * a quien no era, o por duplicado—, y ahí guardarla sería guardar una
     * mentira.
     *
     * Borra de verdad en vez de cancelar, por lo mismo: una baja deja constancia
     * de que alguien se descolgó, y quien nunca estuvo no se descolgó de nada.
     *
     * Comparte el token del formulario de cierre porque el botón vive DENTRO de
     * ese formulario, como una acción alternativa de la misma fila; pedir un
     * token propio obligaría a un campo oculto por inscripción para no ganar
     * nada.
     */
    #[Route('/turno/{id}/quitar/{signup}', name: 'volunteering_remove_person', methods: ['POST'], requirements: ['id' => '\d+', 'signup' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::EDIT, subject: 'shift')]
    public function removePerson(
        Request $request,
        VolunteerShift $shift,
        int $signup,
        VolunteerSignupRepository $signups,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
    ): Response {
        if (!$this->isCsrfTokenValid('volunteering_close', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('volunteering_shift', ['id' => $shift->getId()]);
        }

        $found = $signups->find($signup);

        // Que la inscripción sea DE ESTE TURNO se comprueba a mano: el voter ha
        // dado permiso sobre el turno de la URL, no sobre una inscripción que
        // podría ser de otro y colarse cambiando el número.
        if (null === $found || $found->getShift() !== $shift) {
            $this->addFlash('error', 'Esa inscripción no es de este turno.');

            return $this->redirectToRoute('volunteering_shift', ['id' => $shift->getId()]);
        }

        $partner = $found->getPartner();
        $offer = $shift->getOffer();

        // Queda el rastro aunque la fila se vaya: si desaparecen las horas de
        // alguien, tiene que poder saberse quién las quitó y cuándo.
        if (null !== $offer) {
            $events->forOffer($offer, VolunteerEvent::TYPE_WITHDRAW, [
                'removed_by_manager' => true,
                'minutes' => $found->getCreditedMinutes(),
            ], $partner);
        }

        $em->remove($found);
        $em->flush();

        $this->addFlash('success', sprintf(
            '%s %s ya no consta en este turno.',
            $partner?->getName(),
            $partner?->getSurname()
        ));

        return $this->redirectToRoute('volunteering_shift', ['id' => $shift->getId()]);
    }

    /**
     * Cerrar un turno ya pasado: decir quién fue, quién no, y cuánto se le
     * computa a cada cual.
     *
     * Mientras no se cierra, no computa horas a nadie: el `attended` a null es
     * significativo, y así olvidarse de cerrar un turno no infla el contador de
     * nadie a base de gente que se apuntó y no apareció.
     *
     * LOS MINUTOS VAN POR PERSONA y no por turno, porque la realidad es ésa:
     * alguien se queda media hora menos, alguien llega tarde, y quien lo
     * organizó suele computar otra cosa. Dejar el campo en blanco usa los del
     * turno, que es el caso normal.
     */
    #[Route('/turno/{id}/cerrar', name: 'volunteering_close', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::EDIT, subject: 'shift')]
    public function close(
        Request $request,
        VolunteerShift $shift,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
    ): Response {
        if (!$this->isCsrfTokenValid('volunteering_close', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('volunteering_shift', ['id' => $shift->getId()]);
        }

        $offer = $shift->getOffer();
        $attended = array_map('intval', (array) $request->request->all('attended'));
        $hours = (array) $request->request->all('hours');

        // Los acompañantes los ponía SÓLO quien se apunta, desde su panel. Pero
        // el caso normal es una llamada —"voy con mi pareja"— y quien coordina no
        // tenía dónde apuntarlo, así que el turno seguía pidiendo una plaza que
        // ya estaba ocupada y venía gente de más.
        $companions = (array) $request->request->all('companions');
        $counted = 0;

        // Cerrar de verdad, o sólo corregir lo ya anotado. La diferencia está en
        // qué significa una casilla sin marcar: al cerrar, "no fue"; mientras el
        // turno no ha pasado, "todavía no ha pasado nada" — y darle por ausente
        // a quien está apuntado esperando el día sería inventarse un hecho.
        $closingAll = $request->request->getBoolean('close_all');

        foreach ($shift->getSignups() as $signup) {
            if ($signup->isCancelled()) {
                continue;
            }

            // QUIEN COORDINA LO DECLARA ÉL, en su panel, y diciendo cuántas horas
            // le llevó. Ni sale en esta lista —lo normal es que no vaya: monta la
            // tarea, busca gente y está pendiente— ni el cierre se lo imputa por
            // su cuenta: una tarea vale lo que la asociación decidió, igual para
            // todo el mundo, pero coordinarla no se parece a la media hora de
            // bajar cajas y sólo lo sabe quien lo hizo.
            if ($signup->isCoordination()) {
                continue;
            }

            // Los acompañantes se guardan pase lo que pase con la asistencia:
            // decir "viene con su pareja" no es decir si vino, y son las dos
            // cosas que se apuntan en esta pantalla antes de que llegue el día.
            if (isset($companions[$signup->getId()]) && '' !== trim((string) $companions[$signup->getId()])) {
                $signup->setCompanions(max(0, min(9, (int) $companions[$signup->getId()])));
            }

            $wentThere = \in_array($signup->getId(), $attended, true);

            if (!$wentThere) {
                // Corrigiendo, quien no ha respondido se queda como está: la
                // casilla vacía no dice "no fue", dice "aún no se sabe".
                if (!$closingAll && null === $signup->getAttended()) {
                    continue;
                }

                // Sólo se toca lo que cambia. Sin esto, cerrar un turno que
                // alguien ya había confirmado desde su panel lo reescribiría
                // como "lo puso gestión" y se perdería el rastro de que lo dijo
                // quien fue — que es justo lo que distingue un turno que se
                // cerró solo de uno que hubo que perseguir.
                if (false === $signup->getAttended()) {
                    continue;
                }

                $signup->markAbsent(VolunteerSignup::SOURCE_MANAGER);

                // Un evento por persona y no uno por cierre: el rastro tiene que
                // poder responder "¿a quién se le computaron horas y quién lo
                // dijo?", y un único evento del cierre no lo responde.
                if (null !== $offer) {
                    $events->forOffer(
                        $offer,
                        VolunteerEvent::TYPE_ABSENT,
                        ['minutes' => null, 'role' => $signup->getRole()],
                        $signup->getPartner()
                    );
                }

                continue;
            }

            ++$counted;

            $wanted = CreditedTime::minutesFromHours($hours[$signup->getId()] ?? null)
                ?? $shift->getCreditedMinutes();

            if (true !== $signup->getAttended()) {
                $signup->confirmAttendance(VolunteerSignup::SOURCE_MANAGER, $wanted);
            } elseif ($signup->getCreditedMinutes() !== $wanted) {
                // Corregir los minutos de quien ya había confirmado NO le
                // arrebata la autoría: lo que gestión cambia es cuánto vale,
                // no quién dijo que fue. Por eso se toca el campo suelto y no
                // se pasa por confirmAttendance(), que reescribiría la fuente
                // a "lo puso gestión".
                $signup->setCreditedMinutes($wanted);
            } else {
                continue;
            }

            if (null !== $offer) {
                $events->forOffer(
                    $offer,
                    VolunteerEvent::TYPE_ATTENDED,
                    ['minutes' => $signup->getCreditedMinutes(), 'role' => $signup->getRole()],
                    $signup->getPartner()
                );
            }
        }

        $em->flush();
        $this->addFlash('success', sprintf('Turno cerrado: %d persona(s) con horas computadas.', $counted));

        return $this->redirectToRoute('volunteering_shift', ['id' => $shift->getId()]);
    }

    /**
     * Los sitios donde se hace voluntariado.
     *
     * Catálogo y no texto libre porque con turnos el sitio se escribiría en
     * cientos de filas: acabas con "la nave", "nave" y "Nave" siendo el mismo
     * sitio. Son cuatro o cinco en toda la asociación.
     */
    #[Route('/sitios', name: 'volunteering_places', methods: ['GET'])]
    #[IsGranted('ROLE_GESTION_VOLUNTARIADO_EDIT')]
    public function places(VolunteerPlaceRepository $places): Response
    {
        return $this->render('Volunteering/places.html.twig', [
            'places' => $places->findAllSorted(),
        ]);
    }

    /**
     * Crear o editar un sitio.
     *
     * Una sola acción para los dos: el formulario es el mismo y separarlos sería
     * duplicar seis líneas para no ganar nada.
     */
    #[Route('/sitios/nuevo', name: 'volunteering_place_new', methods: ['GET', 'POST'])]
    #[Route('/sitios/{id}/editar', name: 'volunteering_place_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_VOLUNTARIADO_EDIT')]
    public function placeForm(
        Request $request,
        EntityManagerInterface $em,
        ?VolunteerPlace $place = null,
    ): Response {
        $isNew = null === $place;
        $place ??= new VolunteerPlace();

        $form = $this->createForm(VolunteerPlaceType::class, $place);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($place);
            $em->flush();

            $this->addFlash('success', $isNew ? 'Sitio creado.' : 'Sitio actualizado.');

            return $this->redirectToRoute('volunteering_places');
        }

        return $this->render('Volunteering/place_form.html.twig', [
            'place' => $place,
            'form' => $form->createView(),
            'is_new' => $isNew,
        ]);
    }

    /**
     * El catálogo de áreas.
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
     * Crear un área, en su propia pantalla.
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
            $this->addFlash('success', 'Área creada.');

            return $this->redirectToRoute('volunteering_categories');
        }

        return $this->render('Volunteering/category_form.html.twig', [
            'category' => $category,
            'form' => $form->createView(),
            'is_new' => true,
        ]);
    }

    /**
     * La ficha de un área: quién la coordina, qué se está haciendo dentro, quién
     * hay disponible y qué ha pasado.
     *
     * NACE PORQUE EL ÁREA NO TENÍA DÓNDE MIRARSE. Del catálogo sólo se podía ir
     * a editar, y editar es para cambiar el nombre: no dice si el área está
     * viva, si tiene tareas sin cerrar o si la sostiene una sola persona. Eso es
     * lo que necesita quien coordina, y estaba repartido entre tres pantallas
     * con el filtro puesto a mano.
     *
     * TODO VIENE LIMITADO Y CON SALIDA al listado completo filtrado por el área.
     * Un área con dos años de tareas dentro llenaría la ficha de scroll y
     * dejaría de servir para lo que sirve, que es responder de un vistazo.
     */
    #[Route('/categorias/{id}', name: 'volunteering_category_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_VOLUNTARIADO_EDIT')]
    public function showCategory(
        VolunteerCategory $category,
        VolunteerShiftRepository $shifts,
        VolunteerEventRepository $eventLog,
    ): Response {
        $now = new \DateTime();

        // DE TURNOS Y NO DE TAREAS: «lo que falta cerrar» y «lo que viene» son
        // preguntas con fecha, y desde que el momento vive en su propia fila las
        // contesta el repositorio de turnos.
        //
        // DOS CONSULTAS Y NO UNA, porque el orden que sirve aquí no es el
        // cronológico: lo que ya pasó y falta por cerrar va ANTES de lo que
        // viene, aunque sea más viejo. Expresarlo en un solo ORDER BY exigiría
        // un CASE sobre las subconsultas de asistencia que ya vive en listQb.
        $pending = $shifts->listQb('pending', $category, null, $now)
            ->setMaxResults(self::CATEGORY_TASKS)->getQuery()->getResult();
        $upcoming = $shifts->listQb('upcoming', $category, null, $now)
            ->setMaxResults(self::CATEGORY_TASKS)->getQuery()->getResult();

        // El histórico pasa por `feedQb`, que es lo que arregla el bloque vacío.
        // Antes había un `historyForCategory` que sólo traía lo que le pasó al
        // área EN SÍ —se creó, se renombró, cambió la coordinación—, y en un
        // área que nadie ha tocado eso son cero filas. `feedQb` incluye además
        // lo que pasa DENTRO: tareas publicadas, gente apuntándose, avisos
        // enviados. Eso es la vida del área, y es lo que se venía a ver.
        //
        // El método viejo se retiró en vez de dejarlo sin usar: era el único
        // sitio que lo llamaba.
        $history = $eventLog->feedQb([$category])
            ->setMaxResults(self::CATEGORY_EVENTS)->getQuery()->getResult();

        // Sobre las tareas ya cargadas y no con una consulta aparte: son como
        // mucho veinte objetos que ya están en memoria, y `getRemainingSlots()`
        // vive en la entidad porque una tarea sin tope no tiene plazas que
        // contar y eso no se sabe decir en SQL sin duplicar la regla.
        $missing = 0;
        foreach ($upcoming as $shift) {
            $missing += $shift->getRemainingSlots() ?? 0;
        }

        return $this->render('Volunteering/category_show.html.twig', [
            'category' => $category,
            'pending' => $pending,
            'upcoming' => $upcoming,
            'counts' => $shifts->counts($now, [$category]),
            'missing_slots' => $missing,
            'history' => $history,
            'actor_names' => $eventLog->actorNames($history),
            'now' => $now,
            'task_limit' => self::CATEGORY_TASKS,
        ]);
    }

    /**
     * Editar un área: sólo lo que es texto y quién la coordina.
     *
     * El histórico ya NO se pinta aquí. Un formulario es para cambiar cosas, y
     * un registro de lo que pasó debajo de los botones de guardar no se lee ni
     * se puede usar para nada: su sitio es la ficha
     * ({@see self::showCategory()}), donde está junto a las tareas y la gente
     * que le dan sentido.
     */
    #[Route('/categorias/{id}/editar', name: 'volunteering_category_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_GESTION_VOLUNTARIADO_EDIT')]
    public function editCategory(
        Request $request,
        VolunteerCategory $category,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
    ): Response {
        // Quién coordinaba ANTES: cambiar la coordinación de un área es el
        // cambio que más conviene tener registrado, y después del submit ya no
        // se puede saber quién estaba.
        $before = $this->coordinatorNames($category);

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
            $this->addFlash('success', 'Área actualizada.');

            // A la ficha y no al catálogo: se acaba de guardar un cambio sobre
            // ESTA área y lo siguiente que se quiere es verla, no volver a una
            // tabla donde hay que buscarla otra vez.
            return $this->redirectToRoute('volunteering_category_show', ['id' => $category->getId()]);
        }

        return $this->render('Volunteering/category_form.html.twig', [
            'category' => $category,
            'form' => $form->createView(),
            'is_new' => false,
        ]);
    }

    /**
     * Retirar o volver a ofrecer un área, desde el propio listado.
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
        // A la pantalla desde la que se pulsó, que es la que se estaba mirando.
        // Se decide antes del token para que también el error vuelva bien.
        $back = 'show' === $request->request->get('back')
            ? $this->redirectToRoute('volunteering_category_show', ['id' => $category->getId()])
            : $this->redirectToRoute('volunteering_categories');

        if (!$this->isCsrfTokenValid('volunteering_category_toggle', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $back;
        }

        $category->setActive(!$category->isActive());

        $events->forCategory($category, VolunteerEvent::TYPE_CATEGORY_UPDATED, [
            'name' => $category->getName(),
            'active' => $category->isActive(),
        ]);

        $em->flush();

        $this->addFlash('success', $category->isActive()
            ? sprintf('"%s" vuelve a ofrecerse.', $category->getName())
            : sprintf('"%s" queda retirada. El histórico se conserva.', $category->getName()));

        return $back;
    }

    /**
     * Cuenta lo que ha hecho la sincronización de turnos, en una frase.
     *
     * @param array{created: list<VolunteerShift>, removed: int, kept: list<VolunteerShift>} $sync     lo que pasó
     * @param int                                                                           $notified a cuánta gente se avisó
     *
     * @return string el mensaje para la barra de avisos
     */
    private function describeSync(array $sync, int $notified): string
    {
        $parts = ['Tarea actualizada.'];

        if ([] !== $sync['created']) {
            $parts[] = sprintf('Abiertos %d turno(s).', \count($sync['created']));
        }

        if ($sync['removed'] > 0) {
            $parts[] = sprintf('Retirados %d turno(s) que ya no tocaban.', $sync['removed']);
        }

        if ([] !== $sync['kept']) {
            $parts[] = sprintf(
                'Hay %d turno(s) con gente apuntada que ya no encajan con la repetición: no se han tocado, anúlalos a mano si procede.',
                \count($sync['kept'])
            );
        }

        if ($notified > 0) {
            $parts[] = sprintf('Se ha avisado a %d persona(s).', $notified);
        }

        return implode(' ', $parts);
    }

    /**
     * Le pega al mensaje a cuánta gente se avisó, si se avisó a alguien.
     *
     * @param string $message  el mensaje
     * @param int    $notified a cuánta gente se avisó
     *
     * @return string el mensaje, con el aviso si procede
     */
    private function withNotified(string $message, int $notified): string
    {
        return $notified > 0
            ? sprintf('%s Se ha avisado a %d persona(s) que se habían apuntado.', $message, $notified)
            : $message;
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
