<?php

namespace App\Controller;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupRound;
use App\Entity\Partner;
use App\Form\ConsumerGroupRoundType;
use App\Repository\ConsumerGroupOrderRepository;
use App\Repository\ConsumerGroupRoundRepository;
use App\Service\ConsumerGroup\ConsumerGroupNotifier;
use App\Service\ConsumerGroup\InvalidRoundTransition;
use App\Service\ConsumerGroup\ConsumerGroupStats;
use App\Service\ConsumerGroup\OrderAggregator;
use App\Service\ConsumerGroup\OrderEditor;
use App\Service\ConsumerGroup\RoundItemEditor;
use App\Service\ConsumerGroup\RoundStateMachine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestión del GRUPO DE CONSUMO para la comisión: pedidos de pedido colectivo sobre el
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
    public function index(ConsumerGroupRoundRepository $rounds, ConsumerGroupOrderRepository $orders): Response
    {
        return $this->render('consumer_group/index.html.twig', [
            'rounds'       => $rounds->findAllForManagement(),
            'order_counts' => $orders->countByRound(),
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
    public function new(Request $request, RoundItemEditor $itemEditor, EntityManagerInterface $em): Response
    {
        $round = new ConsumerGroupRound();
        $form = $this->createForm(ConsumerGroupRoundType::class, $round);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $itemEditor->seedFromCatalog($round);
            $round->setCreatedBy($this->getUser());
            $em->persist($round);
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
    public function show(ConsumerGroupRound $round, OrderAggregator $aggregator, RoundStateMachine $machine): Response
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
            'socias'      => $socias,
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
     * catálogo). Solo mientras está abierto.
     */
    #[Route('/{id}/edit', name: 'consumer_group_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, ConsumerGroupRound $round, EntityManagerInterface $em): Response
    {
        if (!$round->canReceiveOrders()) {
            $this->addFlash('warning', 'Sólo se pueden editar pedidos abiertos.');

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
     * y a qué precio de pedido. Solo mientras está abierto.
     */
    #[Route('/{id}/items', name: 'consumer_group_items', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function items(Request $request, ConsumerGroupRound $round, RoundItemEditor $itemEditor, EntityManagerInterface $em): Response
    {
        if (!$round->canReceiveOrders()) {
            $this->addFlash('warning', 'Sólo se pueden cambiar los productos de un pedido abierto.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        // Catálogo a mostrar: productos activos del productor + los que ya están en la
        // pedido (aunque se hayan desactivado, para no perderlos sin querer).
        $catalog = [];
        foreach ($round->getProducer()?->getActiveProducts() ?? [] as $product) {
            $catalog[$product->getId()] = $product;
        }
        foreach ($round->getItems() as $item) {
            if ($item->getProduct() !== null) {
                $catalog[$item->getProduct()->getId()] = $item->getProduct();
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
            $itemEditor->apply($round, $desired);
            $em->flush();
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
     * Transición de estado (cerrar/cancelar/entregar/reabrir). Confirmar tiene su
     * propia pantalla ({@see self::confirm}).
     */
    #[Route('/{id}/transition', name: 'consumer_group_transition', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function transition(Request $request, ConsumerGroupRound $round, RoundStateMachine $machine, EntityManagerInterface $em): Response
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

        $em->flush();
        $this->addFlash('success', sprintf('Pedido marcado como "%s".', $round->getStatusLabel()));

        return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
    }

    /**
     * Confirmar un pedido con paso intermedio: recuento de socias a avisar +
     * interruptor de email. El email es opcional y respeta el interruptor general.
     */
    #[Route('/{id}/confirm', name: 'consumer_group_confirm', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function confirm(Request $request, ConsumerGroupRound $round, RoundStateMachine $machine, ConsumerGroupNotifier $notifier, EntityManagerInterface $em): Response
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
    public function orderNew(Request $request, ConsumerGroupRound $round, OrderEditor $editor, EntityManagerInterface $em): Response
    {
        if (!$round->canManageOrders()) {
            $this->addFlash('warning', 'No se pueden añadir pedidos en el estado actual del pedido.');

            return $this->redirectToRoute('consumer_group_show', ['id' => $round->getId()]);
        }

        $partners = $em->getRepository(Partner::class)->createQueryBuilder('p')
            ->where("p.status = 'ACTIVO'")
            ->orderBy('p.name', 'ASC')->addOrderBy('p.surname', 'ASC')
            ->getQuery()->getResult();

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
    public function orderEdit(Request $request, ConsumerGroupOrder $order, OrderEditor $editor, EntityManagerInterface $em): Response
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
     * Construye las cantidades deseadas (item de pedido => cantidad normalizada) a
     * partir del POST `quantity[<roundItemId>]`. Compartido por apuntar/editar.
     *
     * @return array<array{item: \App\Entity\ConsumerGroupRoundItem, quantity: string}>
     */
    private function desiredFrom(Request $request, ConsumerGroupRound $round): array
    {
        $raw = $request->request->all('quantity');
        $desired = [];
        foreach ($round->getItems() as $item) {
            $desired[] = ['item' => $item, 'quantity' => $this->normalizeDecimal($raw[$item->getId()] ?? '0')];
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
     * Export CSV del pedido AGREGADO al productor: una fila por producto con la
     * cantidad total pedida y el subtotal.
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
                    number_format($line['quantity'], 2, ',', ''),
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
     * Normaliza un decimal enviado (coma → punto, vacío/negativo/no numérico → "0").
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
