<?php

namespace App\Controller;

use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\VolunteerCoordinationLog;
use App\Entity\VolunteerEvent;
use App\Entity\VolunteerShift;
use App\Entity\VolunteerSignup;
use App\Repository\VolunteerCategoryRepository;
use App\Repository\VolunteerCoordinationLogRepository;
use App\Repository\VolunteerShiftRepository;
use App\Repository\VolunteerSignupRepository;
use App\Service\Volunteering\CreditedTime;
use App\Service\Volunteering\VolunteerContributions;
use App\Service\Volunteering\VolunteerEventRecorder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * El voluntariado visto por un socix: qué hace falta, a qué se ha apuntado y
 * cuánto lleva hecho.
 *
 * SE APUNTA A UN TURNO, no a una tarea. "Sacar al perro" no es un plan al que
 * apuntarse; a lo que uno dice sí es al domingo por la mañana. Y quien quiere
 * comprometerse con varios de golpe —"todos los martes de este mes"— lo hace
 * desde el calendario marcando varios ({@see self::signUpMany()}), que es la
 * forma corta de lo que si no serían ocho clics.
 *
 * EL ORDEN DE LA PANTALLA ES EL DISEÑO. Primero lo que hace falta, con fecha y
 * plazas libres; el contador propio va después y en pequeño. Al revés —"llevas
 * 0 horas" arriba y grande— es un reproche sin salida, y un reproche que no se
 * puede resolver de un clic sólo consigue que la gente deje de entrar en la web.
 * Y si dejan de entrar, se pierde también el canal por el que gestionan su
 * cesta, que es el activo de verdad.
 *
 * LO QUE OCURRE EN SU PUNTO DE RECOGIDA VA PRIMERO. Quien recoge en La Cabrera
 * ya va a estar allí el viernes: pedirle media hora es la fricción más baja que
 * existe, y enterrar esa tarea bajo otras tres que le pillan a cuarenta
 * kilómetros es perder la única a la que iba a decir que sí.
 *
 * Todo bajo el toggle del módulo: apagado, ni el menú ni estas rutas existen.
 */
#[Route('/panel/voluntariado')]
#[IsGranted('ROLE_PARTNER')]
#[IsGranted('FEATURE_VOLUNTEERING')]
class PanelVolunteeringController extends AbstractController
{
    /** Cuántos turnos se enseñan de golpe. Una lista larga se lee como un muro y no se lee. */
    private const MAX_SHIFTS = 8;

    /** Cuántos días vista enseña el calendario del socix. */
    private const CALENDAR_DAYS = 56;

    /**
     * Lo que hace falta, lo que tengo comprometido y lo que llevo hecho.
     */
    #[Route('', name: 'panel_volunteering', methods: ['GET'])]
    public function index(
        VolunteerShiftRepository $shifts,
        VolunteerSignupRepository $signups,
        VolunteerCategoryRepository $categories,
        VolunteerCoordinationLogRepository $coordinationLog,
        VolunteerContributions $contributions,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        $partner = $this->getUser()->getPartner();
        $now = new \DateTime();
        [$from, $to] = $contributions->period();

        $mine = $contributions->forPartner($partner);
        $node = $this->nodeOf($partner);
        $mySignups = $signups->findUpcomingFor($partner, $now);
        $myShiftIds = array_map(
            static fn (VolunteerSignup $signup): ?int => $signup->getShift()?->getId(),
            $mySignups
        );

        return $this->render('Panel/volunteering.html.twig', [
            'partner' => $partner,
            'shifts' => $shifts->findStillNeededFor($now, $node, $myShiftIds, self::MAX_SHIFTS),
            // El id y no el nodo: la plantilla sólo necesita comparar, y pasarle
            // la entidad invita a navegar relaciones desde Twig.
            'my_node_id' => $node?->getId(),
            'my_signups' => $mySignups,
            // Lo que ya pasó y aún no ha dicho si hizo. Va arriba del todo en la
            // pantalla: es una pregunta concreta, con respuesta de un clic, y
            // hasta que no la conteste esas horas no las tiene nadie.
            'pending_confirmation' => $signups->findPendingConfirmationFor($partner, $now),
            // Qué hizo, no sólo cuánto: "6 h" no dice nada, "6 h: dos repartos y
            // una mañana de plantación" sí.
            'my_done' => $signups->findDoneFor($partner, $from, $to),
            'my_shift_ids' => $myShiftIds,
            'my_minutes' => $mine->minutes,
            // La mediana de quienes participan (no la media) y a quién se le
            // enseña. Las dos reglas viven en VolunteerContribution, que es
            // también quien las explica: aquí sólo se consultan, para que la home
            // y esta pantalla no puedan divergir.
            'median_minutes' => $mine->medianMinutes,
            'show_median' => $mine->showMedian(),
            'categories' => $categories->findActive(),
            // Ids y no entidades: comparar objetos Doctrine con el operador `in`
            // de Twig depende de la identidad de instancia y falla en cuanto una
            // de las dos listas viene de otra consulta.
            'my_category_ids' => array_map(
                static fn ($category) => $category->getId(),
                $partner->getVolunteerCategories()->toArray()
            ),
            'has_preferences' => !$partner->hasNoVolunteerPreferences(),
            // Las áreas que coordina esta persona, si coordina alguna. Sólo a
            // quien coordina se le ofrece apuntar horas de coordinación: al
            // resto no le dice nada y sería una caja más en una pantalla que ya
            // tiene bastantes.
            'coordinated' => $this->getUser()->getCoordinatedVolunteerCategories()->toArray(),
            // Lo que lleva apuntado este año, para que no lo apunte dos veces.
            'coordination_log' => $coordinationLog->findFor($partner, $from, $to),
        ]);
    }

    /**
     * El calendario: todo lo que hay por delante, por semanas, y con casillas
     * para apuntarse a varios de una vez.
     *
     * ES LA PANTALLA QUE FALTABA. Con la lista corta de la portada se puede decir
     * sí a lo de esta semana, pero no "los viernes de agosto": para eso hay que
     * ver el calendario entero y poder marcar. Y una tarea continua —el reparto,
     * el invernadero— sólo se entiende viéndola repetida.
     */
    #[Route('/calendario', name: 'panel_volunteering_calendar', methods: ['GET'])]
    public function calendar(
        VolunteerShiftRepository $shifts,
        VolunteerSignupRepository $signups,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        $partner = $this->getUser()->getPartner();
        $now = new \DateTimeImmutable();
        $until = $now->modify(sprintf('+%d days', self::CALENDAR_DAYS))->setTime(23, 59, 59);

        $upcoming = $shifts->findBetween($now, $until);

        // Agrupados por día, que es como se lee un calendario. En PHP y no en la
        // plantilla porque Twig no sabe agrupar sin inventarse un bucle con
        // variables de estado.
        $byDay = [];
        foreach ($upcoming as $shift) {
            $byDay[$shift->getStartsAt()->format('Y-m-d')][] = $shift;
        }

        return $this->render('Panel/volunteering_calendar.html.twig', [
            'by_day' => $byDay,
            'my_node_id' => $this->nodeOf($partner)?->getId(),
            // Mis inscripciones de todos esos turnos, en UNA consulta: preguntar
            // turno a turno serían cincuenta consultas para pintar una pantalla.
            'my_signups' => $signups->findForPartnerAndShifts($partner, $upcoming),
        ]);
    }

    /**
     * Apuntarse a un turno, con los acompañantes que se traigan.
     */
    #[Route('/{id}/apuntarme', name: 'panel_volunteering_signup', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function signUp(
        Request $request,
        VolunteerShift $shift,
        VolunteerSignupRepository $signups,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('panel_volunteering', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('panel_volunteering');
        }

        $partner = $this->getUser()->getPartner();

        if (!$shift->isOpen()) {
            $this->addFlash('warning', 'Ese turno ya no admite gente: o está cubierto o ya ha pasado.');

            return $this->redirectToRoute('panel_volunteering');
        }

        $companions = $shift->getOffer()?->isCompanionsAllowed()
            ? max(0, (int) $request->request->get('companions', 0))
            : 0;

        // Reapuntarse después de haberse dado de baja reutiliza la fila: la
        // unicidad (shift, partner) impide que haya dos, y crear una segunda
        // acabaría en un 500 en vez de en un "vale".
        $existing = $signups->findOneFor($shift, $partner);
        if (null !== $existing) {
            $existing->reopen()->setCompanions($companions);
            $this->recordSignup($events, $shift, $partner, ['companions' => $companions, 'again' => true]);
            $em->flush();
            $this->addFlash('success', 'Apuntadx otra vez. Gracias.');

            return $this->redirectToRoute('panel_volunteering');
        }

        try {
            $em->persist(
                (new VolunteerSignup())
                    ->setShift($shift)
                    ->setPartner($partner)
                    ->setCompanions($companions)
                    ->setNotes(trim((string) $request->request->get('notes')) ?: null)
            );
            $this->recordSignup($events, $shift, $partner, ['companions' => $companions]);
            $em->flush();
            $this->addFlash('success', 'Apuntadx. Gracias por echar una mano.');
        } catch (UniqueConstraintViolationException) {
            // Doble clic, o el botón de atrás del navegador. Ya está apuntadx,
            // que es lo que quería: no es un error que deba ver.
            $this->addFlash('success', 'Ya estabas apuntadx a ese turno.');
        }

        return $this->redirectToRoute('panel_volunteering');
    }

    /**
     * Apuntarse a varios turnos de golpe: "los viernes de agosto", "todos los
     * martes".
     *
     * UNA FILA POR TURNO, no una suscripción a la serie. Es más filas, y es lo
     * correcto: pasar lista, contar plazas y computar horas son cosas de un día
     * concreto, y con una suscripción habría que resolver la lista cada vez que
     * alguien pregunta quién viene. Además darse de baja de UN viernes concreto
     * —lo más normal del mundo— con una suscripción sería una excepción que
     * habría que modelar aparte.
     *
     * Los turnos que no admiten gente se saltan en silencio y se cuentan: en una
     * tanda de ocho es normal que uno esté lleno, y devolver un error por eso
     * obligaría a repetir la selección entera.
     */
    #[Route('/apuntarme-a-varios', name: 'panel_volunteering_signup_many', methods: ['POST'])]
    public function signUpMany(
        Request $request,
        VolunteerShiftRepository $shifts,
        VolunteerSignupRepository $signups,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('panel_volunteering', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('panel_volunteering_calendar');
        }

        $partner = $this->getUser()->getPartner();
        $ids = array_map('intval', (array) $request->request->all('shifts'));

        if ([] === $ids) {
            $this->addFlash('warning', 'No has marcado ningún día.');

            return $this->redirectToRoute('panel_volunteering_calendar');
        }

        $chosen = $shifts->findBy(['id' => $ids]);
        $done = 0;
        $skipped = 0;

        foreach ($chosen as $shift) {
            /** @var VolunteerShift $shift */
            if (!$shift->isOpen()) {
                ++$skipped;

                continue;
            }

            $existing = $signups->findOneFor($shift, $partner);

            if (null !== $existing) {
                if (!$existing->isCancelled()) {
                    // Ya iba: no es un fallo ni hace falta contarlo como salto.
                    continue;
                }

                $existing->reopen();
            } else {
                $em->persist(
                    (new VolunteerSignup())
                        ->setShift($shift)
                        ->setPartner($partner)
                );
            }

            $this->recordSignup($events, $shift, $partner, ['many' => true]);
            ++$done;
        }

        $em->flush();

        $this->addFlash($done > 0 ? 'success' : 'warning', match (true) {
            0 === $done => 'No he podido apuntarte a ninguno: o estaban cubiertos o ya habían pasado.',
            $skipped > 0 => sprintf('Apuntadx a %d día(s). %d se han quedado fuera porque ya estaban cubiertos.', $done, $skipped),
            default => sprintf('Apuntadx a %d día(s). Gracias por echar una mano.', $done),
        });

        return $this->redirectToRoute('panel_volunteering_calendar');
    }

    /**
     * Darse de baja de un turno. No borra la inscripción: la marca, para que
     * quien coordina sepa que hay un hueco que volver a cubrir.
     */
    #[Route('/{id}/darme-de-baja', name: 'panel_volunteering_withdraw', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function withdraw(
        Request $request,
        VolunteerShift $shift,
        VolunteerSignupRepository $signups,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('panel_volunteering', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('panel_volunteering');
        }

        $signup = $signups->findOneFor($shift, $this->getUser()->getPartner());

        if (null !== $signup && !$signup->isCancelled()) {
            $signup->cancel();

            $offer = $shift->getOffer();
            if (null !== $offer) {
                $events->forOffer($offer, VolunteerEvent::TYPE_WITHDRAW, null, $signup->getPartner());
            }

            $em->flush();
            $this->addFlash('success', 'Te hemos quitado de ese turno. Gracias por avisar.');
        }

        return $this->redirectToRoute('panel_volunteering');
    }

    /**
     * "Ya la he hecho" / "al final no fui": quien se apuntó confirma por su
     * cuenta lo que pasó, y ahí es cuando se le computan las horas.
     *
     * Que lo diga quien fue, y no gestión al cerrar el turno, es lo que quita el
     * punto único de fallo. Si el contador dependiera de que administración
     * cierre cada turno a mano, se olvidarían —y se van a olvidar— y el contador
     * se quedaría a cero para todo el mundo sin que nadie supiera por qué.
     *
     * ES AUTODECLARADO, y con eso basta hoy: el contador es privado y no da nada
     * a cambio, así que no hay ningún incentivo para inflarlo. El día que las
     * horas cuenten para algo (una cuota, un descuento), habrá que revisar esta
     * decisión — y por eso queda registrado quién lo confirmó
     * ({@see VolunteerSignup::$attendanceSource}): para poder revisarlo entonces
     * sin tener que rehacer el histórico.
     *
     * Gestión puede corregirlo después desde la pantalla del turno.
     */
    #[Route('/{id}/confirmar', name: 'panel_volunteering_confirm', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirm(
        Request $request,
        VolunteerShift $shift,
        VolunteerSignupRepository $signups,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('panel_volunteering', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('panel_volunteering');
        }

        $signup = $signups->findOneFor($shift, $this->getUser()->getPartner());

        if (null === $signup || $signup->isCancelled()) {
            $this->addFlash('warning', 'No constas apuntadx a ese turno.');

            return $this->redirectToRoute('panel_volunteering');
        }

        // Confirmar antes de que el turno ocurra no significa nada, y dejaría
        // computadas unas horas que todavía no ha hecho nadie.
        if ($shift->getStartsAt() > new \DateTime()) {
            $this->addFlash('warning', 'Ese turno todavía no ha llegado.');

            return $this->redirectToRoute('panel_volunteering');
        }

        $offer = $shift->getOffer();

        if ($request->request->getBoolean('attended')) {
            $signup->confirmAttendance(VolunteerSignup::SOURCE_SELF);
            if (null !== $offer) {
                $events->forOffer($offer, VolunteerEvent::TYPE_ATTENDED, ['minutes' => $signup->getCreditedMinutes(), 'role' => $signup->getRole()], $signup->getPartner());
            }
            $em->flush();
            $this->addFlash('success', 'Anotado. Gracias por echar una mano.');
        } else {
            $signup->markAbsent(VolunteerSignup::SOURCE_SELF);
            if (null !== $offer) {
                $events->forOffer($offer, VolunteerEvent::TYPE_ABSENT, null, $signup->getPartner());
            }
            $em->flush();
            $this->addFlash('success', 'Anotado, gracias por decirlo.');
        }

        return $this->redirectToRoute('panel_volunteering');
    }

    /**
     * Apuntar horas de coordinar un área.
     *
     * COORDINAR NO ES UNA TAREA y por eso no se cierra como tal: no ocurre un
     * día concreto ni tiene plazas ni gente que se apunte. Es buscar gente,
     * cuadrarla, avisar y estar pendiente, repartido por la semana. Lo único que
     * se puede hacer es que quien lo hace diga cuánto le ha llevado.
     *
     * LO APUNTA ELLA MISMA, como quien va a una tarea dice si fue. Nadie más
     * sabe ese número, y que lo pusiera gestión sería inventárselo.
     *
     * Sólo sobre áreas que coordina de verdad: sin esa comprobación, cualquiera
     * con cuenta podría apuntarse horas de cualquier área cambiando un id en el
     * formulario.
     */
    #[Route('/coordinacion', name: 'panel_volunteering_log_coordination', methods: ['POST'])]
    public function logCoordination(
        Request $request,
        VolunteerCategoryRepository $categories,
        EntityManagerInterface $em,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('panel_volunteering', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('panel_volunteering');
        }

        $user = $this->getUser();
        $category = $categories->find($request->request->getInt('category'));

        if (null === $category || !$category->isCoordinatedBy($user)) {
            $this->addFlash('error', 'No coordinas esa área.');

            return $this->redirectToRoute('panel_volunteering');
        }

        $minutes = CreditedTime::minutesFromHours($request->request->get('hours'));

        if (null === $minutes || $minutes <= 0) {
            $this->addFlash('error', 'Pon cuántas horas le has dedicado.');

            return $this->redirectToRoute('panel_volunteering');
        }

        // La fecha se puede echar atrás —"esto fue de la semana pasada"— pero no
        // adelante: apuntar horas de un trabajo que aún no se ha hecho es
        // apuntarse horas que no existen.
        $when = \DateTime::createFromFormat('Y-m-d', (string) $request->request->get('happened_on'))
            ?: new \DateTime();

        if ($when > new \DateTime()) {
            $when = new \DateTime();
        }

        $entry = (new VolunteerCoordinationLog())
            ->setPartner($user->getPartner())
            ->setCategory($category)
            ->setHappenedOn($when)
            ->setMinutes($minutes)
            ->setNotes(trim((string) $request->request->get('notes')) ?: null);

        $em->persist($entry);
        $em->flush();

        $this->addFlash('success', 'Anotado. Gracias por llevar esto adelante.');

        return $this->redirectToRoute('panel_volunteering');
    }

    /**
     * Elegir de qué avisar. Marcar categorías significa "avísame de esto"; no
     * marcar ninguna, "avísame de lo que sea sencillo". El texto de la pantalla
     * tiene que decirlo con esas palabras o el escalado de avisos miente.
     */
    #[Route('/preferencias', name: 'panel_volunteering_preferences', methods: ['POST'])]
    public function preferences(
        Request $request,
        VolunteerCategoryRepository $categories,
        EntityManagerInterface $em,
        VolunteerEventRecorder $events,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('panel_volunteering', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $this->redirectToRoute('panel_volunteering');
        }

        $partner = $this->getUser()->getPartner();
        $chosen = array_map('intval', (array) $request->request->all('categories'));

        // El "no me avises de esto" es una salida aparte y no la ausencia de
        // categorías: no marcar ninguna significa "avísame de lo que sea
        // sencillo", que es lo contrario. Sin esta casilla, la única forma de
        // silenciar el voluntariado sería apagar los avisos del navegador
        // enteros, y con ellos los que sí interesan.
        $partner->setVolunteeringOptOut((bool) $request->request->get('opt_out'));

        // Se reconstruye la lista entera en vez de aplicar diferencias: son
        // media docena de casillas y así desmarcar todo funciona igual de bien
        // que marcar, sin un caso especial para la lista vacía.
        foreach ($partner->getVolunteerCategories()->toArray() as $current) {
            $partner->removeVolunteerCategory($current);
        }

        foreach ($categories->findActive() as $category) {
            if (\in_array($category->getId(), $chosen, true)) {
                $partner->addVolunteerCategory($category);
            }
        }

        $events->forPartner($partner, VolunteerEvent::TYPE_PREFERENCES_CHANGED, ['areas' => $chosen, 'opt_out' => $partner->isVolunteeringOptOut()]);
        $em->flush();
        $this->addFlash(
            'success',
            $partner->isVolunteeringOptOut()
                ? 'Guardado. No te avisaremos de voluntariado.'
                : 'Guardado. Te avisaremos de lo que has elegido.'
        );

        return $this->redirectToRoute('panel_volunteering');
    }

    /**
     * Deja el rastro de que alguien se apuntó a un turno.
     *
     * El evento cuelga de la TAREA porque es donde vive el historial y donde se
     * filtra por área; el día concreto va en el payload. Un turno sin tarea no
     * deja rastro en vez de reventar: el rastro es importante, pero no tanto
     * como que apuntarse funcione.
     *
     * @param VolunteerEventRecorder $events  quien escribe el rastro
     * @param VolunteerShift         $shift   el turno
     * @param Partner                $partner quién se apunta
     * @param array<string, mixed>   $payload lo que varía
     */
    private function recordSignup(
        VolunteerEventRecorder $events,
        VolunteerShift $shift,
        Partner $partner,
        array $payload,
    ): void {
        $offer = $shift->getOffer();

        if (null === $offer) {
            return;
        }

        $events->forOffer(
            $offer,
            VolunteerEvent::TYPE_SIGNUP,
            [...$payload, 'when' => $shift->getStartsAt()?->format('d/m/Y H:i')],
            $partner
        );
    }

    /**
     * El punto de recogida del socix, si lo tiene. De ahí sale el orden de la
     * lista.
     *
     * @param Partner $partner el socix
     */
    private function nodeOf(Partner $partner): ?Node
    {
        return $partner->getWeeklyBasketGroup()?->getNode();
    }

    /**
     * Mismo guardarraíl que el resto del panel: una cuenta sin Partner vinculado
     * no puede usarlo, y redirige en vez de explotar.
     */
    private function ensureReady(): ?RedirectResponse
    {
        $user = $this->getUser();

        if ($user && method_exists($user, 'getPartner') && null !== $user->getPartner()) {
            return null;
        }

        $this->addFlash('error', 'Tu usuaria no está vinculada a un socix; pide a admin que te vincule para usar el panel.');

        return $this->redirectToRoute('homepage');
    }
}
