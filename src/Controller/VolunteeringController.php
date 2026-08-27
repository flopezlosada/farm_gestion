<?php

namespace App\Controller;

use App\Entity\Partner;
use App\Entity\VolunteerCall;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerSignup;
use App\Form\VolunteerCategoryType;
use App\Form\VolunteerOfferType;
use App\Repository\PartnerRepository;
use App\Repository\VolunteerCallRepository;
use App\Repository\VolunteerCategoryRepository;
use App\Repository\VolunteerOfferRepository;
use App\Repository\VolunteerSignupRepository;
use App\Security\VolunteerOfferVoter;
use App\Service\Volunteering\VolunteerAudienceResolver;
use App\Service\Volunteering\VolunteerCallNotifier;
use App\Service\Volunteering\VolunteerOfferChangeNotifier;
use App\Service\Volunteering\VolunteerOfferSnapshot;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
 * La LECTURA sí alcanza a todas las áreas: un coordinador de huerta ve las
 * tareas del reparto y quién se apuntó. Son nombres de socixs dentro de la
 * asociación, no datos de contacto, y separar también la lectura obligaría a
 * filtrar cada listado por área a cambio de que nadie pudiera ver cómo va el
 * conjunto.
 */
#[Route('/gestion/voluntariado')]
#[IsGranted('ROLE_GESTION_VOLUNTARIADO')]
#[IsGranted('FEATURE_VOLUNTEERING')]
class VolunteeringController extends AbstractController
{
    /** Cuántos días atrás se miran las tareas ya hechas en el listado. */
    private const RECENT_DAYS = 60;

    /**
     * Cadencias que se ofrecen al repetir una tarea, en días. Las tres que usa
     * la asociación: el reparto es semanal, hay grupos quincenales y algunas
     * cosas son mensuales.
     */
    private const REPEAT_CADENCES = ['weekly' => 7, 'biweekly' => 14, 'monthly' => 28];

    /** Tope de copias por repetición. Un año de reparto semanal cabe de sobra. */
    private const REPEAT_MAX = 52;

    /**
     * Las tareas: lo que falta por cerrar, lo que viene y lo que ya se hizo.
     */
    #[Route('', name: 'volunteering_index', methods: ['GET'])]
    public function index(
        Request $request,
        VolunteerOfferRepository $offers,
        VolunteerCategoryRepository $categories,
        PaginatorInterface $paginator,
    ): Response {
        $now = new \DateTime();
        $scope = $request->query->getAlpha('ver') ?: 'upcoming';
        $categoryId = $request->query->getInt('tipo') ?: null;
        $category = null !== $categoryId ? $categories->find($categoryId) : null;
        $query = trim((string) $request->query->get('q'));

        $pagination = $paginator->paginate(
            $offers->listQb($scope, $category, $query, $now)->getQuery(),
            $request->query->getInt('page', 1),
            25
        );

        return $this->render('Volunteering/index.html.twig', [
            'pagination' => $pagination,
            'counts' => $offers->counts($now),
            'categories' => $categories->findActive(),
            'scope' => $scope,
            'current' => $category,
            'q' => $query,
            'now' => $now,
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
    ): Response {
        $filter = $request->query->getInt('tipo') ?: null;
        $category = null !== $filter ? $categories->find($filter) : null;
        $scope = $request->query->getAlpha('ver') ?: 'declared';
        $query = trim((string) $request->query->get('q'));

        $year = (int) date('Y');
        $from = new \DateTime(sprintf('%d-01-01 00:00:00', $year));
        $to = new \DateTime(sprintf('%d-12-31 23:59:59', $year));

        $pagination = $paginator->paginate(
            $partners->volunteeringPoolQb($scope, $category, $query)->getQuery(),
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
            'categories' => $categories->findActive(),
            'current' => $category,
            'scope' => $scope,
            'counts' => $partners->volunteeringPoolCounts(),
            // Veces que ha participado cada quien, en un solo mapa: preguntarlo
            // por socix sería el N+1 más caro del módulo.
            'participation' => $signups->participationByPartner($from, $to),
            'year' => $year,
            'q' => $query,
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
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $offer = (new VolunteerOffer())->setCreatedBy($this->getUser());
        $form = $this->createForm(VolunteerOfferType::class, $offer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Publicar una tarea de un área que no coordinas es lo mismo que
            // editar la de otra persona. Se comprueba aquí porque hasta ahora la
            // oferta no tenía categorías que mirar.
            $this->denyAccessUnlessGranted(VolunteerOfferVoter::EDIT, $offer);

            $em->persist($offer);
            $em->flush();
            $this->addFlash('success', 'Tarea creada.');

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        return $this->render('Volunteering/form.html.twig', [
            'offer' => $offer,
            'form' => $form->createView(),
            'is_new' => true,
        ]);
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
    ): Response {
        $sent = $calls->sentScopes($offer);

        return $this->render('Volunteering/show.html.twig', [
            'offer' => $offer,
            'sent_scopes' => $sent,
            // Para anotar a mano a quien organizó la tarea o vino sin apuntarse.
            'all_partners' => $partners->findBy(
                ['status' => Partner::STATUS_ACTIVO],
                ['name' => 'ASC', 'surname' => 'ASC']
            ),
            // Cuánta gente recibiría el aviso general, para que quien pulsa el
            // botón vea el número ANTES de molestar a media asociación.
            'everyone_count' => $audience->count($offer, VolunteerCall::SCOPE_EVERYONE),
            'everyone_sent' => \in_array(VolunteerCall::SCOPE_EVERYONE, $sent, true),
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
    ): Response {
        // La foto se toma ANTES de handleRequest: después, la entidad ya lleva
        // los valores nuevos y el original se ha perdido.
        $before = VolunteerOfferSnapshot::of($offer);

        $form = $this->createForm(VolunteerOfferType::class, $offer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
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
     * Repetir esta tarea en fechas siguientes.
     *
     * Es lo que hace el módulo usable para lo que más se repite: el reparto es
     * SEMANAL, y crear "descargar cestas en La Cabrera" cincuenta y dos veces a
     * mano no lo va a hacer nadie.
     *
     * Cadencia y número de veces, no una regla de recurrencia. Karrot modela
     * series con RRULE de iCal, potente y caro; OpenOlitor simplemente duplica a
     * una lista de fechas. Esto es lo segundo: las copias nacen sueltas, así que
     * cambiar o anular una no toca a las demás — que es justo lo que hace falta
     * cuando cae un festivo en medio.
     */
    #[Route('/{id}/repetir', name: 'volunteering_repeat', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(VolunteerOfferVoter::EDIT, subject: 'offer')]
    public function repeat(Request $request, VolunteerOffer $offer, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('volunteering_repeat', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        if (null === $offer->getStartsAt()) {
            $this->addFlash('error', 'Esta tarea no tiene fecha, así que no se puede repetir.');

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        $everyDays = self::REPEAT_CADENCES[$request->request->get('cadence')] ?? null;
        $times = (int) $request->request->get('times');

        if (null === $everyDays || $times < 1) {
            $this->addFlash('error', 'Elige cada cuánto se repite y cuántas veces.');

            return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
        }

        // Tope duro: un error de dedo aquí crea tareas a puñados, y borrarlas
        // una a una es un castigo desproporcionado para un cero de más.
        $times = min($times, self::REPEAT_MAX);

        $start = \DateTimeImmutable::createFromInterface($offer->getStartsAt());
        for ($i = 1; $i <= $times; ++$i) {
            $em->persist($offer->copyForDate($start->modify(sprintf('+%d days', $everyDays * $i))));
        }

        $em->flush();

        $this->addFlash(
            'success',
            sprintf('Creadas %d copias, en borrador. Revísalas —ojo a los festivos— y publícalas.', $times)
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
    public function close(Request $request, VolunteerOffer $offer, EntityManagerInterface $em): Response
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
        }

        $em->flush();
        $this->addFlash('success', sprintf('Tarea cerrada: %d persona(s) con horas computadas.', $counted));

        return $this->redirectToRoute('volunteering_show', ['id' => $offer->getId()]);
    }

    /**
     * El catálogo de tipos de trabajo.
     */
    #[Route('/categorias/listado', name: 'volunteering_categories', methods: ['GET'])]
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
    public function newCategory(Request $request, EntityManagerInterface $em): Response
    {
        $category = new VolunteerCategory();
        $form = $this->createForm(VolunteerCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($category);
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
    public function editCategory(Request $request, VolunteerCategory $category, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(VolunteerCategoryType::class, $category);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Categoría actualizada.');

            return $this->redirectToRoute('volunteering_categories');
        }

        return $this->render('Volunteering/category_form.html.twig', [
            'category' => $category,
            'form' => $form->createView(),
            'is_new' => false,
        ]);
    }
}
