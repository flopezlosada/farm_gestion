<?php

namespace App\Controller;

use App\Entity\ConsumerGroupEventLog;
use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupRound;
use App\Entity\Partner;
use App\Form\ConsumerGroupRoundType;
use App\Repository\ConsumerGroupEventLogRepository;
use App\Repository\ConsumerGroupOrderLineRepository;
use App\Repository\ConsumerGroupOrderRepository;
use App\Repository\ConsumerGroupRoundRepository;
use App\Repository\PartnerRepository;
use App\Service\ConsumerGroup\ConsumerGroupAnnouncer;
use App\Service\ConsumerGroup\ConsumerGroupEventRecorder;
use App\Service\ConsumerGroup\ConsumerGroupNotifier;
use App\Service\ConsumerGroup\InvalidRoundTransition;
use App\Service\ConsumerGroup\ConsumerGroupStats;
use App\Service\ConsumerGroup\ItemsChangeNotifier;
use App\Service\ConsumerGroup\OrderAggregator;
use App\Service\ConsumerGroup\OrderEditor;
use App\Service\ConsumerGroup\RoundItemEditor;
use App\Service\ConsumerGroup\RoundStateMachine;
use App\Service\Notification\NotificationPreferences;
use App\Service\Notification\NotificationTopic;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestión del GRUPO DE CONSUMO para la comisión: pedidos colectivos sobre el
 * catálogo de productores, seguimiento de apuntes y transiciones de estado.
 *
 * Acceso: feature-flag de rodaje + ROLE_GESTION_GRUPO_CONSUMO; la escritura la exige
 * access_control con _EDIT sobre ^/gestion/consumer-group.
 */
#[Route('/gestion/consumer-group')]
#[IsGranted('FEATURE_GRUPO_CONSUMO')]
#[IsGranted('ROLE_GESTION_GRUPO_CONSUMO')]
class ConsumerGroupController extends AbstractController
{
    /**
     * Listado de pedidos con el nº de pedidos de cada uno (sin N+1).
     */
    #[Route('/', name: 'consumer_group_index', methods: ['GET'])]
    public function index(Request $request, ConsumerGroupRoundRepository $rounds, ConsumerGroupOrderRepository $orders): Response
    {
        $filters = [
            'producer' => ((int) $request->query->get('producer', '')) ?: null,
            'status'   => '' !== (string) $request->query->get('status', '') ? (int) $request->query->get('status') : null,
            'from'     => trim((string) $request->query->get('from', '')) ?: null,
            'to'       => trim((string) $request->query->get('to', '')) ?: null,
        ];

        return $this->render('consumer_group/index.html.twig', [
            // La tira de cifras de arriba cuenta SIEMPRE sobre el total, no
            // sobre lo filtrado: es el resumen global, el filtro es para la tabla.
            'all_rounds'   => $rounds->findAllForManagement(),
            'rounds'       => $rounds->findFilteredForManagement($filters),
            'order_counts' => $orders->countByRound(),
            'producers'    => $rounds->findProducersWithRounds(),
            'statuses'     => ConsumerGroupRound::STATUS_LABELS,
            'filters'      => $filters,
        ]);
    }

    /**
     * Analítica del grupo de consumo: cifras globales y agregados por productor,
     * producto y socia (sobre pedidos realizados).
     */
    #[Route('/stats', name: 'consumer_group_stats', methods: ['GET'])]
    public function stats(ConsumerGroupStats $stats): Response
    {
        return $this->render('consumer_group/stats.html.twig', [
            'global'      => $stats->global(),
            'by_producer' => $stats->byProducer(),
            'by_product'  => $stats->byProduct(),
            'by_partner'  => $stats->byPartner(),
            'statuses'    => ConsumerGroupRound::STATUS_LABELS,
        ]);
    }

    /**
     * Crear un pedido. Nace abierto y se siembra con los productos activos del
     * catálogo del productor (al precio de referencia); luego se ajustan en la
     * pantalla de productos del pedido.
     */
    #[Route('/new', name: 'consumer_group_new', methods: ['GET', 'POST'])]
    public function new(Request $request, RoundItemEditor $itemEditor, ConsumerGroupEventRecorder $recorder, EntityManagerInterface $em): Response
    {
        $round = new ConsumerGroupRound();
        $form = $this->createForm(ConsumerGroupRoundType::class, $round);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $itemEditor->seedFromCatalog($round);
            $round->setCreatedBy($this->getUser());
            $em->persist($round);
            $recorder->record(ConsumerGroupEventLog::KIND_ROUND_CREATED, $round, $this->getUser(), 'Pedido creado.');
            $em->flush();
            $this->addFlash('success', 'Pedido creado. Revisa los productos y precios de este pedido.');

            return $this->redirectToRoute('consumer_group_items', ['id' => $round->getId()]);
        }

        return $this->render('consumer_group/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Ficha del pedido: productos, apuntes agregados y transiciones disponibles.
     */
    #[Route('/{id}', name: 'consumer_group_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(ConsumerGroupRound $round, OrderAggregator $aggregator, RoundStateMachine $machine, ConsumerGroupAnnouncer $announcer, ConsumerGroupEventLogRepository $events): Response
    {
        // Pedidos no vacíos del pedido, ordenados por socia, con su total y estado
        // de pago, más un resumen de cobro para la comisión.
        $socias = [];
        $paidCount = 0;
        $paidTotal = 0.0;
        $pendingTotal = 0.0;
        foreach ($round->getOrders() as $order) {
            if ($order->isEmpty()) {
                continue;
            }
            $socias[] = $order;
            if ($order->isPaid()) {
                ++$paidCount;
                $paidTotal += $order->getTotal();
            } else {
                $pendingTotal += $order->getTotal();
            }
        }
        usort($socias, static fn (ConsumerGroupOrder $a, ConsumerGroupOrder $b): int => strcmp((string) $a->getPartner(), (string) $b->getPartner()));

        return $this->render('consumer_group/show.html.twig', [
            'round'       => $round,
            'aggregate'   => $aggregator->aggregate($round),
            'transitions' => $machine->allowedTransitions($round),
            'can_confirm' => $machine->canConfirm($round),
            // La regla de cuándo tiene sentido avisar vive en el announcer, no
            // repetida en la plantilla: si cambia, cambia en un sitio.
            'can_announce' => $announcer->canAnnounce($round),
            'socias'      => $socias,
            'activity'    => $events->findByRound($round),
            'payment'     => [
                'count'        => count($socias),
                'paidCount'    => $paidCount,
                'paidTotal'    => round($paidTotal, 2),
                'pendingTotal' => round($pendingTotal, 2),
            ],
        ]);
    }

    /**
     * Editar la cabecera del pedido (no el productor: sus productos cuelgan de ese
     * catálogo).
     *
     * Mientras la comisión pueda gestionarlo, y no sólo mientras admita apuntes:
     * pasado el plazo, ajustar la fecha de entrega o la nota al productor sigue
     * haciendo falta —de hecho es justo entonces cuando se habla con él—.
     */
    #[Route('/{id}/edit', name: 'consumer_group_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, ConsumerGroupRound $round, EntityManagerInterface $em): Response
    {
        if (!$round->canManageOrders()) {
            $this->addFlash('warning', 'Este pedido ya no se puede editar.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        $form = $this->createForm(ConsumerGroupRoundType::class, $round, ['lock_producer' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Pedido actualizado.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        return $this->render('consumer_group/edit.html.twig', [
            'round' => $round,
            'form'  => $form->createView(),
        ]);
    }

    /**
     * Productos del pedido: elegir qué productos del catálogo del productor entran
     * y a qué precio de pedido. Mientras la comisión pueda gestionarlo.
     */
    #[Route('/{id}/items', name: 'consumer_group_items', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function items(
        Request $request,
        ConsumerGroupRound $round,
        RoundItemEditor $itemEditor,
        ConsumerGroupEventRecorder $recorder,
        EntityManagerInterface $em,
        ConsumerGroupOrderLineRepository $orderLines,
        ConsumerGroupOrderRepository $orders,
        ItemsChangeNotifier $itemsChangeNotifier,
    ): Response {
        if (!$round->canManageOrders()) {
            $this->addFlash('warning', 'Este pedido ya no admite cambios en sus productos.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        // Catálogo a mostrar: productos activos del productor + los que ya están en la
        // pedido (aunque se hayan desactivado, para no perderlos sin querer).
        $catalog = [];
        foreach ($round->getProducer()?->getActiveProducts() ?? [] as $product) {
            $catalog[$product->getId()] = $product;
        }
        $existingItemByProductId = [];
        foreach ($round->getItems() as $item) {
            if ($item->getProduct() !== null) {
                $catalog[$item->getProduct()->getId()] = $item->getProduct();
                $existingItemByProductId[$item->getProduct()->getId()] = $item;
            }
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('consumer_group_items_'.$round->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('warning', 'Token de seguridad inválido.');

                return $this->redirectToRoute('consumer_group_items', ['id' => $round->getId()]);
            }

            $included = $request->request->all('include');
            $prices = $request->request->all('price');

            $desired = [];
            foreach ($catalog as $id => $product) {
                $desired[] = [
                    'product'  => $product,
                    'included' => isset($included[$id]),
                    'price'    => $this->normalizeDecimal($prices[$id] ?? $product->getReferencePrice() ?? '0'),
                ];
            }

            if (!array_filter($desired, static fn (array $entry): bool => $entry['included'])) {
                $this->addFlash('warning', 'El pedido necesita al menos un producto: no se ha guardado.');

                return $this->redirectToRoute('consumer_group_items', ['id' => $round->getId()]);
            }

            // Productos que se están QUITANDO y que alguna socia ya tenía pedidos de
            // verdad (cantidad > 0): quitarlos borra esas líneas en cascada sin dejar
            // rastro. Se pide confirmación explícita antes de aplicar nada.
            $removalsWithOrders = [];
            foreach ($desired as $entry) {
                if ($entry['included']) {
                    continue;
                }
                $item = $existingItemByProductId[$entry['product']->getId()] ?? null;
                if ($item === null) {
                    continue;
                }
                $affectedLines = $orderLines->findWithQuantityForItem($item);
                if ($affectedLines !== []) {
                    $removalsWithOrders[] = ['product' => $entry['product'], 'item' => $item, 'lines' => $affectedLines];
                }
            }

            if ($removalsWithOrders !== [] && '1' !== $request->request->get('confirm_removal')) {
                // El estado que se enseña es el RECIÉN ENVIADO, no el de BBDD: si
                // se vuelve a guardar tras confirmar, tiene que salir exactamente
                // lo que la comisión acaba de marcar, no lo de antes.
                $submittedPrice = [];
                foreach ($desired as $entry) {
                    $submittedPrice[$entry['product']->getId()] = $entry['price'];
                }

                return $this->render('consumer_group/items.html.twig', [
                    'round'                => $round,
                    'catalog'              => array_values($catalog),
                    'current_price'        => $submittedPrice,
                    'included'             => $included,
                    'removals_with_orders' => $removalsWithOrders,
                ]);
            }

            // Antes de aplicar: quién se queda sin un producto que ya tenía pedido
            // (para avisar después de guardar), y si esto es una ronda con apuntes
            // reales a la que se le está AÑADIENDO un producto nuevo.
            $removedAffectedPartners = [];
            $removedProductNames = [];
            foreach ($removalsWithOrders as $removal) {
                $removedProductNames[] = $removal['product']->getName();
                foreach ($removal['lines'] as $line) {
                    $partner = $line->getOrder()?->getPartner();
                    if ($partner !== null) {
                        $removedAffectedPartners[$partner->getId()] = $partner;
                    }
                }
            }

            $addedProductNames = [];
            foreach ($desired as $entry) {
                if ($entry['included'] && !isset($existingItemByProductId[$entry['product']->getId()])) {
                    $addedProductNames[] = $entry['product']->getName();
                }
            }

            $itemEditor->apply($round, $desired);
            $recorder->record(ConsumerGroupEventLog::KIND_ITEMS_UPDATED, $round, $this->getUser(), 'Productos y precios del pedido actualizados.');
            $em->flush();

            // Avisar a quien ya tenía pedido en esta ronda: a quien pierde un
            // producto que había pedido, siempre; a todo el que ya tenía algo
            // pedido, si se ha añadido un producto nuevo (puede interesarle).
            if ($removedProductNames !== [] || $addedProductNames !== []) {
                $affected = $removedAffectedPartners;
                if ($addedProductNames !== []) {
                    foreach ($orders->findWithLinesForRound($round) as $order) {
                        $partner = $order->getPartner();
                        if ($partner !== null && !$order->isEmpty()) {
                            $affected[$partner->getId()] = $partner;
                        }
                    }
                }

                $parts = [];
                if ($removedProductNames !== []) {
                    $parts[] = sprintf('se ha quitado: %s', implode(', ', $removedProductNames));
                }
                if ($addedProductNames !== []) {
                    $parts[] = sprintf('se ha añadido: %s', implode(', ', $addedProductNames));
                }
                $itemsChangeNotifier->notify($round, array_values($affected), ucfirst(implode('; ', $parts)).'. Revisa tu pedido.');
            }

            $this->addFlash('success', 'Productos del pedido actualizados.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        // Cantidades/estado actuales por producto para prellenar.
        $currentPrice = [];
        $included = [];
        foreach ($round->getItems() as $item) {
            if ($item->getProduct() !== null) {
                $currentPrice[$item->getProduct()->getId()] = $item->getPrice();
                $included[$item->getProduct()->getId()] = true;
            }
        }

        return $this->render('consumer_group/items.html.twig', [
            'round'         => $round,
            'catalog'       => array_values($catalog),
            'current_price' => $currentPrice,
            'included'      => $included,
        ]);
    }

    /**
     * Guarda lo que la asociación encarga para el local, que se suma al pedido del
     * productor sin pertenecer a ninguna socia.
     *
     * Se edita desde la propia pantalla del pedido, junto al agregado: es ahí donde
     * la comisión ve lo que llevan pedido las socias y decide si completa. Mientras
     * pueda gestionar el pedido, y no sólo mientras admita apuntes: lo del local
     * suele decidirse con el plazo ya vencido, al hablar con el productor.
     */
    #[Route('/{id}/association-order', name: 'consumer_group_association_order', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function associationOrder(Request $request, ConsumerGroupRound $round, RoundItemEditor $itemEditor, ConsumerGroupEventRecorder $recorder, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('consumer_group_association_order_'.$round->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        if (!$round->canManageOrders()) {
            $this->addFlash('warning', 'Este pedido ya no admite cambios.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        // TAL CUAL llegan: las cantidades las normaliza RoundItemEditor, igual que
        // las de las socias, para que el local pida los mismos bultos que ellas.
        $itemEditor->applyAssociationQuantities($this->desiredFrom($request, $round, 'association'));
        $recorder->record(ConsumerGroupEventLog::KIND_ASSOCIATION_ORDER_UPDATED, $round, $this->getUser(), 'Pedido para el local actualizado.');
        $em->flush();
        $this->addFlash('success', 'Guardado lo que se pide para el local.');

        return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
    }

    /**
     * Transición de estado (cerrar/cancelar/entregar/reabrir). Confirmar tiene su
     * propia pantalla ({@see self::confirm}).
     */
    #[Route('/{id}/transition', name: 'consumer_group_transition', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function transition(Request $request, ConsumerGroupRound $round, RoundStateMachine $machine, ConsumerGroupEventRecorder $recorder, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('consumer_group_transition_'.$round->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        $to = $request->request->getInt('to');
        try {
            $machine->transition($round, $to);
        } catch (InvalidRoundTransition $e) {
            $this->addFlash('warning', $e->getMessage());

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        // Al cancelar, guarda el motivo si se ha indicado.
        if ($to === ConsumerGroupRound::STATUS_CANCELLED) {
            $reason = trim((string) $request->request->get('reason'));
            if ($reason !== '') {
                $round->setCancelReason($reason);
            }
        }

        $recorder->record(ConsumerGroupEventLog::KIND_ROUND_TRANSITIONED, $round, $this->getUser(), sprintf('Pedido marcado como "%s".', $round->getStatusLabel()));
        $em->flush();
        $this->addFlash('success', sprintf('Pedido marcado como "%s".', $round->getStatusLabel()));

        return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
    }

    /**
     * Confirmar un pedido con paso intermedio: recuento de socias a avisar +
     * interruptor de email. El email es opcional y respeta el interruptor general.
     */
    #[Route('/{id}/confirm', name: 'consumer_group_confirm', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function confirm(Request $request, ConsumerGroupRound $round, RoundStateMachine $machine, ConsumerGroupNotifier $notifier, ConsumerGroupEventRecorder $recorder, EntityManagerInterface $em): Response
    {
        if (!$machine->canConfirm($round)) {
            $this->addFlash('warning', 'Este pedido no se puede confirmar en su estado actual.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('consumer_group_confirm_'.$round->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('warning', 'Token de seguridad inválido.');

                return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
            }

            $machine->confirm($round);
            $recorder->record(ConsumerGroupEventLog::KIND_ROUND_CONFIRMED, $round, $this->getUser(), 'Pedido confirmado.');
            $em->flush();

            if ($request->request->getBoolean('send_email')) {
                $result = $notifier->notifyConfirmed($round);
                if (!$result['enabled']) {
                    $this->addFlash('warning', 'Pedido confirmado. El email NO se envió: el interruptor general de correo está apagado.');
                } else {
                    $msg = sprintf('Pedido confirmado. Enviados %d email(s).', $result['sent']);
                    if ($result['skippedNoEmail'] > 0) {
                        $msg .= sprintf(' %d socia(s) sin email.', $result['skippedNoEmail']);
                    }
                    if ($result['failed'] > 0) {
                        $msg .= sprintf(' %d fallo(s) de envío (ver logs).', $result['failed']);
                    }
                    $this->addFlash('success', $msg);
                }
            } else {
                $this->addFlash('success', 'Pedido confirmado (sin aviso por email).');
            }

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        return $this->render('consumer_group/confirm.html.twig', [
            'round' => $round,
            'stats' => $notifier->recipientStats($round),
        ]);
    }

    /**
     * Avisar a la asociación de que el pedido está abierto, con paso intermedio:
     * a cuánta gente llega por cada vía antes de mandar nada.
     *
     * El aviso es MANUAL a propósito: la comisión abre el pedido y ajusta
     * productos y precios antes de enseñarlo, así que avisar al guardar mandaría
     * a todo el mundo a un catálogo a medias. Ver
     * {@see ConsumerGroupAnnouncer}.
     */
    #[Route('/{id}/announce', name: 'consumer_group_announce', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function announce(Request $request, ConsumerGroupRound $round, ConsumerGroupAnnouncer $announcer): Response
    {
        if (!$announcer->canAnnounce($round)) {
            $this->addFlash('warning', 'Sólo se avisa de un pedido abierto y con productos.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('consumer_group_announce_'.$round->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('warning', 'Token de seguridad inválido.');

                return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
            }

            // El reenvío sólo se ofrece si ya se avisó: es una orden humana
            // ("ese correo no llegó"), no algo que se marque de paso.
            $resend = null !== $round->getAnnouncedAt() && $request->request->getBoolean('resend');
            $result = $announcer->announce($round, $resend);

            $this->addFlash('success', sprintf(
                'Aviso enviado: %d en la bandeja, %d por correo, %d al móvil.',
                $result['inbox'],
                $result['email'],
                $result['push'],
            ));

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        return $this->render('consumer_group/announce.html.twig', [
            'round' => $round,
            'audience' => $announcer->audience(),
        ]);
    }

    /**
     * Borrar un pedido. Sólo si no tiene pedidos (cancelar en su lugar si los tiene).
     */
    #[Route('/{id}/delete', name: 'consumer_group_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, ConsumerGroupRound $round, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('consumer_group_delete_'.$round->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        if (!$round->getOrders()->isEmpty()) {
            $this->addFlash('warning', 'No se puede borrar un pedido con pedidos. Cancélala en su lugar.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        $em->remove($round);
        $em->flush();
        $this->addFlash('success', 'Pedido borrada.');

        return $this->redirectToRoute('consumer_group_index');
    }

    /**
     * Apuntar a una socia en el pedido desde gestión (la comisión pide por ella).
     * Selecciona la socia y las cantidades por producto.
     */
    #[Route('/{id}/orders/new', name: 'consumer_group_order_new', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function orderNew(Request $request, ConsumerGroupRound $round, OrderEditor $editor, ConsumerGroupEventRecorder $recorder, EntityManagerInterface $em, PartnerRepository $partnerRepository, NotificationPreferences $preferences): Response
    {
        if (!$round->canManageOrders()) {
            $this->addFlash('warning', 'No se pueden añadir pedidos en el estado actual del pedido.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        // Sólo socias interesadas en el grupo de consumo (no lo han silenciado
        // en su pantalla de avisos): sin fila = lo quiere, así que hoy esto casi
        // no filtra nada, y va estrechando la lista según la gente vaya apagando
        // el tema.
        $partners = $preferences->filterAny($partnerRepository->findActive(), NotificationTopic::CONSUMER_GROUP);
        usort(
            $partners,
            static fn (Partner $a, Partner $b): int => [$a->getName(), $a->getSurname()] <=> [$b->getName(), $b->getSurname()]
        );

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('consumer_group_order_new_'.$round->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('warning', 'Token de seguridad inválido.');

                return $this->redirectToRoute('consumer_group_order_new', ['id' => $round->getId()]);
            }

            $partner = $em->getRepository(Partner::class)->find($request->request->getInt('partner'));
            if ($partner === null) {
                $this->addFlash('warning', 'Elige una socia.');

                return $this->redirectToRoute('consumer_group_order_new', ['id' => $round->getId()]);
            }

            // Evitar duplicar: si ya tiene pedido, lo editamos en su lugar.
            $existing = $em->getRepository(ConsumerGroupOrder::class)->findOneBy(['round' => $round, 'partner' => $partner]);
            $order = $existing ?? new ConsumerGroupOrder($round, $partner);

            $editor->apply($order, $this->desiredFrom($request, $round));
            if ($order->getId() === null && $order->isEmpty()) {
                $this->addFlash('info', 'No se apuntó ninguna cantidad; no se ha creado el pedido.');

                return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
            }
            $em->persist($order);
            $recorder->record(
                $existing === null ? ConsumerGroupEventLog::KIND_ORDER_CREATED : ConsumerGroupEventLog::KIND_ORDER_UPDATED,
                $round,
                $this->getUser(),
                sprintf('Pedido de %s %s desde gestión.', $partner, $existing === null ? 'apuntado' : 'actualizado'),
            );
            $em->flush();
            $this->addFlash('success', 'Pedido apuntado.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        return $this->render('consumer_group_order/new.html.twig', [
            'round'    => $round,
            'partners' => $partners,
        ]);
    }

    /**
     * Editar el pedido de una socia desde gestión (corregir cantidades).
     */
    #[Route('/orders/{id}/edit', name: 'consumer_group_order_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function orderEdit(Request $request, ConsumerGroupOrder $order, OrderEditor $editor, ConsumerGroupEventRecorder $recorder, EntityManagerInterface $em): Response
    {
        $round = $order->getRound();

        if (!$round->canManageOrders()) {
            $this->addFlash('warning', 'No se puede editar el pedido en el estado actual del pedido.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('consumer_group_order_edit_'.$order->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('warning', 'Token de seguridad inválido.');

                return $this->redirectToRoute('consumer_group_order_edit', ['id' => $order->getId()]);
            }

            $editor->apply($order, $this->desiredFrom($request, $round));
            $recorder->record(
                $order->isEmpty() ? ConsumerGroupEventLog::KIND_ORDER_EMPTIED : ConsumerGroupEventLog::KIND_ORDER_UPDATED,
                $round,
                $this->getUser(),
                sprintf('Pedido de %s %s desde gestión.', $order->getPartner(), $order->isEmpty() ? 'vaciado' : 'actualizado'),
            );
            $em->flush();
            $this->addFlash('success', 'Pedido actualizado.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        // Cantidades actuales por item de pedido para prellenar.
        $quantities = [];
        foreach ($order->getLines() as $line) {
            if ($line->getRoundItem() !== null) {
                $quantities[$line->getRoundItem()->getId()] = $line->getQuantity();
            }
        }

        return $this->render('consumer_group_order/edit.html.twig', [
            'round'      => $round,
            'order'      => $order,
            'quantities' => $quantities,
        ]);
    }

    /**
     * Construye las cantidades deseadas (item de pedido => cantidad en crudo) a
     * partir de un POST `<campo>[<roundItemId>]`. Compartido por apuntar y editar
     * el pedido de una socia (`quantity`) y por lo que se pide para el local
     * (`association`): el casado con los productos del pedido es el mismo.
     *
     * @return array<array{item: \App\Entity\ConsumerGroupRoundItem, quantity: mixed}>
     */
    private function desiredFrom(Request $request, ConsumerGroupRound $round, string $field = 'quantity'): array
    {
        // TAL CUAL llegan: las cantidades las normaliza el servicio que las guarda.
        // Antes pasaban por normalizeDecimal, que es el normalizador de los PRECIOS
        // —donde los decimales sí valen—, y por eso se podían colar «0,03 garrafas».
        $raw = $request->request->all($field);
        $desired = [];
        foreach ($round->getItems() as $item) {
            $desired[] = ['item' => $item, 'quantity' => $raw[$item->getId()] ?? '0'];
        }

        return $desired;
    }

    /**
     * Marca el pedido de una socia como pagado / pendiente (seguimiento manual del
     * cobro por la comisión). Alterna el estado.
     */
    #[Route('/orders/{id}/toggle-paid', name: 'consumer_group_order_toggle_paid', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function togglePaid(Request $request, ConsumerGroupOrder $order, EntityManagerInterface $em): Response
    {
        $roundId = $order->getRound()->getId();

        if (!$this->isCsrfTokenValid('consumer_group_toggle_paid_'.$order->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $roundId]);
        }

        $order->setPaid(!$order->isPaid());
        $order->setPaidAt($order->isPaid() ? new \DateTime() : null);
        $em->flush();

        return $this->redirectToRoute('consumer_group_show', ['id' => $roundId]);
    }

    /**
     * Marca el pedido de una socia como recogido / pendiente de recoger. Lo
     * puede tocar la comisión desde gestión (esta acción) o la propia socia
     * desde su panel ({@see PanelConsumerGroupController::pickup()}).
     */
    #[Route('/orders/{id}/toggle-picked-up', name: 'consumer_group_order_toggle_picked_up', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function togglePickedUp(Request $request, ConsumerGroupOrder $order, EntityManagerInterface $em): Response
    {
        $roundId = $order->getRound()->getId();

        if (!$this->isCsrfTokenValid('consumer_group_toggle_picked_up_'.$order->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $roundId]);
        }

        $order->setPickedUp(!$order->isPickedUp());
        $order->setPickedUpAt($order->isPickedUp() ? new \DateTime() : null);
        $em->flush();

        return $this->redirectToRoute('consumer_group_show', ['id' => $roundId]);
    }

    /**
     * Export CSV del pedido AGREGADO al productor: una fila por producto con la
     * cantidad total pedida y el subtotal.
     *
     * La cantidad es la TOTAL —lo de las socias más lo que la asociación encarga
     * para el local—, sin desglosar: al productor le da igual de quién es cada
     * caja, y el reparto interno no es asunto suyo.
     */
    #[Route('/{id}/export', name: 'consumer_group_export', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function export(ConsumerGroupRound $round, OrderAggregator $aggregator): StreamedResponse
    {
        $aggregate = $aggregator->aggregate($round);

        $response = new StreamedResponse(function () use ($aggregate): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // BOM para Excel.
            fputcsv($out, ['Producto', 'Unidad', 'Cantidad total', 'Precio', 'Subtotal']);
            foreach ($aggregate['byItem'] as $line) {
                $item = $line['item'];
                fputcsv($out, [
                    $item->getName(),
                    $item->getUnit(),
                    number_format($line['totalQuantity'], 2, ',', ''),
                    number_format((float) $item->getPrice(), 2, ',', ''),
                    number_format($line['subtotal'], 2, ',', ''),
                ]);
            }
            fputcsv($out, ['', '', '', 'Total', number_format($aggregate['total'], 2, ',', '')]);
            fclose($out);
        });

        $filename = sprintf('pedido-%s-%s.csv', $round->getId(), (new \DateTime())->format('Ymd'));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');

        return $response;
    }

    /**
     * Normaliza un PRECIO enviado (coma → punto, vacío/negativo/no numérico → "0").
     *
     * Sólo precios: aquí los decimales son lo normal (42,50 € la garrafa). Las
     * CANTIDADES no pasan por aquí —se piden en unidades enteras— y las normaliza
     * {@see \App\Service\ConsumerGroup\OrderEditor}.
     */
    private function normalizeDecimal(mixed $value): string
    {
        $normalized = str_replace(',', '.', (string) $value);
        if (!is_numeric($normalized) || (float) $normalized < 0) {
            return '0';
        }

        return $normalized;
    }
}
