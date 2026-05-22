<?php

namespace App\Controller;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerEvent;
use App\Entity\WeeklyBasketStatus;
use App\Repository\BasketRepository;
use App\Repository\DeliveryExceptionRepository;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Repository\PartnerRepository;
use App\Repository\PartnerBasketShareRepository;
use App\Repository\WeeklyBasketRepository;
use App\Repository\WeeklyBasketStatusRepository;
use App\Service\Delivery\DeliveryShiftApplier;
use App\Service\Delivery\DeliveryShiftCandidates;
use App\Service\Delivery\DeliveryShiftValidator;
use App\Service\Delivery\WeeklyBasketGenerator;
use App\Service\Delivery\WeeklyDeliveryReport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Vista detallada del reparto de un viernes concreto. Se accede desde el
 * calendario interno (pinchando en un evento "Reparto · cesta #N") y deja
 * ver el listado completo con búsqueda, orden por nombre/nodo/grupo y la
 * marca de excepción de calendario si la hay.
 *
 * Bajo el mismo prefijo se cuelga la gestión de cambios puntuales de
 * viernes (DeliveryShift): un admin puede registrar el cambio que pide
 * un socio por canal externo y/o cancelar uno en curso, con opción de
 * forzar reglas bypassables del validator.
 */
#[Route('/gestion/reparto')]
#[IsGranted('ROLE_GESTION_SOCIXS')]
class DeliveryController extends AbstractAppController
{
    #[Route('/cambios-viernes', name: 'delivery_shifts_index', methods: ['GET'])]
    public function shiftsIndex(
        Request $request,
        PartnerDeliveryShiftRepository $shiftRepo,
        PartnerRepository $partnerRepo,
        BasketRepository $basketRepo,
        WeeklyBasketRepository $weeklyBasketRepo,
    ): Response {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        // Shifts (cambios de viernes) cuyo viernes destino aún no ha pasado.
        $shifts = $shiftRepo->createQueryBuilder('s')
            ->innerJoin('s.fromBasket', 'fb')
            ->innerJoin('s.toBasket', 'tb')
            ->where('tb.date >= :today')
            ->setParameter('today', $today)
            ->orderBy('fb.date', 'ASC')
            ->getQuery()
            ->getResult();

        // "No recoge esta semana" (WeeklyBasket en status=2) que no son
        // cascada de un shift: si la WeeklyBasket está en status 2 porque hay
        // un PartnerDeliveryShift que la origina, el shift ya aparece en la
        // tabla y meter la WB sería duplicar.
        $skips = $weeklyBasketRepo->createQueryBuilder('wb')
            ->innerJoin('wb.basket', 'b')
            ->where('wb.weekly_basket_status = :statusNoRecoge')
            ->andWhere('b.date >= :today')
            ->andWhere('NOT EXISTS (
                SELECT 1 FROM \App\Entity\PartnerDeliveryShift s2
                WHERE s2.partner = wb.partner AND s2.fromBasket = wb.basket
            )')
            ->setParameter('statusNoRecoge', 2)
            ->setParameter('today', $today)
            ->orderBy('b.date', 'ASC')
            ->getQuery()
            ->getResult();

        $upcomingBaskets = $basketRepo->createQueryBuilder('b')
            ->where('b.date >= :today')
            ->setParameter('today', $today)
            ->orderBy('b.date', 'ASC')
            ->setMaxResults(16)
            ->getQuery()
            ->getResult();

        // Preseleccionar un socix tras una acción (redirect ?partner=ID) para
        // que el form se recargue ya cargado con su estado actual y el admin
        // no tenga que volver a buscarlo en el datalist.
        $preselectedId = $request->query->getInt('partner', 0);
        $preselected = $preselectedId > 0 ? $partnerRepo->find($preselectedId) : null;

        return $this->render('delivery/shifts_index.html.twig', [
            'shifts' => $shifts,
            'skips' => $skips,
            'partners' => $partnerRepo->findBy([], ['surname' => 'ASC', 'name' => 'ASC']),
            'upcoming_baskets' => $upcomingBaskets,
            'preselected_partner' => $preselected instanceof Partner ? $preselected : null,
        ]);
    }

    /**
     * Endpoint para el form dinámico: dado un partner, devuelve qué
     * cambio puede hacerse sobre su próxima cesta. Permite al JS de la
     * pantalla rellenar los selectores de origen y destino según la
     * modalidad del socio sin repintar la página.
     *
     * Respuesta:
     *   - modality          1=semanal, 2=quincenal, 3=mensual, 4=semanal compartida, 5=sólo huevos
     *   - modality_label    Texto legible de la modalidad.
     *   - from_basket       { id, date } del próximo viernes que le toca, o null.
     *   - to_candidates     Lista [{ id, date }] de viernes destino aceptados por
     *                       las reglas (excluyendo violaciones de deadline para
     *                       no descartar al candidato cuando el plazo expiró —
     *                       el front debe marcar el bloqueo aparte).
     *   - blocked           true si hay candidatos pero las reglas no los permiten.
     *   - deadline_passed   true si el deadline para cambios sobre el próximo
     *                       viernes ya pasó.
     */
    #[Route('/cambios-viernes/candidates/{partnerId}', name: 'delivery_shifts_candidates', methods: ['GET'], requirements: ['partnerId' => '\d+'])]
    public function shiftsCandidates(
        int $partnerId,
        PartnerRepository $partnerRepo,
        WeeklyBasketRepository $weeklyBasketRepo,
        PartnerBasketShareRepository $shareRepo,
        PartnerDeliveryShiftRepository $shiftRepo,
        DeliveryShiftCandidates $candidatesService,
        DeliveryShiftValidator $validator,
    ): JsonResponse {
        $partner = $partnerRepo->find($partnerId);
        if (!$partner instanceof Partner) {
            return new JsonResponse(['error' => 'Partner no encontrado.'], 404);
        }

        $share = $shareRepo->findActiveForPartner($partner);
        $modality = $share?->getBasketShare()?->getId();
        $modalityLabel = $share?->getBasketShare()?->getName() ?? 'Sin cesta activa';

        $next = $weeklyBasketRepo->findNextForPartner($partner);
        $from = $next?->getBasket();

        $fromPayload = $from !== null
            ? [
                'id' => $from->getId(),
                'date' => $from->getDate()->format('Y-m-d'),
                'status_id' => $next?->getWeeklyBasketStatus()?->getId(),
            ]
            : null;

        $existingShift = ($from !== null) ? $shiftRepo->findOutgoing($partner, $from) : null;
        $existingShiftPayload = $existingShift !== null
            ? [
                'id' => $existingShift->getId(),
                'to_date' => $existingShift->getToBasket()->getDate()->format('Y-m-d'),
                // CSRF dependiente del id, ya pre-calculado para que el front
                // pueda enviarlo sin tener que ir a otro endpoint.
                'csrf' => $this->container->get('security.csrf.token_manager')
                    ->getToken('delivery_shift_cancel_' . $existingShift->getId())->getValue(),
            ]
            : null;

        $toCandidates = [];
        $blocked = false;
        // El deadline jueves 23:59 es ahora bloqueante también para admin: si
        // la próxima cesta lo ha pasado, el front pintará el InfoCard "Plazo
        // cerrado" y no se ofrecerán acciones (apply/cancel/skip ya bloquean
        // en backend coherentemente).
        $deadlinePassed = ($from !== null) && !$this->isWithinPickupDeadline($from);

        // Si ya hay shift activo, no calculamos candidatos: el shift manda y
        // la UI sólo debe ofrecer "deshacer".
        if (!$deadlinePassed && $existingShift === null && $from !== null && $share !== null) {
            foreach ($candidatesService->findFor($share, $from) as $candidate) {
                $violations = $validator->validate($partner, $from, $candidate);
                if (empty($violations)) {
                    $toCandidates[] = ['id' => $candidate->getId(), 'date' => $candidate->getDate()->format('Y-m-d')];
                } else {
                    $blocked = true;
                }
            }
        }

        return new JsonResponse([
            'modality' => $modality,
            'modality_label' => $modalityLabel,
            'from_basket' => $fromPayload,
            'existing_shift' => $existingShiftPayload,
            'to_candidates' => $toCandidates,
            'blocked' => $blocked,
            'deadline_passed' => $deadlinePassed,
        ]);
    }

    #[Route('/cambios-viernes/aplicar', name: 'delivery_shifts_apply', methods: ['POST'])]
    public function shiftsApply(
        Request $request,
        PartnerRepository $partnerRepo,
        BasketRepository $basketRepo,
        WeeklyBasketRepository $weeklyBasketRepo,
        DeliveryShiftValidator $validator,
        DeliveryShiftApplier $applier,
    ): Response {
        $partner = $partnerRepo->find((int) $request->request->get('partner_id'));

        if (!$this->isCsrfTokenValid('delivery_shift_apply', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido.');
            return $this->redirectToRoute('delivery_shifts_index', $partner instanceof Partner ? ['partner' => $partner->getId()] : []);
        }

        $from = $basketRepo->find((int) $request->request->get('from_basket_id'));
        $to = $basketRepo->find((int) $request->request->get('to_basket_id'));

        if (!$partner instanceof Partner || !$from instanceof Basket || !$to instanceof Basket) {
            $this->addFlash('error', 'Faltan datos del cambio: socio, viernes origen o viernes destino.');
            return $this->redirectToRoute('delivery_shifts_index', $partner instanceof Partner ? ['partner' => $partner->getId()] : []);
        }

        // Guarda defensiva: si la WeeklyBasket origen está marcada como "no
        // recoge" (status=2), no se puede registrar un shift sobre ella —
        // habría que volver a recoger primero. La UI ya enmascara esta
        // acción pero el endpoint también la rechaza.
        $existingWb = $weeklyBasketRepo->findOneBy(['basket' => $from, 'partner' => $partner]);
        if ($existingWb !== null && $existingWb->getWeeklyBasketStatus()?->getId() === 2) {
            $this->addFlash('warning', 'Este socix ya tiene marcado que no recoge esta semana. Si quieres registrar el cambio, primero restituye la recogida.');
            return $this->redirectToRoute('delivery_shifts_index', $partner instanceof Partner ? ['partner' => $partner->getId()] : []);
        }

        // Todas las violaciones bloquean para admin: deadline (jueves 23:59,
        // el viernes ya se está cosechando) y equilibrio (pendiente de cierre
        // por administración). Si admin decide más adelante poder saltárselas,
        // se reactiva un canal explícito.
        $violations = $validator->validate($partner, $from, $to);
        if (!empty($violations)) {
            foreach ($violations as $v) {
                $this->addFlash('error', $v->message);
            }
            return $this->redirectToRoute('delivery_shifts_index', $partner instanceof Partner ? ['partner' => $partner->getId()] : []);
        }

        try {
            $applier->apply($partner, $from, $to, actor: 'gestor:' . $this->getUser()->getId());
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('delivery_shifts_index', $partner instanceof Partner ? ['partner' => $partner->getId()] : []);
        }

        $this->addFlash('notice', sprintf(
            'Cambio aplicado: %s recoge el %s en lugar del %s.',
            trim(($partner->getName() ?? '') . ' ' . ($partner->getSurname() ?? '')),
            $to->getDate()->format('d/m/Y'),
            $from->getDate()->format('d/m/Y'),
        ));
        return $this->redirectToRoute('delivery_shifts_index', ['partner' => $partner->getId()]);
    }

    /**
     * Toggle de "no recoge esta semana" desde admin para un socix concreto.
     * Es el equivalente admin del `panel_basket_skip_toggle`: cambia el
     * status de la próxima WeeklyBasket entre 1 (recoge) y 2 (no recoge),
     * con guarda anti-shift (si hay shift activo, no se permite el toggle:
     * desincronizaría la cascada). Bloquea fuera del deadline jueves 23:59:
     * el viernes ya se está cosechando, ningún cambio tiene sentido.
     */
    #[Route('/cambios-viernes/skip', name: 'delivery_shifts_skip_toggle', methods: ['POST'])]
    public function shiftsSkipToggle(
        Request $request,
        PartnerRepository $partnerRepo,
        WeeklyBasketRepository $weeklyBasketRepo,
        WeeklyBasketStatusRepository $statusRepo,
        PartnerDeliveryShiftRepository $shiftRepo,
        EntityManagerInterface $em,
    ): Response {
        $partner = $partnerRepo->find((int) $request->request->get('partner_id'));

        if (!$this->isCsrfTokenValid('delivery_shift_skip', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido.');
            return $this->redirectToRoute('delivery_shifts_index', $partner instanceof Partner ? ['partner' => $partner->getId()] : []);
        }

        if (!$partner instanceof Partner) {
            $this->addFlash('error', 'Falta el socix sobre el que actuar.');
            return $this->redirectToRoute('delivery_shifts_index', $partner instanceof Partner ? ['partner' => $partner->getId()] : []);
        }

        $next = $weeklyBasketRepo->findNextForPartner($partner);
        if ($next === null) {
            $this->addFlash('warning', 'Este socix no tiene una próxima cesta sobre la que actuar.');
            return $this->redirectToRoute('delivery_shifts_index', $partner instanceof Partner ? ['partner' => $partner->getId()] : []);
        }

        if ($shiftRepo->findOutgoing($partner, $next->getBasket()) !== null) {
            $this->addFlash('warning', 'Este socix tiene un cambio puntual activo: cancélalo primero si quieres alterar la próxima cesta.');
            return $this->redirectToRoute('delivery_shifts_index', $partner instanceof Partner ? ['partner' => $partner->getId()] : []);
        }

        if (!$this->isWithinPickupDeadline($next->getBasket())) {
            $this->addFlash('error', 'El plazo para esta semana ya terminó (jueves 23:59). No se puede registrar el cambio.');
            return $this->redirectToRoute('delivery_shifts_index', ['partner' => $partner->getId()]);
        }

        $currentId = $next->getWeeklyBasketStatus()?->getId();
        $partnerName = trim(($partner->getName() ?? '') . ' ' . ($partner->getSurname() ?? ''));
        $actor = 'gestor:' . $this->getUser()->getId();

        if ($currentId === 1) {
            $next->setWeeklyBasketStatus($statusRepo->find(2));
            $event = new PartnerEvent($partner, PartnerEvent::TYPE_BASKET_SKIP);
            $event->setActor($actor);
            $em->persist($event);
            $this->addFlash('notice', sprintf('%s no recoge la cesta de esta semana.', $partnerName));
        } elseif ($currentId === 2) {
            $next->setWeeklyBasketStatus($statusRepo->find(1));
            $event = new PartnerEvent($partner, PartnerEvent::TYPE_BASKET_UNSKIP);
            $event->setActor($actor);
            $em->persist($event);
            $this->addFlash('notice', sprintf('%s vuelve a recoger la cesta de esta semana.', $partnerName));
        } else {
            $this->addFlash('warning', 'Estado de la cesta inesperado, no se puede alternar desde aquí.');
        }

        $em->flush();
        return $this->redirectToRoute('delivery_shifts_index', ['partner' => $partner->getId()]);
    }

    /**
     * Deadline efectivo del Basket dado, calculado con el mismo criterio
     * que el panel y la regla DeadlineRule: el jueves 23:59 anterior al
     * viernes del Basket (con la salvedad de la fórmula `abs(N-4)` que
     * sólo da el jueves correcto cuando el Basket cae en viernes — caso
     * normal hoy).
     */
    private function isWithinPickupDeadline(Basket $basket): bool
    {
        $date = $basket->getDate();
        if ($date === null) {
            return true;
        }
        $pickup = \DateTimeImmutable::createFromMutable($date);
        $diffDays = abs((int) $pickup->format('N') - 4);
        $deadline = $pickup->setTime(23, 59, 0)->modify(sprintf('-%d days', $diffDays));
        return new \DateTimeImmutable('now') < $deadline;
    }

    #[Route('/cambios-viernes/{id}/cancelar', name: 'delivery_shifts_cancel', methods: ['POST'])]
    public function shiftsCancel(
        Request $request,
        PartnerDeliveryShift $shift,
        DeliveryShiftValidator $validator,
        DeliveryShiftApplier $applier,
    ): Response {
        $partnerId = $shift->getPartner()->getId();

        if (!$this->isCsrfTokenValid('delivery_shift_cancel_' . $shift->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido.');
            return $this->redirectToRoute('delivery_shifts_index', ['partner' => $partnerId]);
        }

        // Las reglas bloquean igual que en apply: deadline y equilibrio.
        // Mientras administración no defina el equilibrio definitivo, ninguna
        // se puede saltar — si admin necesita cancelar fuera de plazo,
        // hablamos antes de reabrir el bypass.
        $violations = $validator->validate($shift->getPartner(), $shift->getFromBasket(), $shift->getToBasket());
        if (!empty($violations)) {
            foreach ($violations as $v) {
                $this->addFlash('error', $v->message);
            }
            return $this->redirectToRoute('delivery_shifts_index', ['partner' => $partnerId]);
        }

        $applier->cancel($shift, actor: 'gestor:' . $this->getUser()->getId());

        $this->addFlash('notice', 'Cambio cancelado.');
        return $this->redirectToRoute('delivery_shifts_index', ['partner' => $partnerId]);
    }

    #[Route('/{basketId}', name: 'delivery_show', methods: ['GET'], requirements: ['basketId' => '\d+'])]
    public function show(
        #[MapEntity(id: 'basketId')] Basket $basket,
        WeeklyBasketGenerator $generator,
        DeliveryExceptionRepository $exceptions,
    ): Response {
        $report = $generator->generateForBasket($basket);
        $exception = $exceptions->findByFriday($basket->getDate());

        return $this->render('delivery/show.html.twig', [
            'basket' => $basket,
            'report' => $report,
            'exception' => $exception,
            'rows' => $this->flattenForTable($report),
        ]);
    }

    /**
     * Aplana las cinco colecciones del report en una sola lista plana para
     * la tabla con DataTables. Cada fila trae todo lo necesario para que
     * la plantilla no haga lógica.
     *
     * @return array<int, array{name:string, surname:string, modality:string, node:?string, group:?string, amount:int}>
     */
    private function flattenForTable(WeeklyDeliveryReport $report): array
    {
        $rows = [];
        $modalities = [
            ['weekly_partners', 'Semanal'],
            ['biweekly_partners', 'Quincenal'],
            ['monthly_partners', 'Mensual'],
            ['old_half_basket_partners', 'Media cesta'],
            ['only_egg_partners', 'Solo huevos'],
        ];

        foreach ($modalities as [$prop, $label]) {
            foreach ($report->$prop as $item) {
                $partner = $item->getPartner();
                $node = $partner->getWeeklyBasketGroup();
                $share = method_exists($item, 'getPartnerBasketShare') ? $item->getPartnerBasketShare() : null;
                $group = $share?->getDeliveryGroup();

                $rows[] = [
                    'name' => $partner->getName() ?? '',
                    'surname' => $partner->getSurname() ?? '',
                    'modality' => $label,
                    'node' => $node?->getName(),
                    'group' => $group,
                    'amount' => (int) $item->getAmount(),
                ];
            }
        }

        return $rows;
    }
}
