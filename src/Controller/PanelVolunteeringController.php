<?php

namespace App\Controller;

use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerCoordinationLog;
use App\Entity\VolunteerEvent;
use App\Entity\VolunteerShift;
use App\Entity\VolunteerSignup;
use App\Repository\VolunteerCategoryRepository;
use App\Repository\VolunteerCoordinationLogRepository;
use App\Repository\VolunteerShiftRepository;
use App\Repository\VolunteerSignupRepository;
use App\Service\Calendar\MonthGrid;
use App\Service\Volunteering\CreditedTime;
use App\Service\Volunteering\ShiftCalendar;
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
 * CUATRO PESTAÑAS Y NO UNA PANTALLA. Esto era una sola página que hacía cinco
 * cosas —confirmar lo hecho, ver lo que falta, ver lo tuyo, elegir tus áreas y
 * mirar tu aportación—, o sea cuatro pantallazos de scroll en un móvil, que en
 * la práctica significa que sólo se ve lo primero. Ahora: la portada (lo que
 * hace falta), el cuadrante, lo mío y mis áreas, cada una con su URL.
 *
 * El CUADRANTE se llama así y no «calendario» a propósito: en el menú del panel
 * «Mi calendario» es el de la cesta, y son dos vistas que nunca se mezclan.
 * Estaba además enterrado en un enlace de texto al pie de un bloque, siendo la
 * única vista donde uno se apunta a varios días de una vez.
 *
 * EL ORDEN DENTRO DE CADA UNA SIGUE SIENDO EL DISEÑO. Primero lo que hace falta,
 * con fecha y plazas libres; el contador propio va al final de «lo mío» y en
 * pequeño. Al revés —"llevas 0 horas" arriba y grande— es un reproche sin
 * salida, y un reproche que no se puede resolver de un clic sólo consigue que la
 * gente deje de entrar en la web. Y si dejan de entrar, se pierde también el
 * canal por el que gestionan su cesta, que es el activo de verdad.
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
    /**
     * Cuántos turnos se enseñan por grupo. Una lista larga se lee como un muro y
     * no se lee.
     *
     * ES POR GRUPO Y NO DEL TOTAL, que era lo de antes. Un tope global de ocho
     * sobre una lista ordenada por fecha dejaba fuera todo lo de dentro de dos
     * semanas en cuanto la semana que viene tuviera ocho turnos: la pantalla
     * decía "lo que hace falta" y enseñaba únicamente los días más próximos.
     */
    private const MAX_SHIFTS = 8;

    /**
     * Cuántas rutinas se asoman. Son dos turnos diarios de una plaza y hay
     * decenas por delante: con enseñar los próximos días basta, y el resto vive
     * en el calendario, que es donde se elige "los martes de octubre".
     */
    private const MAX_ROUTINE = 4;

    /**
     * Cuántos turnos por confirmar se enseñan con sus botones en «Mis turnos».
     *
     * A la vista y no detrás de un resumen, porque es la acción que más cuesta
     * conseguir. Pero con tope: quien lleva medio año sin contestar tendría ahí
     * una pared de tarjetas, y una pared no se contesta, se cierra.
     */
    private const MAX_PENDING = 3;

    /**
     * La portada: lo que hace falta, y nada más.
     *
     * Contesta UNA pregunta —¿dónde hago falta?— desde que lo demás tiene su
     * pestaña. Antes esta acción servía las cinco cosas a la vez.
     */
    #[Route('', name: 'panel_volunteering', methods: ['GET'])]
    public function index(
        VolunteerShiftRepository $shifts,
        VolunteerSignupRepository $signups,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        $partner = $this->getUser()->getPartner();
        $now = new \DateTime();
        $node = $this->nodeOf($partner);
        $mySignups = $signups->findUpcomingFor($partner, $now);
        $myShiftIds = array_map(
            static fn (VolunteerSignup $signup): ?int => $signup->getShift()?->getId(),
            $mySignups
        );

        // Lo que hace falta, en los tres montones en los que se lee: lo de esta
        // semana, lo de más adelante y el día a día. Sin repartir, la pantalla
        // era una lista plana en la que una faena de cuatro plazas del sábado
        // pesaba lo mismo que sacar al perro el martes.
        //
        // El reparto es por FECHA (isImminent) y no por urgencia: en qué montón
        // cae un turno lo decide cuándo es. Con isUrgent(), que además mira las
        // plazas libres, un turno de pasado mañana cuya tarea no tiene número de
        // plazas —el boletín, llamar a las socias— acababa en "próximas
        // semanas", donde no lo iba a ver nadie.
        $stillNeeded = $shifts->findStillNeededFor($now, $node, $myShiftIds);
        $urgent = array_values(array_filter(
            $stillNeeded,
            static fn (VolunteerShift $shift): bool => $shift->isImminent($now)
        ));
        $later = array_values(array_filter(
            $stillNeeded,
            static fn (VolunteerShift $shift): bool => !$shift->isImminent($now)
        ));
        $routine = $shifts->findRoutineStillNeededFor($now, $node, $myShiftIds);

        return $this->render('Panel/volunteering.html.twig', [
            'partner' => $partner,
            // Los tres grupos, ya cortados. El corte es POR GRUPO: con un tope
            // global, una semana cargada tapaba entero lo de dentro de quince
            // días.
            'urgent_shifts' => \array_slice($shifts->onePerOffer($urgent), 0, self::MAX_SHIFTS),
            'later_shifts' => \array_slice($shifts->onePerOffer($later), 0, self::MAX_SHIFTS),
            // El día a día aparte y detrás: dos turnos diarios de una plaza
            // sumaban más que todo el trabajo de campo junto y copaban la
            // pantalla donde la gente se apunta.
            'routine_shifts' => \array_slice($shifts->onePerOffer($routine), 0, self::MAX_ROUTINE),
            'routine_total' => \count($routine),
            // Cuánto queda fuera de cada montón, para no enseñar ocho y callar
            // que hay veinte.
            'urgent_total' => \count($urgent),
            'later_total' => \count($later),
            // El id y no el nodo: la plantilla sólo necesita comparar, y pasarle
            // la entidad invita a navegar relaciones desde Twig.
            'my_node_id' => $node?->getId(),
            // Las áreas que ha marcado. La tarjeta las usa para decir CON
            // PALABRAS que un turno es de otra área —«no es de tus áreas, pero
            // puedes venir»— en vez de bajarle el contraste, que es el lenguaje
            // universal de «deshabilitado» y diría justo lo contrario.
            'my_category_ids' => array_map(
                static fn (VolunteerCategory $category): int => $category->getId(),
                $partner->getVolunteerCategories()->toArray()
            ),
        ] + $this->tabsContext($signups, $partner, $now));
    }

    /**
     * Mis turnos: el próximo, lo que falta por contestar, lo que viene después y
     * cuánto llevo puesto este año.
     *
     * ES UN RESUMEN, NO UN ARCHIVO. La versión anterior desplegaba entera la
     * lista de todo lo hecho en el año, y ésa es la única parte de la pantalla
     * que crece sin techo: en septiembre eran siete líneas y en junio serían
     * treinta. Los pendientes y los turnos futuros son pocos por naturaleza; el
     * histórico no. Así que el histórico se resume aquí —la cifra y cuántas
     * tareas— y se despliega en {@see self::history()}.
     *
     * LO QUE FALTA POR CONTESTAR SE QUEDA A LA VISTA, con sus botones, y no
     * detrás de un «tienes 5 sin confirmar». Es la acción que más cuesta
     * conseguir —hasta que no responden, esas horas no las tiene nadie— y
     * ponerle un clic de más va justo en contra. Se limita, eso sí: con seis
     * pendientes la pantalla volvía a ser un muro.
     */
    #[Route('/mis-turnos', name: 'panel_volunteering_mine', methods: ['GET'])]
    public function mine(
        VolunteerSignupRepository $signups,
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

        // El próximo va aparte del resto: es lo que se viene a mirar, y como
        // tarjeta entera. Los demás bajan a una línea cada uno — para decidir si
        // vas al del sábado no hace falta releer la ficha completa.
        $upcoming = $signups->findUpcomingFor($partner, $now);
        $pending = $signups->findPendingConfirmationFor($partner, $now);
        $answered = $signups->findAnsweredFor($partner, $from, $to);

        return $this->render('Panel/volunteering_mine.html.twig', [
            'partner' => $partner,
            'next_signup' => $upcoming[0] ?? null,
            'later_signups' => \array_slice($upcoming, 1),
            // Lo que ya pasó y aún no ha dicho si hizo. Es una pregunta concreta
            // con respuesta de un clic, y hasta que no la conteste esas horas no
            // las tiene nadie.
            'pending_confirmation' => \array_slice($pending, 0, self::MAX_PENDING),
            'pending_total' => \count($pending),
            // Del histórico, aquí sólo el recuento: el detalle vive en su propia
            // pantalla porque es lo único que crece todo el año.
            'done_count' => \count(array_filter(
                $answered,
                static fn (VolunteerSignup $signup): bool => true === $signup->getAttended()
            )),
            'my_minutes' => $mine->minutes,
            // La mediana de quienes participan (no la media) y a quién se le
            // enseña. Las dos reglas viven en VolunteerContribution, que es
            // también quien las explica: aquí sólo se consultan, para que la home
            // y esta pantalla no puedan divergir.
            'median_minutes' => $mine->medianMinutes,
            'show_median' => $mine->showMedian(),
            // Las áreas que coordina esta persona, si coordina alguna. Sólo a
            // quien coordina se le ofrece apuntar horas de coordinación: al
            // resto no le dice nada y sería una caja más.
            'coordinated' => $this->getUser()->getCoordinatedVolunteerCategories()->toArray(),
            // Lo que lleva apuntado este año, para que no lo apunte dos veces.
            'coordination_log' => $coordinationLog->findFor($partner, $from, $to),
        ] + $this->tabsContext($signups, $partner, $now));
    }

    /**
     * El detalle de lo que llevo hecho este año.
     *
     * PANTALLA PROPIA porque es lo único de «Mis turnos» que crece sin techo: en
     * septiembre son siete líneas y en junio serán treinta, y desplegado entero
     * enterraba lo que sí se viene a mirar —el próximo turno y lo que falta por
     * contestar—. Aquí se entra a propósito, y entonces la lista larga no
     * estorba: es justo lo que se venía a ver.
     *
     * Trae también los «no pude», que no computan pero son respuestas suyas: sin
     * ellos, contestar que no hacía desaparecer el turno sin dejar rastro.
     */
    #[Route('/mis-turnos/historial', name: 'panel_volunteering_history', methods: ['GET'])]
    public function history(
        VolunteerSignupRepository $signups,
        VolunteerCoordinationLogRepository $coordinationLog,
        VolunteerContributions $contributions,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        $partner = $this->getUser()->getPartner();
        [$from, $to] = $contributions->period();
        $mine = $contributions->forPartner($partner);

        return $this->render('Panel/volunteering_history.html.twig', [
            'partner' => $partner,
            'my_done' => $signups->findAnsweredFor($partner, $from, $to),
            'my_minutes' => $mine->minutes,
            'median_minutes' => $mine->medianMinutes,
            'show_median' => $mine->showMedian(),
            // Coordinar no genera inscripciones, así que sus horas sólo se ven
            // aquí: en la lista de turnos no aparecerían nunca.
            'coordination_log' => $coordinationLog->findFor($partner, $from, $to),
        ] + $this->tabsContext($signups, $partner, new \DateTime()));
    }

    /**
     * Mis áreas: de qué quiero que me avisen.
     *
     * Se contesta una vez y luego se mira cero veces, así que su sitio es una
     * pestaña propia y no media pantalla en cada visita por delante de lo único
     * que cambia cada semana.
     */
    #[Route('/mis-areas', name: 'panel_volunteering_areas', methods: ['GET'])]
    public function areas(
        VolunteerCategoryRepository $categories,
        VolunteerSignupRepository $signups,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        $partner = $this->getUser()->getPartner();

        return $this->render('Panel/volunteering_areas.html.twig', [
            'partner' => $partner,
            'categories' => $categories->findActive(),
            // Ids y no entidades: comparar objetos Doctrine con el operador `in`
            // de Twig depende de la identidad de instancia y falla en cuanto una
            // de las dos listas viene de otra consulta.
            'my_category_ids' => array_map(
                static fn ($category) => $category->getId(),
                $partner->getVolunteerCategories()->toArray()
            ),
        ] + $this->tabsContext($signups, $partner, new \DateTime()));
    }

    /**
     * Lo que las cuatro pestañas necesitan sepan cuál se está mirando.
     *
     * Hoy es sólo el contador de lo que falta por confirmar, y va en TODAS las
     * pantallas a propósito: es una pregunta con respuesta de un clic, y hasta
     * que no se conteste esas horas no las tiene nadie. Si el aviso viviera
     * dentro de su propia pestaña, quien no entra ahí no se entera nunca.
     *
     * Está centralizado para que añadir un segundo indicador no obligue a tocar
     * cuatro acciones y olvidarse de una.
     *
     * @return array{pending: int}
     */
    private function tabsContext(
        VolunteerSignupRepository $signups,
        Partner $partner,
        \DateTimeInterface $now,
    ): array {
        return [
            'pending' => \count($signups->findPendingConfirmationFor($partner, $now)),
        ];
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
        Request $request,
        VolunteerShiftRepository $shifts,
        VolunteerSignupRepository $signups,
        VolunteerCategoryRepository $categories,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        $partner = $this->getUser()->getPartner();
        $now = new \DateTimeImmutable();
        $month = MonthGrid::monthFrom($request->query->getString('mes'), $now);
        $weeks = MonthGrid::weeks((int) $month->format('Y'), (int) $month->format('n'));

        $categoryId = $request->query->getInt('tipo') ?: null;
        $category = null !== $categoryId ? $categories->find($categoryId) : null;

        // Las áreas que ha marcado: lo suyo se resalta y el resto se apaga, y
        // con `?solo=mias` el resto ni se enseña. Es su calendario de
        // voluntariado, no el de la asociación: sin esto, quien marcó huerta
        // ve el reparto de tres nodos por encima de lo que le interesa.
        $myCategoryIds = array_map(
            static fn (VolunteerCategory $c): int => $c->getId(),
            $partner->getVolunteerCategories()->toArray()
        );
        $onlyMine = 'mias' === $request->query->getAlpha('solo') && [] !== $myCategoryIds;

        $inMine = static fn (VolunteerShift $s): bool => [] !== array_intersect(
            $myCategoryIds,
            array_map(static fn (VolunteerCategory $c): int => $c->getId(), $s->getOffer()->getCategories()->toArray())
        );

        // El socix no ve lo apagado: ni borradores ni parados (fuera por
        // «sólo publicadas») ni anulados. Un día anulado se explica en la
        // ficha de la tarea, no en su calendario.
        $visible = array_values(array_filter(
            $shifts->findBetween($weeks[0][0], end($weeks)[6]->setTime(23, 59, 59), true, $category),
            static fn (VolunteerShift $s): bool => !$s->isCancelled() && (!$onlyMine || $inMine($s))
        ));
        $days = ShiftCalendar::days($visible, $now);

        return $this->render('Panel/volunteering_calendar.html.twig', [
            'weeks' => $weeks,
            'days' => $days,
            'states' => ShiftCalendar::statesPresent($days),
            'month' => $month,
            'prev' => $month->modify('-1 month'),
            'next' => $month->modify('+1 month'),
            'today' => $now->format('Y-m-d'),
            'categories' => $categories->findActive(),
            'current' => $category,
            'my_category_ids' => $myCategoryIds,
            'my_category_names' => array_map(static fn (VolunteerCategory $c): string => $c->getName(), $partner->getVolunteerCategories()->toArray()),
            'only_mine' => $onlyMine,
            'my_node_id' => $this->nodeOf($partner)?->getId(),
            // Mis inscripciones de todos esos turnos, en UNA consulta: preguntar
            // turno a turno serían cincuenta consultas para pintar una pantalla.
            'my_signups' => $signups->findForPartnerAndShifts($partner, $visible),
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

            return $this->redirectToRoute('panel_volunteering_mine');
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

        // Desde el calendario se vuelve al calendario, al mes del turno.
        if ('calendario' === $request->request->getAlpha('volver')) {
            return $this->redirectToRoute('panel_volunteering_calendar', ['mes' => $shift->getStartsAt()?->format('Y-m')]);
        }

        return $this->redirectToRoute('panel_volunteering_mine');
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

            return $this->redirectToRoute('panel_volunteering_mine');
        }

        $signup = $signups->findOneFor($shift, $this->getUser()->getPartner());

        if (null === $signup || $signup->isCancelled()) {
            $this->addFlash('warning', 'No constas apuntadx a ese turno.');

            return $this->redirectToRoute('panel_volunteering_mine');
        }

        // Confirmar antes de que el turno ocurra no significa nada, y dejaría
        // computadas unas horas que todavía no ha hecho nadie.
        if ($shift->getStartsAt() > new \DateTime()) {
            $this->addFlash('warning', 'Ese turno todavía no ha llegado.');

            return $this->redirectToRoute('panel_volunteering_mine');
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

        return $this->redirectToRoute('panel_volunteering_mine');
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

            return $this->redirectToRoute('panel_volunteering_mine');
        }

        $user = $this->getUser();
        $category = $categories->find($request->request->getInt('category'));

        if (null === $category || !$category->isCoordinatedBy($user)) {
            $this->addFlash('error', 'No coordinas esa área.');

            return $this->redirectToRoute('panel_volunteering_mine');
        }

        $minutes = CreditedTime::minutesFromHours($request->request->get('hours'));

        if (null === $minutes || $minutes <= 0) {
            $this->addFlash('error', 'Pon cuántas horas le has dedicado.');

            return $this->redirectToRoute('panel_volunteering_mine');
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

        return $this->redirectToRoute('panel_volunteering_mine');
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

            return $this->redirectToRoute('panel_volunteering_areas');
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

        return $this->redirectToRoute('panel_volunteering_areas');
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
