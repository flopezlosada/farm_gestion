<?php

namespace App\Controller;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerEvent;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Entity\WeeklyBasketStatus;
use App\Repository\BasketRepository;
use App\Repository\DeliveryExceptionRepository;
use App\Repository\NodeRepository;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Repository\PartnerRepository;
use App\Repository\PartnerBasketShareRepository;
use App\Repository\WeeklyBasketGroupRepository;
use App\Repository\WeeklyBasketItemRepository;
use App\Repository\WeeklyBasketRepository;
use App\Repository\WeeklyBasketStatusRepository;
use App\Service\Delivery\DeliveryModeResolver;
use App\Service\Delivery\DeliveryShiftApplier;
use App\Service\Delivery\DeliveryShiftValidator;
use App\Service\Delivery\ExtraBasketEditor;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Delivery\PickupRelocator;
use App\Service\Delivery\NodeDeliverySheet;
use App\Service\Delivery\WeeklyBasketGenerator;
use Dompdf\Dompdf;
use Dompdf\Options;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
 * Bajo el mismo prefijo se cuelga el registro de cambios de reparto
 * (DeliveryShift): una pantalla de CONSULTA por semana que lista los
 * cambios de día y los "no recoge" y permite deshacerlos. El ALTA de
 * cambios ya no vive aquí —se hace en el calendario de recogida de la
 * ficha del socix (partner_delivery_calendar)—.
 */
#[Route('/gestion/reparto')]
#[IsGranted('ROLE_GESTION_REPARTO')]
class DeliveryController extends AbstractController
{
    /** Nº de semanas (Baskets) visibles en la tira selectora del registro. */
    private const WEEK_STRIP_SIZE = 7;

    /**
     * Registro de cambios de reparto de una semana concreta (Basket): los
     * cambios de día (DeliveryShift) y los "no recoge" sin cambio de día.
     * NO registra cambios nuevos —eso vive en el calendario de recogida de
     * la ficha del socix (partner_delivery_calendar)—; es una pantalla de
     * consulta. La semana se elige con la tira de chips superior (?basket).
     *
     * El filtro es por el viernes-ciclo ORIGEN ("le tocaba"): un cambio
     * 5-jun→26-jun aparece en la semana del 5. El "Deshacer" solo se ofrece
     * en semanas futuras (un viernes pasado ya no se deshace).
     */
    #[Route('/historial-cambios', name: 'delivery_shifts_index', methods: ['GET'])]
    public function shiftsIndex(
        Request $request,
        PartnerDeliveryShiftRepository $shiftRepo,
        BasketRepository $basketRepo,
        WeeklyBasketRepository $weeklyBasketRepo,
        DeliveryExceptionRepository $exceptionRepo,
    ): Response {
        $today = new \DateTimeImmutable('today');

        // Semana seleccionada: ?basket=ID, o la próxima (date >= hoy), o la
        // última pasada si todas pasaron, para no quedarnos sin nada que pintar.
        $selected = $basketRepo->find($request->query->getInt('basket', 0))
            ?? $basketRepo->createQueryBuilder('b')
                ->where('b.date >= :today')->setParameter('today', $today->format('Y-m-d'))
                ->orderBy('b.date', 'ASC')->setMaxResults(1)->getQuery()->getOneOrNullResult()
            ?? $basketRepo->createQueryBuilder('b')
                ->orderBy('b.date', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();

        if (!$selected instanceof Basket) {
            throw $this->createNotFoundException('No hay semanas de reparto configuradas.');
        }

        // Cambios de día cuyo ORIGEN es la semana seleccionada.
        $shifts = $shiftRepo->createQueryBuilder('s')
            ->innerJoin('s.partner', 'p')
            ->where('s.fromBasket = :basket')
            ->setParameter('basket', $selected)
            ->orderBy('p.surname', 'ASC')->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        // "No recoge esta semana" (WeeklyBasket en status=2) que no son cascada
        // de un shift: si la WB está en status 2 porque la origina un
        // PartnerDeliveryShift, el shift ya aparece arriba y meter la WB
        // duplicaría.
        $skips = $weeklyBasketRepo->createQueryBuilder('wb')
            ->innerJoin('wb.partner', 'p')
            ->where('wb.basket = :basket')
            ->andWhere('wb.weekly_basket_status = :statusNoRecoge')
            ->andWhere('NOT EXISTS (
                SELECT 1 FROM \App\Entity\PartnerDeliveryShift s2
                WHERE s2.partner = wb.partner AND s2.fromBasket = wb.basket
            )')
            ->setParameter('basket', $selected)
            ->setParameter('statusNoRecoge', 2)
            ->orderBy('p.surname', 'ASC')->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        // Cierre de la semana: excepción global (todos los nodos) sin fecha de
        // traslado. Da contexto al registro —los cambios de esa semana suelen
        // ser consecuencia del cierre—.
        $globalException = $exceptionRepo->findGlobalForBasket($selected);
        $closure = ($globalException !== null && $globalException->isCancelled()) ? $globalException : null;

        return $this->render('delivery/shifts_index.html.twig', [
            'selected' => $selected,
            'is_future' => $selected->getDate() !== null && $selected->getDate() >= $today,
            'closure' => $closure,
            'week_strip' => $this->buildWeekStrip($basketRepo, $shiftRepo, $weeklyBasketRepo, $exceptionRepo, $selected, $today),
            'shifts' => $shifts,
            'skips' => $skips,
        ]);
    }

    /**
     * Tira de chips de semanas (Baskets) centrada en la seleccionada, con un
     * marcador en las que tienen algún cambio o salto registrado, para que el
     * registro se escanee de un vistazo. A diferencia de la mini-timeline de
     * reparto (por nodo, con fecha física), esta es global por viernes-ciclo.
     *
     * @return array{weeks: list<array{basket:Basket, active:bool, past:bool, has_activity:bool, closed:bool}>, prev_id:?int, next_id:?int}
     */
    private function buildWeekStrip(
        BasketRepository $basketRepo,
        PartnerDeliveryShiftRepository $shiftRepo,
        WeeklyBasketRepository $weeklyBasketRepo,
        DeliveryExceptionRepository $exceptionRepo,
        Basket $selected,
        \DateTimeImmutable $today,
    ): array {
        $selectedDate = $selected->getDate()->format('Y-m-d');
        $half = intdiv(self::WEEK_STRIP_SIZE - 1, 2);

        $previous = $basketRepo->createQueryBuilder('b')
            ->where('b.date < :date')->setParameter('date', $selectedDate)
            ->orderBy('b.date', 'DESC')->setMaxResults($half)
            ->getQuery()->getResult();
        $next = $basketRepo->createQueryBuilder('b')
            ->where('b.date > :date')->setParameter('date', $selectedDate)
            ->orderBy('b.date', 'ASC')->setMaxResults(self::WEEK_STRIP_SIZE - 1 - count($previous))
            ->getQuery()->getResult();

        /** @var Basket[] $window */
        $window = [...array_reverse($previous), $selected, ...$next];
        $ids = array_map(static fn (Basket $b): int => $b->getId(), $window);

        // Semanas con actividad: origen de algún shift o algún "no recoge".
        $withShift = array_column($shiftRepo->createQueryBuilder('s')
            ->select('DISTINCT IDENTITY(s.fromBasket) AS bid')
            ->where('s.fromBasket IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->getScalarResult(), 'bid');
        $withSkip = array_column($weeklyBasketRepo->createQueryBuilder('wb')
            ->select('DISTINCT IDENTITY(wb.basket) AS bid')
            ->where('wb.basket IN (:ids)')
            ->andWhere('wb.weekly_basket_status = :s2')->setParameter('s2', 2)
            ->setParameter('ids', $ids)
            ->getQuery()->getScalarResult(), 'bid');
        $active = array_flip(array_map('intval', [...$withShift, ...$withSkip]));

        // Semanas cerradas: excepción global (node IS NULL) de cierre (sin
        // fecha de traslado) — para pintarlas distintas en la tira.
        $closedIds = array_column($exceptionRepo->createQueryBuilder('e')
            ->select('DISTINCT IDENTITY(e.basket) AS bid')
            ->where('e.basket IN (:ids)')
            ->andWhere('e.node IS NULL')
            ->andWhere('e.shiftedDate IS NULL')
            ->setParameter('ids', $ids)
            ->getQuery()->getScalarResult(), 'bid');
        $closed = array_flip(array_map('intval', $closedIds));

        $weeks = array_map(
            static fn (Basket $b): array => [
                'basket' => $b,
                'active' => $b->getId() === $selected->getId(),
                'past' => $b->getDate() !== null && $b->getDate() < $today,
                'has_activity' => isset($active[$b->getId()]),
                'closed' => isset($closed[$b->getId()]),
            ],
            $window,
        );

        $prevId = $basketRepo->createQueryBuilder('b')
            ->where('b.date < :date')->setParameter('date', $window[0]->getDate()->format('Y-m-d'))
            ->orderBy('b.date', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult()?->getId();
        $lastDate = end($window)->getDate()->format('Y-m-d');
        $nextId = $basketRepo->createQueryBuilder('b')
            ->where('b.date > :date')->setParameter('date', $lastDate)
            ->orderBy('b.date', 'ASC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult()?->getId();

        return ['weeks' => $weeks, 'prev_id' => $prevId, 'next_id' => $nextId];
    }

    /**
     * Toggle de "no recoge" sobre la WeeklyBasket de un socix en una semana
     * (Basket) concreta: cambia el status entre 1 (recoge) y 2 (no recoge),
     * con guarda anti-shift (si hay shift activo, no se permite el toggle:
     * desincronizaría la cascada) y de deadline (jueves 23:59: el viernes ya
     * se está cosechando). Desde el registro sólo se usa para deshacer un
     * salto (2→1), pero el toggle se mantiene genérico.
     */
    #[Route('/historial-cambios/skip', name: 'delivery_shifts_skip_toggle', methods: ['POST'])]
    public function shiftsSkipToggle(
        Request $request,
        PartnerRepository $partnerRepo,
        BasketRepository $basketRepo,
        WeeklyBasketRepository $weeklyBasketRepo,
        WeeklyBasketStatusRepository $statusRepo,
        PartnerDeliveryShiftRepository $shiftRepo,
        EntityManagerInterface $em,
    ): Response {
        $partner = $partnerRepo->find((int) $request->request->get('partner_id'));
        $basket = $basketRepo->find((int) $request->request->get('basket_id'));
        $backToWeek = static fn (): array => $basket instanceof Basket ? ['basket' => $basket->getId()] : [];

        if (!$this->isCsrfTokenValid('delivery_shift_skip', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido.');
            return $this->redirectToRoute('delivery_shifts_index', $backToWeek());
        }

        if (!$partner instanceof Partner || !$basket instanceof Basket) {
            $this->addFlash('error', 'Faltan datos: socix o semana sobre la que actuar.');
            return $this->redirectToRoute('delivery_shifts_index', $backToWeek());
        }

        $wb = $weeklyBasketRepo->findOneBy(['partner' => $partner, 'basket' => $basket]);
        if ($wb === null) {
            $this->addFlash('warning', 'Este socix no tiene cesta esa semana sobre la que actuar.');
            return $this->redirectToRoute('delivery_shifts_index', $backToWeek());
        }

        if ($shiftRepo->findOutgoing($partner, $basket) !== null) {
            $this->addFlash('warning', 'Este socix tiene un cambio de día activo esa semana: cancélalo primero.');
            return $this->redirectToRoute('delivery_shifts_index', $backToWeek());
        }

        if (!$this->isWithinPickupDeadline($basket)) {
            $this->addFlash('error', 'El plazo para esa semana ya terminó (jueves 23:59). No se puede deshacer.');
            return $this->redirectToRoute('delivery_shifts_index', $backToWeek());
        }

        $currentId = $wb->getWeeklyBasketStatus()?->getId();
        $partnerName = trim(($partner->getName() ?? '') . ' ' . ($partner->getSurname() ?? ''));
        $actor = 'gestor:' . $this->getUser()->getId();

        if ($currentId === 1) {
            $wb->setWeeklyBasketStatus($statusRepo->find(2));
            $event = new PartnerEvent($partner, PartnerEvent::TYPE_BASKET_SKIP);
            $event->setActor($actor);
            $em->persist($event);
            $this->addFlash('notice', sprintf('%s no recoge la cesta de esa semana.', $partnerName));
        } elseif ($currentId === 2) {
            $wb->setWeeklyBasketStatus($statusRepo->find(1));
            $event = new PartnerEvent($partner, PartnerEvent::TYPE_BASKET_UNSKIP);
            $event->setActor($actor);
            $em->persist($event);
            $this->addFlash('notice', sprintf('%s vuelve a recoger la cesta de esa semana.', $partnerName));
        } else {
            $this->addFlash('warning', 'Estado de la cesta inesperado, no se puede alternar desde aquí.');
        }

        $em->flush();
        return $this->redirectToRoute('delivery_shifts_index', $backToWeek());
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

    /**
     * ¿El día de reparto ya pasó? Un Basket pasado sin reparto materializado se
     * deja vacío: nunca se proyecta hacia atrás (no tiene sentido dibujar un
     * reparto que ya debió ocurrir y del que no quedó constancia en piedra).
     */
    private function isPastBasket(Basket $basket): bool
    {
        $date = $basket->getDate();
        if ($date === null) {
            return false;
        }

        return \DateTimeImmutable::createFromMutable($date)->setTime(0, 0)
            < new \DateTimeImmutable('today');
    }

    /**
     * Contadores socios/grupos por nodo para las pestañas de la pantalla v2, con la
     * MISMA decisión piedra-vs-dibujo POR NODO que la tabla (vía DeliveryModeResolver):
     * cada nodo cuenta de su piedra si la tiene materializada, o de su proyección al
     * vuelo si le toca dibujar. Los nodos en EMPTY (no reparten, cancelado o pasado sin
     * piedra) se omiten y su pestaña cae a "sin reparto hoy". Así una semana mixta —un
     * nodo materializado y otro desplazado por un cierre, sin piedra— muestra en cada
     * pestaña lo mismo que enseña su tabla.
     *
     * @param Node[] $nodes
     * @return array<int, array{wbg:int, socios:int}>
     */
    private function deliveryCountsByNode(
        array $nodes,
        Basket $basket,
        DeliveryModeResolver $deliveryModeResolver,
        WeeklyBasketGenerator $generator,
        WeeklyBasketRepository $weeklyBasketRepo,
    ): array {
        $counts = [];
        foreach ($nodes as $n) {
            $mode = $deliveryModeResolver->mode($n, $basket);
            if ($mode === DeliveryModeResolver::STONE) {
                $stone = $weeklyBasketRepo->findForNodeAndBasket($n, $basket);
                $wbgIds = [];
                foreach ($stone as $wb) {
                    $wbg = $wb->getWeeklyBasketGroup();
                    if ($wbg !== null) {
                        $wbgIds[$wbg->getId()] = true;
                    }
                }
                $counts[$n->getId()] = ['socios' => count($stone), 'wbg' => count($wbgIds)];
            } elseif ($mode === DeliveryModeResolver::DRAW) {
                $deliveries = $generator->projectDeliveriesForNode($n, $basket);
                $wbgIds = [];
                foreach ($deliveries as $delivery) {
                    $wbg = $delivery['weeklyBasket']->getWeeklyBasketGroup();
                    if ($wbg !== null) {
                        $wbgIds[$wbg->getId()] = true;
                    }
                }
                $counts[$n->getId()] = ['socios' => count($deliveries), 'wbg' => count($wbgIds)];
            }
        }

        return $counts;
    }

    #[Route('/historial-cambios/{id}/cancelar', name: 'delivery_shifts_cancel', methods: ['POST'])]
    public function shiftsCancel(
        Request $request,
        PartnerDeliveryShift $shift,
        DeliveryShiftValidator $validator,
        DeliveryShiftApplier $applier,
    ): Response {
        // Volvemos a la semana origen del cambio (donde estaba la fila).
        $weekId = $shift->getFromBasket()->getId();

        if (!$this->isCsrfTokenValid('delivery_shift_cancel_' . $shift->getId(), (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido.');
            return $this->redirectToRoute('delivery_shifts_index', ['basket' => $weekId]);
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
            return $this->redirectToRoute('delivery_shifts_index', ['basket' => $weekId]);
        }

        $applier->cancel($shift, actor: 'gestor:' . $this->getUser()->getId());

        $this->addFlash('notice', 'Cambio cancelado.');
        return $this->redirectToRoute('delivery_shifts_index', ['basket' => $weekId]);
    }

    /**
     * Cesta extra puntual: fija a mano la composición de la entrega de un socio en la
     * semana que se está viendo. Cubre los dos casos reales —subir la cantidad de
     * cestas de quien ya recoge, o dar cesta(s) a quien no le tocaba—, ambos sobre una
     * única entrega (ver App\Service\Delivery\ExtraBasketEditor). Solo sobre semanas ya
     * generadas (es una operación sobre el reparto en marcha).
     *
     * @param Node    $node    Nodo cuya pantalla de reparto se está viendo (para volver).
     * @param Basket  $basket  Semana sobre la que se actúa.
     * @param Request $request Lleva partner_id, cestas, docenas, nota y el token CSRF.
     */
    #[Route(
        '/v2/nodo/{nodeId}/{basketId}/extra',
        name: 'delivery_set_extra',
        methods: ['POST'],
        requirements: ['nodeId' => '\d+', 'basketId' => '\d+']
    )]
    public function setExtra(
        #[MapEntity(id: 'nodeId')] Node $node,
        #[MapEntity(id: 'basketId')] Basket $basket,
        Request $request,
        PartnerRepository $partnerRepo,
        ExtraBasketEditor $extraBasketEditor,
    ): Response {
        $back = ['nodeId' => $node->getId(), 'basketId' => $basket->getId()];

        if (!$this->isCsrfTokenValid('delivery_set_extra', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido.');
            return $this->redirectToRoute('delivery_by_node', $back);
        }

        $partner = $partnerRepo->find((int) $request->request->get('partner_id'));
        if (!$partner instanceof Partner) {
            $this->addFlash('error', 'Falta el socix sobre el que actuar.');
            return $this->redirectToRoute('delivery_by_node', $back);
        }

        // El nodo destino debe tener el reparto YA generado esa semana (no piedra parcial
        // sobre una semana dibujada): lo valida ExtraBasketEditor por nodo y el catch de
        // abajo lo flashea. Ver relocate-semanas-futuras-dibujadas-debt.

        // Incremento que añade el gestor: cestas de verdura y/o docenas de huevos.
        $addAmounts = [
            BasketComponent::ID_VEGETABLES => (string) $request->request->get('cestas', '0'),
            BasketComponent::ID_EGGS => (string) $request->request->get('docenas', '0'),
        ];
        if ((float) $addAmounts[BasketComponent::ID_VEGETABLES] <= 0
            && (float) $addAmounts[BasketComponent::ID_EGGS] <= 0) {
            $this->addFlash('warning', 'Indica al menos una cantidad de cestas o de huevos a añadir.');
            return $this->redirectToRoute('delivery_by_node', $back);
        }

        $note = trim((string) $request->request->get('nota', '')) ?: null;
        $partnerName = trim(($partner->getName() ?? '') . ' ' . ($partner->getSurname() ?? ''));

        try {
            $extraBasketEditor->addToDelivery(
                $partner,
                $basket,
                $addAmounts,
                $note,
                'gestor:' . $this->getUser()->getId(),
            );
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('delivery_by_node', $back);
        }

        $this->addFlash('notice', sprintf('Entrega de %s actualizada para esa semana.', $partnerName));
        return $this->redirectToRoute('delivery_by_node', $back);
    }

    /**
     * Traslado puntual de lugar: un socio del listado recoge la cesta de ESA semana en
     * otro nodo/grupo. Delega en PickupRelocator (cambia el grupo del WeeklyBasket de la
     * semana y recalcula su fecha física); el listado del destino lo absorbe y el de este
     * nodo lo excluye automáticamente. Solo mueve una cesta que ya existe esa semana.
     *
     * @param Node    $node    Nodo de la pantalla (origen, para volver).
     * @param Basket  $basket  Semana sobre la que se actúa.
     * @param Request $request Lleva partner_id, group_id y el token CSRF.
     */
    #[Route(
        '/v2/nodo/{nodeId}/{basketId}/recoger-en-otro-nodo',
        name: 'delivery_relocate',
        methods: ['POST'],
        requirements: ['nodeId' => '\d+', 'basketId' => '\d+']
    )]
    public function relocatePickup(
        #[MapEntity(id: 'nodeId')] Node $node,
        #[MapEntity(id: 'basketId')] Basket $basket,
        Request $request,
        PartnerRepository $partnerRepo,
        WeeklyBasketGroupRepository $groupRepo,
        PickupRelocator $relocator,
    ): Response {
        $back = ['nodeId' => $node->getId(), 'basketId' => $basket->getId()];

        if (!$this->isCsrfTokenValid('delivery_relocate', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido.');
            return $this->redirectToRoute('delivery_by_node', $back);
        }

        $partner = $partnerRepo->find((int) $request->request->get('partner_id'));
        $group = $groupRepo->find((int) $request->request->get('group_id'));
        if (!$partner instanceof Partner || !$group instanceof WeeklyBasketGroup) {
            $this->addFlash('error', 'Faltan datos: el socix o el grupo de destino.');
            return $this->redirectToRoute('delivery_by_node', $back);
        }

        $partnerName = trim(($partner->getName() ?? '') . ' ' . ($partner->getSurname() ?? ''));

        try {
            $override = $relocator->relocate($partner, $basket, $group, 'gestor:' . $this->getUser()->getId());
        } catch (\LogicException $e) {
            $this->addFlash('warning', $e->getMessage());
            return $this->redirectToRoute('delivery_by_node', $back);
        }

        // relocate() devuelve null si el destino era el propio nodo de casa: lo gestionó
        // como vuelta al patrón (returnHome). El mensaje refleja cada caso.
        $this->addFlash('notice', $override === null
            ? sprintf('%s vuelve a recoger en su nodo esa semana.', $partnerName)
            : sprintf('%s recogerá esa semana en "%s".', $partnerName, $group->getName()));
        return $this->redirectToRoute('delivery_by_node', $back);
    }

    /**
     * NODOS a los que el listado de este nodo puede trasladar un socio EN ESTA SEMANA: los
     * OTROS nodos que REPARTEN ese basket (la acción es "recoger en otro nodo"). Una opción
     * por nodo (no por grupo), etiqueta = nombre del nodo. El `id` es un grupo
     * REPRESENTATIVO del nodo destino: el relocado aparece en la sección "Trasladados" del
     * destino (NodeDeliverySheet), así que el grupo concreto es invisible; solo hace falta
     * uno del nodo para anclar el WeeklyBasket. Funciona en semanas dibujadas o generadas
     * (el override vive sobre la proyección). Ver docs/redesign/modelo-overrides-reparto.md.
     *
     * @param Node                        $node             Nodo actual de la pantalla.
     * @param Basket                      $basket           Semana del listado.
     * @param Node[]                      $allNodes         Todos los nodos (ya cargados por byNode).
     * @param NodeDeliveryDate            $nodeDeliveryDate
     * @param WeeklyBasketGroupRepository $groupRepo
     * @return list<array{id:int, label:string}> Ordenados por nombre de nodo.
     */
    private function relocateTargetGroups(
        Node $node,
        Basket $basket,
        array $allNodes,
        NodeDeliveryDate $nodeDeliveryDate,
        WeeklyBasketGroupRepository $groupRepo,
    ): array {
        $options = [];
        foreach ($allNodes as $other) {
            if ($other->getId() === $node->getId() || !$nodeDeliveryDate->deliversInBasket($basket, $other)) {
                continue;
            }
            $representative = $groupRepo->findOneBy(['node' => $other], ['name' => 'ASC']);
            if ($representative === null) {
                continue; // nodo sin grupos
            }
            $options[] = ['id' => $representative->getId(), 'label' => $other->getName()];
        }
        usort($options, static fn (array $a, array $b) => strcmp($a['label'], $b['label']));

        return $options;
    }

    /**
     * Entry point del menú lateral: calcula el primer Node (por id ASC) y
     * el próximo Basket (date >= today, o el último pasado si todos han
     * pasado) y redirige a la pantalla concreta. Permite enlazar desde el
     * menú sin hardcodear ids que cambian con el tiempo.
     */
    #[Route('/v2', name: 'delivery_by_node_default', methods: ['GET'])]
    public function byNodeDefault(
        NodeRepository $nodeRepo,
        BasketRepository $basketRepo,
    ): Response {
        $firstNode = $nodeRepo->createQueryBuilder('n')->orderBy('n.id', 'ASC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if ($firstNode === null) {
            throw $this->createNotFoundException('No hay nodos configurados todavía.');
        }

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $nextBasket = $basketRepo->createQueryBuilder('b')
            ->where('b.date >= :today')
            ->setParameter('today', $today)
            ->orderBy('b.date', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        // Fallback: si todos los baskets han pasado, ir al último para no 404.
        if ($nextBasket === null) {
            $nextBasket = $basketRepo->createQueryBuilder('b')
                ->orderBy('b.date', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        if ($nextBasket === null) {
            throw $this->createNotFoundException('No hay baskets configurados todavía.');
        }

        return $this->redirectToRoute('delivery_by_node', [
            'nodeId' => $firstNode->getId(),
            'basketId' => $nextBasket->getId(),
        ]);
    }

    /**
     * Listado de reparto v2 (sub-fase 8.8c): tabla agrupada Nodo > WBG > Modalidad.
     * Reemplaza la pantalla legacy partner_basket_share_generate_weekly.
     */
    #[Route(
        '/v2/nodo/{nodeId}/{basketId}',
        name: 'delivery_by_node',
        methods: ['GET'],
        requirements: ['nodeId' => '\d+', 'basketId' => '\d+']
    )]
    public function byNode(
        #[MapEntity(id: 'nodeId')] Node $node,
        #[MapEntity(id: 'basketId')] Basket $basket,
        NodeRepository $nodeRepo,
        BasketRepository $basketRepo,
        WeeklyBasketRepository $weeklyBasketRepo,
        WeeklyBasketItemRepository $weeklyBasketItemRepo,
        PartnerBasketShareRepository $partnerBasketShareRepo,
        WeeklyBasketGroupRepository $weeklyBasketGroupRepo,
        NodeDeliveryDate $nodeDeliveryDate,
        DeliveryExceptionRepository $deliveryExceptionRepo,
        WeeklyBasketGenerator $generator,
        DeliveryModeResolver $deliveryModeResolver,
        Request $request,
    ): Response {
        $orden = $request->query->get('orden', 'grupo');
        if (!in_array($orden, ['grupo', 'modalidad', 'alfabetico'], true)) {
            $orden = 'grupo';
        }

        $allNodes = $nodeRepo->findBy([], ['name' => 'ASC']);

        // ¿Este nodo entrega en este Basket? Captura cadencia (un quincenal
        // fuera de fase devuelve false) y excepciones de calendario por nodo
        // o global de admin (cancelación o traslado). Sustituye al hardcode
        // de festivos viernes que existía hasta 8.8e.
        $nodeDelivers = $nodeDeliveryDate->deliversInBasket($basket, $node);

        // Excepción global de cancelación del ciclo entero: si admin cancela
        // todo el viernes (excepción global sin shifted_date), no hay reparto
        // que dibujar para ningún nodo.
        $globalException = $deliveryExceptionRepo->findGlobalForBasket($basket);
        $basketGloballyCancelled = $globalException !== null && $globalException->isCancelled();

        // Piedra vs dibujo (rework de materialización tardía), decisión POR NODO
        // delegada en DeliveryModeResolver: STONE = leer lo materializado de este nodo;
        // DRAW = dibujar al vuelo desde la proyección SIN congelar (semana futura o sin
        // piedra que sí reparte); EMPTY = no reparte, cancelado, o pasado sin piedra.
        // Por nodo y no global del basket: un cierre que desplaza la cadencia de un nodo
        // quincenal (Cascorro/Midori) lo deja sin piedra en la semana nueva aunque OTRO
        // nodo (Torremocha) siga materializado; con la regla global se leía de su piedra
        // vieja/vacía en vez de dibujarse. Ver vistas-reparto-dibujar-debt.
        $mode = $deliveryModeResolver->mode($node, $basket);

        // amountsByPartner[partner.id] = [componentId => cantidad]: cestas físicas
        // (verdura) y docenas (huevos) por entrega. Vienen de los WeeklyBasketItem
        // materializados (piedra) o de la composición proyectada al vuelo (dibujo).
        // Clave por partner.id, no wb.id: el WeeklyBasket dibujado es transitorio y
        // no tiene id, y un socio tiene una sola entrega por nodo+día.
        $amountsByPartner = [];

        if ($mode === DeliveryModeResolver::STONE) {
            $weeklyBaskets = $weeklyBasketRepo->findForNodeAndBasket($node, $basket);
            $byWbId = $weeklyBasketItemRepo->componentAmountsFor($weeklyBaskets);
            foreach ($weeklyBaskets as $wb) {
                $pid = $wb->getPartner()?->getId();
                if ($pid !== null) {
                    $amountsByPartner[$pid] = $byWbId[$wb->getId()] ?? [];
                }
            }
        } elseif ($mode === DeliveryModeResolver::DRAW) {
            $weeklyBaskets = [];
            foreach ($generator->projectDeliveriesForNode($node, $basket) as $delivery) {
                $wb = $delivery['weeklyBasket'];
                $weeklyBaskets[] = $wb;
                $pid = $wb->getPartner()?->getId();
                if ($pid === null) {
                    continue;
                }
                $amounts = [];
                foreach ($delivery['items'] as $item) {
                    $amounts[$item['component']->getId()] = (float) $item['amount'];
                }
                $amountsByPartner[$pid] = $amounts;
            }
        } else {
            $weeklyBaskets = [];
        }

        // Fecha física de reparto del nodo en este Basket: para Torremocha
        // (semanal viernes) coincide con basket.date; para Madrid (miércoles)
        // cae otro día; null si el nodo es quincenal y este Basket no le toca.
        $physicalDate = $nodeDeliveryDate->physicalDateFor($basket, $node);

        // Mini-timeline del header: 1 ciclo pasado, el actual y 3 futuros.
        // Cada entrada lleva el día REAL del nodo (miércoles para Madrid,
        // viernes para Torremocha) y si el nodo reparte esa semana, para
        // atenuar las semanas vacías de los nodos quincenales.
        //
        // La tira se ancla SIEMPRE en el día seleccionado ($basket), no en
        // ?ref=, para que al pinchar un día (incluso tras navegar con las
        // flechas a una ventana lejana) la tira quede centrada en ese día.
        // El ?ref= sólo lo usa la navegación AJAX de las flechas, que
        // desplaza la ventana sin cambiar el día seleccionado.
        $timelineData = $this->buildTimelineData(
            $basketRepo, $nodeDeliveryDate, $node, $basket,
            $basket->getId(), 'center',
        );

        // Estructura unificada de secciones para el render, según el modo de
        // orden elegido (por grupo de recogida / por modalidad / lista
        // alfabética). Formato común: secciones con subgrupos y filas, para
        // que el template tenga una sola rama de pintado.
        //
        // Los TRASLADADOS (override de nodo: recogen aquí esta semana viniendo de
        // OTRO nodo) se apartan ANTES de agrupar y van a su propia sección al final
        // —igual que en el PDF (NodeDeliverySheet)— para que no se mezclen con los
        // socios de casa del grupo destino. Siguen contando en los totales del nodo
        // (sí reparten aquí hoy). La detección es pura en WeeklyBasket::getRelocatedFromGroup.
        $relocated = array_values(array_filter(
            $weeklyBaskets,
            static fn (WeeklyBasket $wb): bool => $wb->getRelocatedFromGroup() !== null,
        ));
        $regular = array_values(array_filter(
            $weeklyBaskets,
            static fn (WeeklyBasket $wb): bool => $wb->getRelocatedFromGroup() === null,
        ));
        $sections = $this->buildSections($regular, $orden);
        if ($relocated !== []) {
            $sections[] = $this->relocatedSection($relocated);
        }

        // Mapas por partner.id para el render (clave común a piedra y dibujo):
        //  - pbs: PartnerBasketShare activo en la fecha (para cohorte y huevos).
        //    En el dibujo el WB transitorio ya trae su PBS; en piedra la propiedad
        //    transient es null al recuperar, así que se consulta.
        //  - cestas/docenas/lleva-huevos: de la composición ya calculada
        //    ($amountsByPartner), materializada o proyectada según el camino.
        $pbsByPartnerId = [];
        $cestasByPartnerId = [];
        $dozensByPartnerId = [];
        $eggDeliveryMap = [];
        foreach ($weeklyBaskets as $wb) {
            $partner = $wb->getPartner();
            if ($partner === null) {
                continue;
            }
            $pid = $partner->getId();
            $pbsByPartnerId[$pid] = $wb->getPartnerBasketShare()
                ?? $partnerBasketShareRepo->findActiveForPartner($partner, $basket->getDate());
            $amounts = $amountsByPartner[$pid] ?? [];
            $cestasByPartnerId[$pid] = $amounts[BasketComponent::ID_VEGETABLES] ?? 0.0;
            $dozensByPartnerId[$pid] = $amounts[BasketComponent::ID_EGGS] ?? 0.0;
            // "Lleva huevos" = existe línea de huevos para esta entrega.
            $eggDeliveryMap[$pid] = isset($amounts[BasketComponent::ID_EGGS]);
        }

        // node_active_counts[nodeId] = {wbg, socios}: conteo de reparto de ESE día por
        // nodo, para las pestañas. La decisión piedra-vs-dibujo es POR NODO, igual que
        // la tabla: cada nodo cuenta de su piedra si la tiene, o de su proyección si le
        // toca dibujar. Así una semana mixta (un nodo materializado, otro desplazado por
        // un cierre y sin piedra) muestra en cada pestaña lo mismo que su tabla.
        $nodeActiveCounts = $this->deliveryCountsByNode(
            $allNodes,
            $basket,
            $deliveryModeResolver,
            $generator,
            $weeklyBasketRepo,
        );

        return $this->render('delivery/by_node.html.twig', [
            'node' => $node,
            'basket' => $basket,
            'physical_date' => $physicalDate,
            'node_delivers' => $nodeDelivers,
            'basket_globally_cancelled' => $basketGloballyCancelled,
            'all_nodes' => $allNodes,
            'node_active_counts' => $nodeActiveCounts,
            'timeline' => $timelineData['timeline'],
            'timeline_nav' => $timelineData['nav'],
            'ref_id' => $timelineData['ref_id'],
            'orden' => $orden,
            'sections' => $sections,
            'pbs_by_partner_id' => $pbsByPartnerId,
            'egg_delivery_map' => $eggDeliveryMap,
            'cestas_by_partner_id' => $cestasByPartnerId,
            'dozens_by_partner_id' => $dozensByPartnerId,
            'relocate_groups' => $this->relocateTargetGroups($node, $basket, $allNodes, $nodeDeliveryDate, $weeklyBasketGroupRepo),
            'totals' => $this->computeTotals($weeklyBaskets, $cestasByPartnerId, $dozensByPartnerId),
        ]);
    }

    /**
     * Fragmento HTML de la mini-timeline para repintarla por AJAX al pulsar
     * las flechas, sin recargar la página entera (el listado no cambia). El
     * `mode` (back/ahead) desplaza la ventana solapando un solo día.
     */
    #[Route(
        '/v2/nodo/{nodeId}/{basketId}/timeline',
        name: 'delivery_by_node_timeline',
        methods: ['GET'],
        requirements: ['nodeId' => '\d+', 'basketId' => '\d+']
    )]
    public function byNodeTimeline(
        #[MapEntity(id: 'nodeId')] Node $node,
        #[MapEntity(id: 'basketId')] Basket $basket,
        BasketRepository $basketRepo,
        NodeDeliveryDate $nodeDeliveryDate,
        Request $request,
    ): Response {
        $mode = $request->query->get('mode', 'center');
        if (!in_array($mode, ['center', 'back', 'ahead'], true)) {
            $mode = 'center';
        }
        $orden = $request->query->get('orden', 'grupo');
        if (!in_array($orden, ['grupo', 'modalidad', 'alfabetico'], true)) {
            $orden = 'grupo';
        }

        $data = $this->buildTimelineData(
            $basketRepo, $nodeDeliveryDate, $node, $basket,
            $request->query->getInt('ref', 0), $mode,
        );

        return $this->render('delivery/_timeline.html.twig', [
            'node' => $node,
            'basket' => $basket,
            'timeline' => $data['timeline'],
            'timeline_nav' => $data['nav'],
            'ref_id' => $data['ref_id'],
            'orden' => $orden,
        ]);
    }

    /**
     * Estructura unificada de secciones para el template, según el modo de
     * orden. Formato común: cada sección tiene un título (o null en lista
     * plana) y una lista de subgrupos; cada subgrupo tiene filas y, opcional,
     * un label (la modalidad, cuando se agrupa por grupo). Una sola forma de
     * pintar para los tres modos.
     *
     * @param WeeklyBasket[] $weeklyBaskets
     * @param string $orden grupo|modalidad|alfabetico
     * @return list<array{title:?string, subgroups: list<array{bs_id:?int, label:?string, rows: WeeklyBasket[]}>}>
     */
    private function buildSections(array $weeklyBaskets, string $orden): array
    {
        return match ($orden) {
            'modalidad'  => $this->sectionsByModality($weeklyBaskets),
            'alfabetico' => $this->sectionsFlat($weeklyBaskets),
            default      => $this->sectionsByGroup($weeklyBaskets),
        };
    }

    /**
     * Secciones por grupo de recogida (WBG), con subgrupos por modalidad.
     * Confía en el orden del query (wbg.name, basket_share.id, partner.name).
     *
     * @param WeeklyBasket[] $weeklyBaskets
     * @return list<array{title:?string, subgroups: list<array{bs_id:?int, label:?string, rows: WeeklyBasket[]}>}>
     */
    private function sectionsByGroup(array $weeklyBaskets): array
    {
        $byWbg = [];
        foreach ($weeklyBaskets as $wb) {
            $wbg = $wb->getWeeklyBasketGroup();
            $bs = $wb->getBasketShare();
            if ($wbg === null || $bs === null) {
                continue;
            }
            $byWbg[$wbg->getId()] ??= ['title' => $wbg->getName(), 'subs' => []];
            $byWbg[$wbg->getId()]['subs'][$bs->getId()] ??= ['bs_id' => $bs->getId(), 'label' => $bs->getName(), 'rows' => []];
            $byWbg[$wbg->getId()]['subs'][$bs->getId()]['rows'][] = $wb;
        }

        return array_map(
            fn (array $g): array => ['title' => $g['title'], 'subgroups' => array_values($g['subs'])],
            array_values($byWbg),
        );
    }

    /**
     * Secciones por modalidad de cesta; dentro, subgrupos por grupo de
     * recogida (segundo orden), y dentro filas por nombre. Útil para preparar
     * todas las cestas de una modalidad agrupadas por punto de entrega.
     *
     * Caso especial modalidad compartida (bs.id=4): el WBG es subdivisión
     * logística interna, pero el punto de recogida real es el nodo (la
     * pantalla ya está filtrada por nodo). Por eso no se subdivide por WBG
     * y se aplica un pase que pega cada pareja con su sharePartner para
     * que las dos filas salgan seguidas.
     *
     * @param WeeklyBasket[] $weeklyBaskets
     * @return list<array{title:?string, subgroups: list<array{bs_id:?int, label:?string, rows: WeeklyBasket[]}>}>
     */
    private function sectionsByModality(array $weeklyBaskets): array
    {
        $byMod = [];
        foreach ($weeklyBaskets as $wb) {
            $bs = $wb->getBasketShare();
            if ($bs === null) {
                continue;
            }
            $byMod[$bs->getId()] ??= ['title' => $bs->getName(), 'wbs' => []];
            $byMod[$bs->getId()]['wbs'][] = $wb;
        }
        ksort($byMod);

        $result = [];
        foreach ($byMod as $bsId => $m) {
            if ($bsId === 4) {
                $rows = $m['wbs'];
                usort($rows, $this->compareByDisplayName(...));
                [$rows, $pairEnds] = $this->pinSharedPairs($rows);
                $result[] = [
                    'title' => $m['title'],
                    'subgroups' => [[
                        'bs_id' => null,
                        'label' => null,
                        'rows' => $rows,
                        'pair_end_ids' => $pairEnds,
                    ]],
                ];
                continue;
            }

            $subs = [];
            foreach ($m['wbs'] as $wb) {
                $wbg = $wb->getWeeklyBasketGroup();
                $groupId = $wbg?->getId() ?? 0;
                $subs[$groupId] ??= [
                    'bs_id' => null,
                    'label' => $wbg?->getName() ?? 'Sin grupo',
                    'rows' => [],
                ];
                $subs[$groupId]['rows'][] = $wb;
            }
            uasort($subs, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
            foreach ($subs as &$sub) {
                usort($sub['rows'], $this->compareByDisplayName(...));
            }
            unset($sub);

            $result[] = ['title' => $m['title'], 'subgroups' => array_values($subs)];
        }

        return $result;
    }

    /**
     * Pega cada pareja de cesta compartida (Partner.sharePartner) consigo.
     * Mantiene el orden alfabético del "primero" de cada pareja y coloca
     * al compañero justo detrás. Si el compañero no está en la lista
     * (otro nodo, skip, shift fuera), la fila queda suelta en su sitio.
     *
     * Devuelve también el set de partner.id que cierran un grupo (la fila del
     * compañero en parejas de 2, o la propia fila en huérfanos de 1) para
     * que el template pinte un separador entre grupos. Clave por partner.id
     * (no wb.id): vale igual para WeeklyBasket de piedra y transitorios de dibujo.
     *
     * @param WeeklyBasket[] $rows ordenados alfabéticamente
     * @return array{0: WeeklyBasket[], 1: array<int, true>}
     */
    private function pinSharedPairs(array $rows): array
    {
        $byPartnerId = [];
        foreach ($rows as $wb) {
            $partner = $wb->getPartner();
            if ($partner !== null) {
                $byPartnerId[$partner->getId()] = $wb;
            }
        }

        $result = [];
        $used = [];
        $pairEnds = [];
        foreach ($rows as $wb) {
            $partnerId = $wb->getPartner()?->getId();
            if ($partnerId !== null && isset($used[$partnerId])) {
                continue;
            }
            $result[] = $wb;
            if ($partnerId !== null) {
                $used[$partnerId] = true;
            }

            $mate = $wb->getPartner()?->getSharePartner();
            $mateWb = $mate !== null ? ($byPartnerId[$mate->getId()] ?? null) : null;
            if ($mateWb !== null && !isset($used[$mate->getId()])) {
                $result[] = $mateWb;
                $used[$mate->getId()] = true;
                $pairEnds[$mate->getId()] = true;
            } elseif ($partnerId !== null) {
                $pairEnds[$partnerId] = true;
            }
        }
        return [$result, $pairEnds];
    }

    /**
     * Sección "Trasladados" de la pantalla v2: los socios que esta semana recogen en
     * este nodo viniendo de OTRO (override de nodo). Va al final del listado, como en
     * el PDF ({@see NodeDeliverySheet}), en una lista plana ordenada por nombre. Cada
     * fila se marca "de {grupo de casa}" en _row.html.twig vía
     * {@see WeeklyBasket::getRelocatedFromGroup}, así que aquí no hace falta subdividir.
     *
     * @param WeeklyBasket[] $relocated Entregas trasladadas (todas con getRelocatedFromGroup() != null).
     * @return array{title:string, subgroups: list<array{bs_id:?int, label:?string, rows: WeeklyBasket[]}>}
     */
    private function relocatedSection(array $relocated): array
    {
        usort($relocated, $this->compareByDisplayName(...));

        return [
            'title' => 'Trasladados',
            'subgroups' => [['bs_id' => null, 'label' => null, 'rows' => $relocated]],
        ];
    }

    /**
     * Una sola sección sin título con todas las filas ordenadas por nombre.
     *
     * @param WeeklyBasket[] $weeklyBaskets
     * @return list<array{title:?string, subgroups: list<array{bs_id:?int, label:?string, rows: WeeklyBasket[]}>}>
     */
    private function sectionsFlat(array $weeklyBaskets): array
    {
        $rows = $weeklyBaskets;
        usort($rows, $this->compareByDisplayName(...));

        return [['title' => null, 'subgroups' => [['bs_id' => null, 'label' => null, 'rows' => $rows]]]];
    }

    /**
     * Comparador de WeeklyBasket por el nombre de reparto del socio (natural,
     * insensible a mayúsculas).
     */
    private function compareByDisplayName(WeeklyBasket $a, WeeklyBasket $b): int
    {
        return strnatcasecmp(
            $a->getPartner()?->getNameForDelivery() ?? '',
            $b->getPartner()?->getNameForDelivery() ?? '',
        );
    }

    /**
     * Totales del nodo+día para el resumen destacado de la pantalla.
     *
     * @param WeeklyBasket[] $weeklyBaskets
     * @param array<int,float> $cestasByPartnerId Cestas físicas por entrega (ítem verdura), clave partner.id.
     * @param array<int,float> $dozensByPartnerId Docenas por entrega (ítem huevos), clave partner.id.
     * @return array{socios:int, grupos:int, cestas:float, docenas:float, con_huevos:int}
     */
    private function computeTotals(array $weeklyBaskets, array $cestasByPartnerId, array $dozensByPartnerId): array
    {
        $cestas = 0.0;
        $docenas = 0.0;
        $conHuevos = 0;
        $grupos = [];
        foreach ($weeklyBaskets as $wb) {
            $pid = $wb->getPartner()?->getId();
            // Cestas físicas y docenas salen de la composición materializada o
            // proyectada: la ponderación ½ de las compartidas y el 0 de "solo
            // huevos" ya están estampados en el ítem (el composer aplicó el peso
            // de la modalidad), así que aquí solo sumamos.
            $cestas += $cestasByPartnerId[$pid] ?? 0.0;
            $wbg = $wb->getWeeklyBasketGroup();
            if ($wbg !== null) {
                $grupos[$wbg->getId()] = true;
            }
            $dozens = $dozensByPartnerId[$pid] ?? 0.0;
            if ($dozens > 0) {
                $conHuevos++;
                $docenas += $dozens;
            }
        }
        return [
            'socios' => count($weeklyBaskets),
            'grupos' => count($grupos),
            'cestas' => $cestas,
            'docenas' => $docenas,
            'con_huevos' => $conHuevos,
        ];
    }

    /**
     * Basket de referencia para anclar la mini-timeline: el indicado por
     * `?ref=` si es válido, o el más próximo a hoy (esta semana) como
     * fallback. La carga de página pasa el día seleccionado como ref para
     * centrar la tira en él; la navegación AJAX de las flechas pasa el extremo
     * de la ventana para desplazarla sin cambiar el día seleccionado.
     */
    private function resolveTimelineAnchor(BasketRepository $repo, int $refId): ?Basket
    {
        if ($refId > 0 && ($ref = $repo->find($refId)) instanceof Basket) {
            return $ref;
        }

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        return $repo->createQueryBuilder('b')
            ->where('b.date >= :today')
            ->setParameter('today', $today)
            ->orderBy('b.date', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
            ?? $repo->createQueryBuilder('b')
                ->orderBy('b.date', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
    }

    /** Nº de viernes visibles en la mini-timeline. */
    private const TIMELINE_SIZE = 5;

    /**
     * Ventana de la mini-timeline (5 viernes) alrededor del ancla, según el
     * modo de navegación:
     *  - 'center': 1 anterior + ancla + 3 posteriores (carga inicial / día).
     *  - 'back'  : 4 anteriores + ancla (ancla en el extremo derecho). Lo usa
     *              la flecha ‹: el día más antiguo visible pasa a ser el más
     *              reciente de la nueva ventana (solape de 1).
     *  - 'ahead' : ancla + 4 posteriores (ancla en el extremo izquierdo). Lo
     *              usa la flecha ›.
     *
     * @return Basket[]
     */
    private function surroundingBaskets(BasketRepository $repo, Basket $anchor, string $mode = 'center'): array
    {
        $anchorDate = $anchor->getDate()?->format('Y-m-d');
        if ($anchorDate === null) {
            return [$anchor];
        }

        [$nPrev, $nNext] = match ($mode) {
            'back'  => [self::TIMELINE_SIZE - 1, 0],
            'ahead' => [0, self::TIMELINE_SIZE - 1],
            default => [1, self::TIMELINE_SIZE - 2],
        };

        $previous = $nPrev > 0 ? $repo->createQueryBuilder('b')
            ->where('b.date < :date')->setParameter('date', $anchorDate)
            ->orderBy('b.date', 'DESC')->setMaxResults($nPrev)
            ->getQuery()->getResult() : [];

        $next = $nNext > 0 ? $repo->createQueryBuilder('b')
            ->where('b.date > :date')->setParameter('date', $anchorDate)
            ->orderBy('b.date', 'ASC')->setMaxResults($nNext)
            ->getQuery()->getResult() : [];

        return [...array_reverse($previous), $anchor, ...$next];
    }

    /**
     * Arma los datos de la mini-timeline (chips + flechas) para un nodo y un
     * día seleccionado. Compartido por la página completa y por el fragmento
     * AJAX que repintan las flechas.
     *
     * @return array{timeline: list<array{basket:Basket, date:\DateTimeInterface, delivers:bool, active:bool, past:bool}>, nav: array{prev_ref:?int, next_ref:?int, has_prev:bool, has_next:bool}, ref_id:?int}
     */
    private function buildTimelineData(
        BasketRepository $basketRepo,
        NodeDeliveryDate $nodeDeliveryDate,
        Node $node,
        Basket $selected,
        int $refId,
        string $mode,
    ): array {
        $anchor = $this->resolveTimelineAnchor($basketRepo, $refId);
        $window = $anchor !== null ? $this->surroundingBaskets($basketRepo, $anchor, $mode) : [];
        $today = new \DateTimeImmutable('today');

        $timeline = array_map(
            fn (Basket $b): array => [
                'basket' => $b,
                // Fecha física del nodo aplicando excepciones de calendario
                // (festivo trasladado: 1-may→30-abr, 15-may→14-may). Si el nodo
                // no reparte esa semana o está cancelada, physicalDateFor da null
                // y caemos al día nominal del nodo (la fila se atenúa via 'delivers').
                'date' => $nodeDeliveryDate->physicalDateFor($b, $node) ?? $nodeDeliveryDate->weekdayDateFor($b, $node),
                'delivers' => $nodeDeliveryDate->deliversInBasket($b, $node),
                'active' => $b->getId() === $selected->getId(),
                'past' => $b->getDate() !== null && $b->getDate() < $today,
            ],
            $window,
        );

        $first = $window[0] ?? null;
        $last = $window !== [] ? end($window) : null;

        return [
            'timeline' => $timeline,
            'nav' => [
                'prev_ref' => $first?->getId(),
                'next_ref' => $last?->getId(),
                'has_prev' => $first !== null && $this->basketExistsBeyond($basketRepo, $first, '<'),
                'has_next' => $last !== null && $this->basketExistsBeyond($basketRepo, $last, '>'),
            ],
            'ref_id' => $anchor?->getId(),
        ];
    }

    /**
     * ¿Hay algún Basket más allá del dado en la dirección indicada?
     *
     * @param string $op '<' (anteriores) o '>' (posteriores).
     */
    private function basketExistsBeyond(BasketRepository $repo, Basket $basket, string $op): bool
    {
        $date = $basket->getDate()?->format('Y-m-d');
        if ($date === null) {
            return false;
        }

        return (int) $repo->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where("b.date $op :date")
            ->setParameter('date', $date)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Puente por día: redirige al reparto v2 de ese Basket en el primer nodo.
     * Lo usa el evento del calendario (y cualquier enlace que solo conozca el
     * día). Unifica el reparto en una sola pantalla —la v2, que redibuja las
     * semanas futuras sin congelarlas— y reemplaza a la antigua delivery_show,
     * que materializaba al abrir y se ha retirado.
     */
    #[Route('/dia/{basketId}', name: 'delivery_for_basket', methods: ['GET'], requirements: ['basketId' => '\d+'])]
    public function forBasket(
        #[MapEntity(id: 'basketId')] Basket $basket,
        NodeRepository $nodeRepo,
    ): Response {
        $firstNode = $nodeRepo->createQueryBuilder('n')
            ->orderBy('n.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if ($firstNode === null) {
            throw $this->createNotFoundException('No hay nodos configurados todavía.');
        }

        return $this->redirectToRoute('delivery_by_node', [
            'nodeId' => $firstNode->getId(),
            'basketId' => $basket->getId(),
        ]);
    }

    /**
     * Selector de nodos para el listado imprimible de un día (Basket): muestra
     * todos los nodos, premarcando los que reparten ese día, y enlaza a la
     * generación del PDF con los nodos elegidos.
     */
    #[Route('/imprimible/{basketId}', name: 'delivery_printable_select', methods: ['GET'], requirements: ['basketId' => '\d+'])]
    public function printableSelect(
        #[MapEntity(id: 'basketId')] Basket $basket,
        NodeRepository $nodeRepo,
        NodeDeliveryDate $nodeDeliveryDate,
    ): Response {
        $nodes = array_map(
            static fn (Node $node): array => [
                'node' => $node,
                'delivers' => $nodeDeliveryDate->deliversInBasket($basket, $node),
                'physical_date' => $nodeDeliveryDate->physicalDateFor($basket, $node),
            ],
            $nodeRepo->findBy([], ['name' => 'ASC']),
        );

        // Si ningún nodo reparte ese día (festivo global, todos quincenales
        // fuera de fase…) no hay nada que imprimir: la plantilla deshabilita la
        // generación y el PDF queda protegido aguas abajo en printablePdf().
        $anyDelivers = array_filter($nodes, static fn (array $row): bool => $row['delivers']) !== [];

        return $this->render('delivery/printable_select.html.twig', [
            'basket' => $basket,
            'nodes' => $nodes,
            'any_delivers' => $anyDelivers,
        ]);
    }

    /**
     * Listado imprimible (PDF descargable vía dompdf) de un día para los nodos
     * elegidos (?nodes[]=). Cada nodo va en su(s) hoja(s), aislado por salto de
     * página. Reutiliza {@see NodeDeliverySheet}: si la semana está materializada
     * la lee de piedra ({@see NodeDeliverySheet::build}); si no, la DIBUJA al vuelo
     * desde la proyección ({@see NodeDeliverySheet::shape} sobre
     * {@see WeeklyBasketGenerator::projectLinesForNode}) sin congelarla, igual que
     * la pantalla v2.
     */
    #[Route('/imprimible/{basketId}/pdf', name: 'delivery_printable_pdf', methods: ['GET'], requirements: ['basketId' => '\d+'])]
    public function printablePdf(
        #[MapEntity(id: 'basketId')] Basket $basket,
        Request $request,
        NodeRepository $nodeRepo,
        NodeDeliverySheet $sheetBuilder,
        NodeDeliveryDate $nodeDeliveryDate,
        WeeklyBasketGenerator $generator,
        DeliveryModeResolver $deliveryModeResolver,
    ): Response {
        $nodeIds = array_values(array_filter(array_map('intval', (array) $request->query->all('nodes'))));

        // Piedra vs dibujo idéntico a byNode y POR NODO (vía DeliveryModeResolver): cada
        // nodo se lee de su piedra (STONE) si la tiene materializada; si no y le toca, se
        // dibuja al vuelo desde la proyección (DRAW) sin congelarla; EMPTY no imprime
        // hoja. La decisión no es global del basket: un cierre que desplaza la cadencia
        // de un nodo quincenal lo deja sin piedra en la semana nueva aunque otro nodo sí
        // esté materializado.
        $sheets = [];
        foreach ($nodeRepo->findBy(['id' => $nodeIds ?: [0]], ['name' => 'ASC']) as $node) {
            $mode = $deliveryModeResolver->mode($node, $basket);
            $sheet = match ($mode) {
                DeliveryModeResolver::STONE => $sheetBuilder->build($node, $basket),
                DeliveryModeResolver::DRAW => $sheetBuilder->shape($generator->projectLinesForNode($node, $basket)),
                default => null,
            };
            if ($sheet === null) {
                continue; // EMPTY: no reparte, cancelado, o pasado sin materializar
            }
            $sheets[] = [
                'node' => $node,
                'physical_date' => $nodeDeliveryDate->physicalDateFor($basket, $node),
                'sheet' => $sheet,
            ];
        }

        // Ninguno de los nodos elegidos reparte ese día: no tiene sentido un PDF
        // de cero hojas. Volvemos al selector con un aviso. Cubre tanto el caso
        // de que ningún nodo reparta como el de forzar la URL con nodos que no
        // reparten.
        if ($sheets === []) {
            $this->addFlash('warning', 'Ningún nodo reparte ese día: no hay listado que generar.');

            return $this->redirectToRoute('delivery_printable_select', ['basketId' => $basket->getId()]);
        }

        $html = $this->renderView('delivery/printable.html.twig', [
            'basket' => $basket,
            'sheets' => $sheets,
        ]);

        // DejaVu Sans: dompdf necesita una fuente con cobertura latina completa
        // para acentos y la ñ (la fuente por defecto los rompe).
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sprintf('reparto-%s.pdf', $basket->getDate()->format('Y-m-d'));

        return new Response($dompdf->output(), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }
}
