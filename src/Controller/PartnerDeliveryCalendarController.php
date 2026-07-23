<?php

namespace App\Controller;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\WeeklyBasket;
use App\Repository\BasketRepository;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Service\Delivery\AccumulatingMove;
use App\Service\Delivery\DeliveryCalendarProjector;
use App\Service\Delivery\DeliveryCalendarViewBuilder;
use App\Service\Delivery\DeliveryShiftApplier;
use App\Service\Delivery\EggScheduleException;
use App\Service\Delivery\ExtraBasketEditor;
use App\Service\Delivery\PartnerEggScheduleEditor;
use App\Service\Delivery\PartnerMonthResetter;
use App\Service\Delivery\WeeklyBasketComponentEditor;
use App\Service\Delivery\WeeklyBasketGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Calendario de recogida por socia: ver y EDITAR las entregas de una socia
 * (mover de semana, marcar "no recoge", recuperar, reiniciar el mes, ajustar
 * componentes).
 *
 * Extraído de PartnerController porque es LOGÍSTICA DE REPARTO, no gestión de
 * la persona: por eso lo pueden usar tanto ROLE_GESTION_REPARTO como
 * ROLE_GESTION_SOCIXS (el resto de la ficha —datos personales— sigue solo en
 * socixs). Se llega aquí desde la ficha (socixs) y desde la pantalla de reparto
 * (enlace "Cambiar de viernes / no recoge"). Mismos nombres de ruta que antes.
 */
#[Route('/gestion/partner')]
#[IsGranted(new Expression("is_granted('ROLE_GESTION_REPARTO') or is_granted('ROLE_GESTION_SOCIXS')"))]
class PartnerDeliveryCalendarController extends AbstractController
{
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
        AccumulatingMove $accumulatingMove,
        PartnerEggScheduleEditor $eggEditor,
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

        // Componente al que se limita el movimiento (verdura/huevos), o 0 = entrega entera.
        // Se resuelve ANTES de R1 porque la regla de compartidas depende de él.
        $componentId = (int) $request->request->get('component_id', 0);
        if (!in_array($componentId, [BasketComponent::ID_VEGETABLES, BasketComponent::ID_EGGS], true)) {
            $componentId = 0;
        }

        // R1: en una cesta compartida el DÍA de la cesta lo marca la alternancia con el otro
        // hogar → no se mueve la entrega entera ni la verdura. Los HUEVOS no se comparten (cada
        // hogar los suyos), así que mover SOLO el componente huevos sí se permite.
        if ($partner->getSharePartner() !== null && $componentId !== BasketComponent::ID_EGGS) {
            $this->addFlash('warning', 'Esta cesta es compartida: su día lo marca la alternancia con el otro hogar. Solo puedes mover los huevos, no la cesta.');

            return $backToCalendar();
        }

        // HUEVOS: la lógica (invariantes + moveComponent) vive en el editor compartido con el
        // panel del socio (DRY). Se delega aquí, ANTES de las validaciones de la entrega entera
        // —que quedan intactas para verdura y entrega completa—.
        if ($componentId === BasketComponent::ID_EGGS) {
            $to = $basketRepository->find((int) $request->request->get('to_basket_id', 0));
            try {
                $eggEditor->move($partner, $from, $to, 'gestor:' . $this->getUser()?->getId());
            } catch (EggScheduleException $e) {
                $this->addFlash('error', $e->getMessage());

                return $backToCalendar();
            }
            $this->addFlash('success', sprintf(
                'Huevos movidos del %s al %s.',
                $from->getDate()->format('d/m/Y'),
                $to->getDate()->format('d/m/Y'),
            ));
            [$year, $month] = $this->returnMonth($request, $to);

            return $this->redirectToRoute('partner_delivery_calendar', [
                'id' => $partner->getId(),
                'year' => $year,
                'month' => $month,
                'sel' => $to->getId(),
            ]);
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
        // ($componentId se resolvió arriba, antes de R1.)
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

            [$year, $month] = $this->returnMonth($request, $to);

            return $this->redirectToRoute('partner_delivery_calendar', [
                'id' => $partner->getId(),
                'year' => $year,
                'month' => $month,
                'sel' => $to->getId(),
            ]);
        }

        // Sin choque: el destino no puede tener ya una entrega REAL del socio (ítems no
        // vacíos) — un hueco por día. Un día vacío / "no recoge" SÍ vale (mover la cesta
        // ahí lo revive). Excepción: el día ORIGINAL de la propia cesta (volver = quitar
        // el cambio). Se usa la proyección de mes (shift-aware) para ver también entregas
        // previstas sin materializar.
        $shiftRepo = $em->getRepository(PartnerDeliveryShift::class);
        $patternOrigin = $shiftRepo->findIncoming($partner, $from)?->getFromBasket() ?? $from;
        $toDate = $to->getDate();
        foreach ($projector->projectMonth($partner, (int) $toDate->format('Y'), (int) $toDate->format('n')) as $s) {
            if ($s['basket']->getId() !== $to->getId()) {
                continue;
            }
            if ($to->getId() !== $patternOrigin->getId() && !empty($s['items'])) {
                // El destino YA recoge. Sin confirmar, se rechaza como siempre. Con
                // confirmación explícita (el front manda accumulate=1 tras el modal),
                // se TRASLADA sumando: el destino pasa a llevar 2 cestas y el origen se
                // vacía. No es un traslado reversible con un arrastre (ver AccumulatingMove).
                if (!$request->request->getBoolean('accumulate')) {
                    $this->addFlash('error', 'El socio ya tiene una entrega ese día.');

                    return $backToCalendar();
                }

                try {
                    // En transacción: el traslado son dos pasos (sumar al destino + vaciar el
                    // origen) que hacen flush por separado; envolverlos hace atómico el conjunto
                    // (si el segundo falla, rollback del primero) en vez de dejar estado parcial.
                    $em->wrapInTransaction(fn () => $accumulatingMove->move(
                        $partner,
                        $from,
                        $to,
                        actor: 'gestor:' . $this->getUser()?->getId(),
                    ));
                } catch (\LogicException $e) {
                    $this->addFlash('error', $e->getMessage());

                    return $backToCalendar();
                } catch (\Throwable $e) {
                    $this->addFlash('error', 'No se pudo trasladar la cesta. No se ha aplicado ningún cambio; inténtalo de nuevo.');

                    return $backToCalendar();
                }

                $this->addFlash('success', sprintf(
                    'Cesta del %s trasladada al %s: se suma a lo que ya recoge ese día.',
                    $from->getDate()->format('d/m/Y'),
                    $to->getDate()->format('d/m/Y'),
                ));

                [$year, $month] = $this->returnMonth($request, $to);

                return $this->redirectToRoute('partner_delivery_calendar', [
                    'id' => $partner->getId(),
                    'year' => $year,
                    'month' => $month,
                    'sel' => $to->getId(),
                ]);
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

        // Tras mover se selecciona el día destino, pero se VUELVE al mes que se estaba
        // viendo (no al del destino): si el movimiento cruzó de mes, el destino ya se dibuja
        // en la semana vecina de esa misma vista. Ver returnMonth().
        [$year, $month] = $this->returnMonth($request, $to);

        return $this->redirectToRoute('partner_delivery_calendar', [
            'id' => $partner->getId(),
            'year' => $year,
            'month' => $month,
            'sel' => $to->getId(),
        ]);
    }

    /**
     * Mes al que volver tras un cambio (mover / recuperar): el que se estaba VIENDO en el
     * calendario (campos view_year/view_month que manda el partial) si llega y es válido,
     * para no saltar al mes del destino cuando el movimiento cruza de mes — el destino se
     * dibuja en la semana vecina de esa misma vista. Si no llega (o es inválido), cae al mes
     * del $fallback (comportamiento previo).
     *
     * @param Request $request
     * @param Basket  $fallback Basket cuyo mes se usa si no llega una vista válida (el destino).
     * @return array{0: int, 1: int} [año, mes]
     */
    private function returnMonth(Request $request, Basket $fallback): array
    {
        $year = (int) $request->request->get('view_year', 0);
        $month = (int) $request->request->get('view_month', 0);
        if ($year >= 2000 && $month >= 1 && $month <= 12) {
            return [$year, $month];
        }

        return [(int) $fallback->getDate()->format('Y'), (int) $fallback->getDate()->format('n')];
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

        // Se vuelve al mes que se estaba viendo (no al del destino), igual que al mover:
        // el día recuperado puede caer en la semana vecina dibujada. Ver returnMonth().
        [$year, $month] = $this->returnMonth($request, $to);

        return $this->redirectToRoute('partner_delivery_calendar', [
            'id' => $partner->getId(),
            'year' => $year,
            'month' => $month,
            'sel' => $to->getId(),
        ]);
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
        PartnerEggScheduleEditor $eggEditor,
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

        // HUEVOS: el toggle (generado/sin-generar) vive en el editor compartido con el panel del
        // socio (DRY) y SIN R1 —los huevos sí se editan en compartidas—. La verdura sigue inline
        // más abajo con sus guardas R1 (resolveCalendarDelivery / ungeneratedEditGuard).
        if ($componentId === BasketComponent::ID_EGGS) {
            try {
                $result = $eggEditor->toggleEggs($partner, $basket, 'gestor:' . $this->getUser()?->getId());
            } catch (EggScheduleException $e) {
                $this->addFlash('error', $e->getMessage());

                return $backToCalendar();
            }
            match ($result) {
                'added' => $this->addFlash('success', 'Huevos añadidos a esa entrega.'),
                'removed' => $this->addFlash('success', 'Huevos quitados: esa semana no recoge huevos.'),
                default => $this->addFlash('warning', 'Esa entrega no contempla huevos según la cesta del socio.'),
            };

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
}
