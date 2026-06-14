<?php

namespace App\Controller;

use App\Entity\Basket;
use App\Entity\City;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerEvent;
use App\Entity\State;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Entity\WeeklyBasketStatus;
use App\Form\ChangePasswordType;
use App\Form\PartnerProfileType;
use App\Repository\BasketRepository;
use App\Repository\PartnerBasketShareRepository;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Repository\WeeklyBasketGroupRepository;
use App\Repository\WeeklyBasketRepository;
use App\Service\Delivery\DeliveryCalendarProjector;
use App\Service\Delivery\DeliveryCalendarViewBuilder;
use App\Service\Delivery\DeliveryDeadline;
use App\Service\Delivery\DeliveryShiftApplier;
use App\Service\Delivery\DeliveryShiftValidator;
use App\Service\Delivery\PickupRelocationOptions;
use App\Service\Delivery\PickupRelocator;
use App\Service\Delivery\WeeklyBasketGenerator;
use App\Service\Delivery\WeeklyBasketSkipper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Panel del socix — vista personal de cada socia/o.
 *
 * Requiere ROLE_PARTNER (asignado en User.roles) y un Partner vinculado
 * a User.partner. Si falta el vínculo, el panel redirige al admin (o a
 * la home pública) en lugar de explotar.
 */
#[Route('/panel')]
#[IsGranted('ROLE_PARTNER')]
class PanelController extends AbstractController
{
    public function __construct(
        private readonly DeliveryDeadline $deadline,
    ) {
    }

    #[Route('', name: 'panel', methods: ['GET'])]
    public function index(PickupRelocationOptions $relocationOptions): Response
    {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        $partner = $this->getUser()->getPartner();

        // El traslado de nodo opera sobre la cesta de la FAMILIA (el principal), igual que
        // el calendario: un secundario tramita el traslado de la cesta de su hogar.
        $owner = $this->basketOwner($partner);

        // Las cestas COMPARTIDAS (alternancia entre dos hogares) no se trasladan de nodo de
        // momento (lo bloquea PickupRelocator): no ofrecer el atajo. Vacío → modal sin pintar.
        // Además, el traslado es autoservicio: tras el feature-flag (la acción
        // panel_basket_change_group ya responde 403 con él apagado). Vaciar aquí evita
        // ofrecer un atajo que acabaría en error.
        $canRelocate = $owner->getSharePartner() === null
            && $this->isGranted('FEATURE_PARTNER_SELFSERVICE');

        // La home replica las secciones de la ficha admin (partner/show) pero en
        // SOLO LECTURA: el socix ve sus datos, su cesta, su familia y su histórico,
        // sin las acciones de gestión (cambiar/dar de baja cesta, quitar familiares).
        // Único autoservicio embebido aquí: "recoger en otro nodo" (traslado puntual),
        // un atajo del que también vive en el calendario. El resto (saltar/mover) va al
        // calendario de recogida.
        return $this->render('Panel/index.html.twig', [
            'partner' => $partner,
            'active_share' => $this->activeShare($partner),
            'group' => $partner->getWeeklyBasketGroup(),
            'relocate_weeks' => $canRelocate ? $relocationOptions->weeksForPartner($owner) : [],
            'relocate_groups' => $canRelocate ? $relocationOptions->groupsForPartner($owner) : [],
        ]);
    }

    #[Route('/perfil', name: 'panel_profile', methods: ['GET', 'POST'])]
    public function profile(
        Request $request,
        EntityManagerInterface $em,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        $partner = $this->getUser()->getPartner();

        $profileForm = $this->createForm(PartnerProfileType::class, $partner);
        $profileForm->handleRequest($request);

        if ($profileForm->isSubmitted() && $profileForm->isValid()) {
            $em->flush();
            $this->addFlash('notice', 'Tus datos se han guardado.');
            return $this->redirectToRoute('panel_profile');
        }

        return $this->render('Panel/profile.html.twig', [
            'partner' => $partner,
            'profile_form' => $profileForm->createView(),
        ]);
    }

    #[Route('/perfil/contrasena', name: 'panel_password', methods: ['GET', 'POST'])]
    public function password(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        $user = $this->getUser();

        $passwordForm = $this->createForm(ChangePasswordType::class);
        $passwordForm->handleRequest($request);

        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            $current = $passwordForm->get('currentPassword')->getData();
            if (!$hasher->isPasswordValid($user, $current)) {
                $passwordForm->get('currentPassword')->addError(
                    new \Symfony\Component\Form\FormError('La contraseña actual no es correcta.')
                );
            } else {
                $user->setPassword($hasher->hashPassword($user, $passwordForm->get('newPassword')->getData()));
                $em->flush();
                $this->addFlash('notice', 'Tu contraseña se ha actualizado.');
                return $this->redirectToRoute('panel_profile');
            }
        }

        return $this->render('Panel/password.html.twig', [
            'password_form' => $passwordForm->createView(),
        ]);
    }

    /**
     * Devuelve los municipios de una provincia. Sirve al select dependiente
     * del form de perfil: 8112 ciudades es demasiado para inyectar todas en
     * el HTML, así que el JS pide solo las de la provincia seleccionada.
     */
    #[Route('/perfil/municipios/{state}', name: 'panel_cities_by_state', methods: ['GET'])]
    public function citiesByState(State $state): JsonResponse
    {
        $cities = array_map(
            static fn (City $c) => ['id' => $c->getId(), 'name' => (string) $c],
            $state->getCities()->toArray()
        );

        usort($cities, static fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return new JsonResponse($cities);
    }

    /**
     * "Mi cesta" antiguo (próxima cesta + cambios de reparto en formato viejo) RETIRADO:
     * toda la gestión vive ahora en el calendario de recogida (no recoger / mover, con
     * sus reglas). La ruta se mantiene como redirección para enlaces o marcadores viejos.
     */
    #[Route('/cesta', name: 'panel_basket', methods: ['GET'])]
    public function basket(): Response
    {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        return $this->redirectToRoute('panel_calendar');
    }

    /**
     * Saltar / volver a recoger la próxima cesta. Acción toggle (status
     * 1 ↔ 2) limitada al deadline previo al viernes. Cada toggle deja
     * un PartnerEvent en el feed del socix para que admin lo vea en el
     * resumen periódico.
     */
    #[Route('/cesta/skip-toggle', name: 'panel_basket_skip_toggle', methods: ['POST'])]
    #[IsGranted('FEATURE_PARTNER_SELFSERVICE')]
    public function skipNextBasket(
        Request $request,
        WeeklyBasketRepository $weeklyBasketRepository,
        WeeklyBasketSkipper $skipper,
        PartnerDeliveryShiftRepository $deliveryShiftRepository,
        EntityManagerInterface $em,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('panel_basket_skip', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return $this->redirectToRoute('panel_basket');
        }

        $partner = $this->getUser()->getPartner();
        $next = $weeklyBasketRepository->findNextForPartner($partner);

        if ($next === null) {
            $this->addFlash('warning', 'No tienes una próxima cesta sobre la que actuar.');
            return $this->redirectToRoute('panel_basket');
        }

        if (!$this->isWithinPickupDeadline($next)) {
            $this->addFlash('error', 'Ya no se puede cambiar la cesta de esta semana — el plazo terminó. Si necesitas avisar, contacta con la administración.');
            return $this->redirectToRoute('panel_basket');
        }

        // Si hay un cambio puntual activo, el estado de la WB origen está
        // gestionado por el shift (status=2 como cascada). Permitir el
        // toggle aquí dejaría el shift desincronizado (WB origen volvería
        // a "recoge" pero el shift y la WB destino seguirían vivos →
        // doble recogida). Defensa en profundidad por si la UI falla.
        if ($deliveryShiftRepository->findOutgoing($partner, $next->getBasket()) !== null) {
            $this->addFlash('warning', 'Tienes un cambio puntual de viernes activo: cancélalo primero si quieres volver a la pauta normal.');
            return $this->redirectToRoute('panel_basket');
        }

        // Status 1 = "Recoge", 2 = "No la recoge". El toggle solo opera entre
        // estos dos; cualquier otro estado lo deja como está y avisa al socix
        // (caso defensivo, no debería ocurrir en panel). La semántica vive en
        // WeeklyBasketSkipper, compartida con el calendario de recogida (admin).
        $skipped = $skipper->toggle($next, 'partner:' . $partner->getId());

        if ($skipped === true) {
            $this->addFlash('notice', 'Listo: hemos marcado que esta semana no recogerás la cesta. Si cambias de opinión, puedes deshacerlo desde aquí antes del plazo.');
        } elseif ($skipped === false) {
            $this->addFlash('notice', 'Listo: al final sí recogerás la cesta esta semana.');
        } else {
            $this->addFlash('warning', 'Tu cesta está en un estado especial y no se puede cambiar desde aquí. Contacta con la administración si necesitas modificarla.');
        }

        $em->flush();
        return $this->redirectToRoute('panel_basket');
    }

    /**
     * Cambio puntual de nodo de recogida para una semana concreta (la elige el socix
     * entre sus entregas futuras). El traslado vive en {@see PickupRelocator}, compartido
     * con el admin: crea/re-apunta el PartnerNodeOverride de esa semana, cascadea a piedra
     * si está materializada y registra el evento. NO toca Partner.weeklyBasketGroup (el
     * nodo de casa permanente): el cambio es solo de ese reparto.
     *
     * En familias resuelve el dueño de la cesta (el principal, vía {@see basketOwner}),
     * como el resto del autoservicio del calendario.
     *
     * @param Request                     $request   Lleva basket_id, group_id y el token CSRF.
     * @param BasketRepository            $basketRepository
     * @param WeeklyBasketGroupRepository $weeklyBasketGroupRepository
     * @param PickupRelocator             $relocator
     * @return Response
     */
    #[Route('/cesta/change-group', name: 'panel_basket_change_group', methods: ['POST'])]
    #[IsGranted('FEATURE_PARTNER_SELFSERVICE')]
    public function changePickupGroup(
        Request $request,
        BasketRepository $basketRepository,
        WeeklyBasketGroupRepository $weeklyBasketGroupRepository,
        PickupRelocator $relocator,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('panel_basket_change_group', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return $this->redirectToRoute('panel');
        }

        $partner = $this->basketOwner($this->getUser()->getPartner());

        $basketId = (int) $request->request->get('basket_id', 0);
        $basket = $basketId > 0 ? $basketRepository->find($basketId) : null;
        if ($basket === null) {
            $this->addFlash('error', 'Selecciona una semana válida.');
            return $this->redirectToRoute('panel');
        }

        if (!$this->isWithinPickupDeadlineForBasket($basket, $partner)) {
            $this->addFlash('error', 'Ya no se puede cambiar de nodo para esa semana — el plazo terminó. Contacta con la administración si necesitas avisar.');
            return $this->redirectToRoute('panel');
        }

        $groupId = (int) $request->request->get('group_id', 0);
        $group = $groupId > 0 ? $weeklyBasketGroupRepository->find($groupId) : null;
        if ($group === null) {
            $this->addFlash('error', 'Selecciona un nodo de recogida válido.');
            return $this->redirectToRoute('panel');
        }

        // El traslado (crear/re-apuntar el override, cascadear a piedra si la semana ya está
        // materializada, recalcular la fecha física y registrar el evento) vive en
        // PickupRelocator, compartido con el admin. Valida que el destino reparta esa semana.
        try {
            $relocator->relocate($partner, $basket, $group, 'partner:' . $partner->getId());
        } catch (\LogicException $e) {
            $this->addFlash('warning', $e->getMessage());
            return $this->redirectToRoute('panel');
        }

        $this->addFlash('notice', sprintf(
            'Listo: la semana del %s recogerás la cesta en "%s". El cambio aplica solo a ese reparto.',
            $basket->getDate()->format('d/m/Y'),
            $group->getName(),
        ));
        return $this->redirectToRoute('panel');
    }

    /**
     * Pide cambio puntual del viernes que le tocaba al socix por otro
     * viernes compatible. Validador centraliza las reglas (Window,
     * Balance, Deadline); el socix nunca puede saltarse violaciones
     * bypassables — se le devuelve un mensaje claro y se le invita a
     * contactar con admin si quiere forzar.
     */
    #[Route('/cesta/cambiar-viernes', name: 'panel_basket_shift_request', methods: ['POST'])]
    #[IsGranted('FEATURE_PARTNER_SELFSERVICE')]
    public function requestShift(
        Request $request,
        WeeklyBasketRepository $weeklyBasketRepository,
        BasketRepository $basketRepository,
        DeliveryShiftValidator $validator,
        DeliveryShiftApplier $applier,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('panel_basket_shift', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return $this->redirectToRoute('panel_basket');
        }

        $partner = $this->getUser()->getPartner();
        $next = $weeklyBasketRepository->findNextForPartner($partner);
        $from = $next?->getBasket();
        if ($from === null) {
            $this->addFlash('warning', 'No tienes una próxima cesta sobre la que pedir cambio.');
            return $this->redirectToRoute('panel_basket');
        }

        $toBasketId = (int) $request->request->get('to_basket_id', 0);
        $to = $toBasketId > 0 ? $basketRepository->find($toBasketId) : null;
        if ($to === null) {
            $this->addFlash('error', 'Selecciona un viernes destino válido.');
            return $this->redirectToRoute('panel_basket');
        }

        $violations = $validator->validate($partner, $from, $to);
        if (!empty($violations)) {
            foreach ($violations as $v) {
                $this->addFlash($v->bypassable ? 'warning' : 'error', $v->message);
            }
            return $this->redirectToRoute('panel_basket');
        }

        try {
            $applier->apply($partner, $from, $to, actor: 'partner:' . $partner->getId());
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('panel_basket');
        }

        $this->addFlash('notice', sprintf(
            'Listo: en lugar del viernes %s recogerás la cesta el %s.',
            $from->getDate()->format('d/m/Y'),
            $to->getDate()->format('d/m/Y'),
        ));
        return $this->redirectToRoute('panel_basket');
    }

    /**
     * Cancela el cambio puntual de viernes activo del socix. Pasa por
     * el validator igualmente: el deadline aplica también a la reversión.
     */
    #[Route('/cesta/cancelar-cambio-viernes', name: 'panel_basket_shift_cancel', methods: ['POST'])]
    #[IsGranted('FEATURE_PARTNER_SELFSERVICE')]
    public function cancelShift(
        Request $request,
        WeeklyBasketRepository $weeklyBasketRepository,
        PartnerDeliveryShiftRepository $deliveryShiftRepository,
        DeliveryShiftValidator $validator,
        DeliveryShiftApplier $applier,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        if (!$this->isCsrfTokenValid('panel_basket_shift_cancel', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return $this->redirectToRoute('panel_basket');
        }

        $partner = $this->getUser()->getPartner();
        $next = $weeklyBasketRepository->findNextForPartner($partner);
        $from = $next?->getBasket();
        $shift = $from !== null ? $deliveryShiftRepository->findOutgoing($partner, $from) : null;

        if ($shift === null) {
            $this->addFlash('warning', 'No tienes un cambio puntual de viernes que cancelar.');
            return $this->redirectToRoute('panel_basket');
        }

        $violations = $validator->validate($partner, $shift->getFromBasket(), $shift->getToBasket());
        $blocking = $validator->blocking($violations);
        if (!empty($blocking)) {
            foreach ($blocking as $v) {
                $this->addFlash('error', $v->message);
            }
            return $this->redirectToRoute('panel_basket');
        }

        $applier->cancel($shift, actor: 'partner:' . $partner->getId());

        $this->addFlash('notice', 'Listo: hemos cancelado el cambio. Recogerás la cesta el viernes que te tocaba.');
        return $this->redirectToRoute('panel_basket');
    }

    /**
     * ¿Sigue abierto el plazo para tocar esta WeeklyBasket? Delega en
     * {@see DeliveryDeadline}, fuente única del plazo (antelación + hora
     * configurables, sobre la fecha física del nodo), compartida con el
     * validador del admin.
     */
    private function isWithinPickupDeadline(WeeklyBasket $weeklyBasket): bool
    {
        $partner = $weeklyBasket->getPartner();
        $basket = $weeklyBasket->getBasket();
        if ($partner === null || $basket === null) {
            return false;
        }

        return $this->deadline->isOpen($partner, $basket);
    }

    /**
     * ¿Sigue abierto el plazo para que $partner cambie su reparto en $basket?
     * Misma fuente que {@see isWithinPickupDeadline}.
     */
    private function isWithinPickupDeadlineForBasket(Basket $basket, Partner $partner): bool
    {
        return $this->deadline->isOpen($partner, $basket);
    }

    /**
     * Calendario de recogida del socio — la misma pantalla que usa el gestor, pero
     * resuelta desde la sesión y por ahora en SOLO LECTURA (withDropTargets: false).
     * En familias resuelve el dueño de la cesta (el principal: la PBS cuelga de él),
     * para que un secundario vea el calendario de su familia y no uno vacío.
     *
     * @param Request                     $request
     * @param DeliveryCalendarViewBuilder $viewBuilder
     * @return Response
     */
    #[Route('/calendar', name: 'panel_calendar', methods: ['GET'])]
    public function calendar(Request $request, DeliveryCalendarViewBuilder $viewBuilder): Response
    {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        $partner = $this->basketOwner($this->getUser()->getPartner());

        $month = $request->query->has('month') ? (int) $request->query->get('month') : null;
        $year = $request->query->has('year') ? (int) $request->query->get('year') : null;
        $selectedBasketId = (int) $request->query->get('sel', 0);

        // El autoservicio (saltar/mover/recuperar) está tras un feature-flag: con él
        // apagado el calendario queda en solo-lectura — sin drop targets ni botones de
        // acción. La protección dura vive en cada endpoint (#[IsGranted]); esto es UX
        // para no ofrecer gestos que acabarían en un 403.
        $canSelfServe = $this->isGranted('FEATURE_PARTNER_SELFSERVICE');

        // withDropTargets: true → calcula los días libres a los que el socio puede mover
        // una entrega (los marca para el flujo de "mover por toque").
        $view = $viewBuilder->build($partner, $year, $month, $selectedBasketId, withDropTargets: $canSelfServe);

        return $this->render('Panel/calendar.html.twig', $view + ['can_self_serve' => $canSelfServe]);
    }

    /**
     * Dueño de la cesta del socio: en una familia (parent_id) la PartnerBasketShare
     * cuelga del principal, así que un secundario sin cesta propia ve la de su familia.
     * Si el propio socio tiene cesta activa (caso raro de secundario con PBS propia),
     * se queda con la suya. Sin parent, es él mismo.
     *
     * @param Partner $partner
     * @return Partner
     */
    private function basketOwner(Partner $partner): Partner
    {
        $parent = $partner->getParent();
        if ($parent !== null && $this->activeShare($partner) === null) {
            return $parent;
        }
        return $partner;
    }

    /**
     * "No recoger" / "volver a recoger" una semana desde el calendario del socio.
     * Es el mismo modelo de intents que usa el gestor (DeliveryShiftApplier), pero
     * con DEADLINE de autoservicio (el gestor no lo tiene) y resuelto desde la sesión.
     * En esta fase el socio aún no MUEVE entregas; las ramas de cesta entrante/saliente
     * solo se alcanzan si gestión le movió una cesta, y se tratan con tacto.
     *
     * @param int                            $basketId
     * @param Request                        $request
     * @param WeeklyBasketGenerator          $generator
     * @param PartnerDeliveryShiftRepository $shiftRepository
     * @param DeliveryShiftApplier           $applier
     * @param EntityManagerInterface         $em
     * @return Response
     */
    #[Route('/calendar/skip/{basketId}', name: 'panel_calendar_skip', methods: ['POST'], requirements: ['basketId' => '\\d+'])]
    #[IsGranted('FEATURE_PARTNER_SELFSERVICE')]
    public function calendarSkip(
        int $basketId,
        Request $request,
        WeeklyBasketGenerator $generator,
        PartnerDeliveryShiftRepository $shiftRepository,
        DeliveryShiftApplier $applier,
        EntityManagerInterface $em,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        $partner = $this->basketOwner($this->getUser()->getPartner());

        $basket = $em->getRepository(Basket::class)->find($basketId);
        if ($basket === null) {
            throw $this->createNotFoundException('Semana de reparto no encontrada.');
        }

        $backToCalendar = fn (): Response => $this->redirectToRoute('panel_calendar', [
            'year' => $basket->getDate()->format('Y'),
            'month' => $basket->getDate()->format('n'),
            'sel' => $basket->getId(),
        ]);

        if (!$this->isCsrfTokenValid('panel_calendar_skip', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $backToCalendar();
        }

        // R1: la cesta compartida la marca la alternancia con el otro hogar.
        if ($partner->getSharePartner() !== null) {
            $this->addFlash('warning', 'Tu cesta es compartida: el día lo marca la alternancia con el otro hogar.');

            return $backToCalendar();
        }

        // Plazo de autoservicio: hasta el jueves 23:59 anterior al reparto. Defensa
        // server-side; el calendario ya lo oculta en el cliente cuando ha pasado.
        if (!$this->isWithinPickupDeadlineForBasket($basket, $partner)) {
            $this->addFlash('error', 'Ya no se puede cambiar esa semana — el plazo terminó. Si necesitas avisar, contacta con la administración.');

            return $backToCalendar();
        }

        $actor = 'partner:' . $partner->getId();
        $generated = $em->getRepository(WeeklyBasket::class)->findOneBy(['basket' => $basket]) !== null;
        $outgoing = $shiftRepository->findOutgoing($partner, $basket);
        $incoming = $shiftRepository->findIncoming($partner, $basket);

        // VOLVER A RECOGER: acción explícita (botón del panel sobre una semana aparcada).
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
                $this->addFlash('notice', 'Listo: vuelves a recoger esa semana.');
            } else {
                $this->addFlash('warning', 'Esa semana no tiene ninguna entrega aparcada que recuperar.');
            }

            return $backToCalendar();
        }

        // NO RECOGE. Si la cesta de ese día vino MOVIDA de otro (lo hizo gestión), se
        // aparca esa entrega entrante (igual que en el calendario del gestor).
        if ($incoming !== null) {
            $applier->skipMovedDelivery($incoming, $actor);
            $this->addFlash('notice', 'Listo: esta semana no la recoges.');

            return $backToCalendar();
        }
        if ($outgoing !== null && $outgoing->isSkip()) {
            $this->addFlash('warning', 'Esa semana ya está marcada como que no la recoges.');

            return $backToCalendar();
        }
        if ($outgoing !== null) {
            $this->addFlash('warning', 'La cesta de esa semana está movida a otro día. Si necesitas cambiarlo, contacta con la administración.');

            return $backToCalendar();
        }

        // Debe haber una cesta esa semana para poder no-recogerla.
        if (!$generated && $em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $basket->getDate()) === null) {
            $this->addFlash('warning', 'No tienes una cesta esa semana.');

            return $backToCalendar();
        }

        $applier->applySkipIntent($partner, $basket, null, $actor);
        $this->addFlash('notice', 'Listo: esta semana no la recoges.');

        return $backToCalendar();
    }

    /**
     * Mover una entrega a otra fecha desde el calendario del socio (cambio puntual de
     * viernes). Mismas reglas que el gestor (DeliveryShiftApplier::move) salvo el scope:
     * el socio solo mueve su propia cesta. Las invariantes físicas (cesta activa, deadline
     * día-anterior, el nodo reparte el destino, un hueco por día) se comprueban inline;
     * no hay validador de autoservicio extra. Resuelto desde la sesión.
     *
     * @param int                       $basketId Semana origen (Basket) donde está la cesta.
     * @param Request                   $request
     * @param BasketRepository          $basketRepository
     * @param WeeklyBasketGenerator     $generator
     * @param DeliveryCalendarProjector $projector
     * @param DeliveryShiftApplier      $applier
     * @param EntityManagerInterface    $em
     * @return Response
     */
    #[Route('/calendar/move/{basketId}', name: 'panel_calendar_move', methods: ['POST'], requirements: ['basketId' => '\\d+'])]
    #[IsGranted('FEATURE_PARTNER_SELFSERVICE')]
    public function calendarMove(
        int $basketId,
        Request $request,
        BasketRepository $basketRepository,
        WeeklyBasketGenerator $generator,
        DeliveryCalendarProjector $projector,
        DeliveryShiftApplier $applier,
        EntityManagerInterface $em,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        $partner = $this->basketOwner($this->getUser()->getPartner());

        $from = $basketRepository->find($basketId);
        if ($from === null) {
            throw $this->createNotFoundException('Semana de reparto no encontrada.');
        }

        $backToFrom = fn (): Response => $this->redirectToRoute('panel_calendar', [
            'year' => $from->getDate()->format('Y'),
            'month' => $from->getDate()->format('n'),
            'sel' => $from->getId(),
        ]);

        if (!$this->isCsrfTokenValid('panel_calendar_move', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $backToFrom();
        }

        // R1: la cesta compartida no cambia de día (lo marca la alternancia).
        if ($partner->getSharePartner() !== null) {
            $this->addFlash('warning', 'Tu cesta es compartida: el día lo marca la alternancia con el otro hogar.');

            return $backToFrom();
        }

        $share = $em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $from->getDate());
        if ($share === null) {
            $this->addFlash('error', 'No tienes una cesta activa.');

            return $backToFrom();
        }

        $today = new \DateTimeImmutable('today');

        // Deadline: se mueve hasta el día ANTERIOR a la recogida (fecha física).
        $fromPhysical = $generator->projectShareDelivery($from, $share)['deliveryDate'] ?? $from->getDate();
        if ($fromPhysical <= $today) {
            $this->addFlash('warning', 'Esa cesta se recoge hoy o ya pasó: ya no se puede mover.');

            return $backToFrom();
        }

        $to = $basketRepository->find((int) $request->request->get('to_basket_id', 0));
        if ($to === null) {
            $this->addFlash('error', 'Elige una fecha destino válida.');

            return $backToFrom();
        }

        // El nodo del socio debe repartir el día destino, y debe ser futuro.
        $toProjection = $generator->projectShareDelivery($to, $share);
        if ($toProjection === null) {
            $this->addFlash('error', 'Tu nodo no reparte ese día: elige otra fecha.');

            return $backToFrom();
        }
        $toPhysical = $toProjection['deliveryDate'] ?? $to->getDate();
        if ($toPhysical <= $today) {
            $this->addFlash('error', 'No puedes mover la cesta a hoy ni a una fecha pasada.');

            return $backToFrom();
        }

        // Un hueco por día: el destino no puede tener ya una entrega real, salvo que sea
        // el día de patrón de la propia cesta (volver atrás = quitar el cambio).
        $shiftRepo = $em->getRepository(PartnerDeliveryShift::class);
        $patternOrigin = $shiftRepo->findIncoming($partner, $from)?->getFromBasket() ?? $from;
        $isReturn = $to->getId() === $patternOrigin->getId();
        $toDate = $to->getDate();
        foreach ($projector->projectMonth($partner, (int) $toDate->format('Y'), (int) $toDate->format('n')) as $s) {
            if ($s['basket']->getId() !== $to->getId()) {
                continue;
            }
            if (!$isReturn && !empty($s['items'])) {
                $this->addFlash('error', 'Ya tienes una entrega ese día.');

                return $backToFrom();
            }
            break;
        }

        // El socio mueve con las MISMAS reglas que el gestor (paridad de comportamiento;
        // lo único que cambia es el scope: el gestor mueve a cualquier socio, el socio
        // solo a sí mismo). Las invariantes físicas ya están comprobadas arriba (cesta
        // activa, deadline día-anterior, el nodo reparte el destino, un hueco por día);
        // no hay validador de autoservicio extra. ($isReturn solo cambia el mensaje.)
        try {
            $applier->move($partner, $from, $to, actor: 'partner:' . $partner->getId());
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());

            return $backToFrom();
        }

        $this->addFlash('notice', $isReturn
            ? 'Listo: la cesta vuelve a su día habitual.'
            : 'Listo: cambiada al ' . $to->getDate()->format('d/m/Y') . '.');

        return $this->redirectToRoute('panel_calendar', [
            'year' => $to->getDate()->format('Y'),
            'month' => $to->getDate()->format('n'),
            'sel' => $to->getId(),
        ]);
    }

    /**
     * Recuperar (volver a recoger) una entrega aparcada en un DÍA ELEGIDO desde el
     * calendario del socio — el destino del arrastre de una tarjeta de la papelera.
     * Mismo mecanismo que el gestor (DeliveryShiftApplier::recoverSkipToDay), con plazo
     * de autoservicio y resuelto desde la sesión. Comparte el token CSRF con "mover".
     *
     * @param int                            $basketId Semana ORIGEN (aparcada) que se recupera.
     * @param Request                        $request
     * @param BasketRepository               $basketRepository
     * @param WeeklyBasketGenerator          $generator
     * @param PartnerDeliveryShiftRepository $shiftRepository
     * @param DeliveryShiftApplier           $applier
     * @param EntityManagerInterface         $em
     * @return Response
     */
    #[Route('/calendar/recover/{basketId}', name: 'panel_calendar_recover', methods: ['POST'], requirements: ['basketId' => '\\d+'])]
    #[IsGranted('FEATURE_PARTNER_SELFSERVICE')]
    public function calendarRecover(
        int $basketId,
        Request $request,
        BasketRepository $basketRepository,
        WeeklyBasketGenerator $generator,
        PartnerDeliveryShiftRepository $shiftRepository,
        DeliveryShiftApplier $applier,
        EntityManagerInterface $em,
    ): Response {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        $partner = $this->basketOwner($this->getUser()->getPartner());

        $origin = $basketRepository->find($basketId);
        if ($origin === null) {
            throw $this->createNotFoundException('Semana de reparto no encontrada.');
        }

        $backToCalendar = fn (Basket $sel): Response => $this->redirectToRoute('panel_calendar', [
            'year' => $sel->getDate()->format('Y'),
            'month' => $sel->getDate()->format('n'),
            'sel' => $sel->getId(),
        ]);

        // Comparte token con "mover" (panel_calendar_move): ambos gestos van por MOVE_CSRF.
        if (!$this->isCsrfTokenValid('panel_calendar_move', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');

            return $backToCalendar($origin);
        }

        if ($partner->getSharePartner() !== null) {
            $this->addFlash('warning', 'Tu cesta es compartida: el día lo marca la alternancia con el otro hogar.');

            return $backToCalendar($origin);
        }

        $skip = $shiftRepository->findOutgoing($partner, $origin);
        if ($skip === null || !$skip->isSkip()) {
            $this->addFlash('warning', 'Esa entrega ya no está aparcada.');

            return $backToCalendar($origin);
        }

        $share = $em->getRepository(PartnerBasketShare::class)->findActiveForPartner($partner, $origin->getDate());
        if ($share === null) {
            $this->addFlash('error', 'No tienes una cesta activa.');

            return $backToCalendar($origin);
        }

        $to = $basketRepository->find((int) $request->request->get('to_basket_id', 0));
        if ($to === null) {
            $this->addFlash('error', 'Elige una fecha destino válida.');

            return $backToCalendar($origin);
        }

        $today = new \DateTimeImmutable('today');
        $toProjection = $generator->projectShareDelivery($to, $share);
        if ($toProjection === null) {
            $this->addFlash('error', 'Tu nodo no reparte ese día: elige otra fecha.');

            return $backToCalendar($origin);
        }
        if (($toProjection['deliveryDate'] ?? $to->getDate()) <= $today) {
            $this->addFlash('error', 'No puedes recuperar la cesta en hoy ni en una fecha pasada.');

            return $backToCalendar($origin);
        }

        $actor = 'partner:' . $partner->getId();

        // ¿La cesta aparcada era de SOLO-HUEVO? (semana origen sin verdura por patrón). Si no
        // se distingue, recuperarla sobre una semana de cesta recompondría verdura de la nada.
        $originIsCestaWeek = false;
        foreach ($generator->projectForBasket($origin) as $candidate) {
            if ($candidate->getPartner()->getId() === $partner->getId()) {
                $originIsCestaWeek = true;
                break;
            }
        }

        $applier->recoverSkipToDay($skip, $to, !$originIsCestaWeek, $actor);
        $this->addFlash('notice', $to->getId() === $origin->getId()
            ? 'Recuperada en su día habitual.'
            : 'Recuperada el ' . $to->getDate()->format('d/m/Y') . '.');

        return $backToCalendar($to);
    }

    #[Route('/familia', name: 'panel_family', methods: ['GET'])]
    public function family(): Response
    {
        if (($redirect = $this->ensureReady()) !== null) {
            return $redirect;
        }

        // Inferencia de familia: por ahora dejamos placeholder.
        // El modelo actual no tiene una entidad explícita de "familia" y la
        // inferencia desde PartnerBasketShare necesita validación con admin.
        // Cuando se aclare, se rellena esta vista.
        return $this->render('Panel/family.html.twig', [
            'partner' => $this->getUser()->getPartner(),
            'family_members' => [],
        ]);
    }

    /**
     * Gating común a todas las acciones del panel: comprueba que el User tenga
     * un Partner vinculado; si no, lo saca del panel.
     *
     * El forzado de contraseña (passwordSet = false) NO se mira aquí: lo cubre
     * globalmente {@see \App\EventSubscriber\ForcePasswordChangeSubscriber},
     * que intercepta la petición antes de llegar a este controller.
     *
     * Devuelve null si todo está bien y el controller puede seguir.
     */
    private function ensureReady(): ?RedirectResponse
    {
        $user = $this->getUser();

        if ($user && method_exists($user, 'getPartner') && $user->getPartner() !== null) {
            return null;
        }

        $this->addFlash('error', 'Tu usuaria no está vinculada a un socix; pide a admin que te vincule para usar el panel.');

        foreach (['ROLE_ADMIN', 'ROLE_GESTION_GRANJA', 'ROLE_GESTION_SOCIXS', 'ROLE_GESTION_REPARTO', 'ROLE_BLOG'] as $role) {
            if ($this->isGranted($role)) {
                return $this->redirectToRoute('dashboard');
            }
        }
        return $this->redirectToRoute('homepage');
    }

    /**
     * PartnerBasketShare activo del socix: el más reciente que aún no ha
     * sido cerrado (end_date NULL o futura). Devuelve null si no tiene.
     */
    private function activeShare(Partner $partner): ?PartnerBasketShare
    {
        $today = new \DateTime('today');
        $active = null;
        foreach ($partner->getPartnerBasketShares() as $share) {
            $end = $share->getEndDate();
            if ($end === null || $end >= $today) {
                if ($active === null || $share->getStartDate() > $active->getStartDate()) {
                    $active = $share;
                }
            }
        }
        return $active;
    }
}
