<?php

namespace App\Controller;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupRound;
use App\Repository\ConsumerGroupOrderRepository;
use App\Repository\ConsumerGroupRoundRepository;
use App\Service\ConsumerGroup\OrderEditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Panel del socix para el GRUPO DE CONSUMO: ver las rondas abiertas, apuntarse
 * (o editar su pedido mientras la ronda esté abierta) y ver los pedidos que ya se
 * le van a entregar con la cesta.
 *
 * El apunte NO es vinculante hasta que la comisión confirma la ronda (se supera el
 * mínimo del productor); mientras está abierta, la socia puede cambiar o vaciar su
 * pedido. Acceso: ROLE_PARTNER (derivado de tener un Partner vinculado), como el
 * resto de /panel.
 */
#[Route('/panel/consumer-group')]
#[IsGranted('FEATURE_GRUPO_CONSUMO')]
#[IsGranted('ROLE_PARTNER')]
class PanelConsumerGroupController extends AbstractController
{
    /**
     * Rondas abiertas a las que la socia puede apuntarse, y sus pedidos ya
     * confirmados (que se le entregarán con la cesta).
     */
    #[Route('', name: 'panel_consumer_group_index', methods: ['GET'])]
    public function index(ConsumerGroupRoundRepository $rounds, ConsumerGroupOrderRepository $orders): Response
    {
        $partner = $this->getUser()?->getPartner();
        if ($partner === null) {
            return $this->redirectToRoute('dashboard');
        }

        // Un solo query: todos los pedidos de la socia; los indexamos por ronda
        // (para marcar las abiertas ya apuntadas) y filtramos los confirmados.
        $myOrders = $orders->findByPartner($partner);
        $orderByRound = [];
        foreach ($myOrders as $order) {
            $orderByRound[$order->getRound()->getId()] = $order;
        }

        $confirmed = array_filter(
            $myOrders,
            static fn (ConsumerGroupOrder $o): bool => $o->getRound()->isConfirmed() && !$o->isEmpty()
        );

        return $this->render('Panel/consumer_group/index.html.twig', [
            'open_rounds'    => $rounds->findOpen(),
            'order_by_round' => $orderByRound,
            'confirmed'      => $confirmed,
        ]);
    }

    /**
     * Ficha de una ronda para la socia: si está abierta, el formulario para
     * apuntarse/editar; si no, un resumen de su pedido y el estado.
     */
    #[Route('/{id}', name: 'panel_consumer_group_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(ConsumerGroupRound $round, ConsumerGroupOrderRepository $orders): Response
    {
        $partner = $this->getUser()?->getPartner();
        if ($partner === null) {
            return $this->redirectToRoute('dashboard');
        }

        $order = $orders->findOneByRoundAndPartner($round, $partner);

        // Cantidades ya pedidas por item de ronda (round_item.id => cantidad).
        $quantities = [];
        if ($order !== null) {
            foreach ($order->getLines() as $line) {
                if ($line->getRoundItem() !== null) {
                    $quantities[$line->getRoundItem()->getId()] = $line->getQuantity();
                }
            }
        }

        return $this->render('Panel/consumer_group/show.html.twig', [
            'round'      => $round,
            'order'      => $order,
            'quantities' => $quantities,
            'can_order'  => $round->canReceiveOrders(),
        ]);
    }

    /**
     * Guardar el pedido de la socia en una ronda abierta. Upsert: crea el pedido si
     * no existía y sincroniza las líneas con las cantidades enviadas.
     */
    #[Route('/{id}/order', name: 'panel_consumer_group_order', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function order(Request $request, ConsumerGroupRound $round, ConsumerGroupOrderRepository $orders, OrderEditor $editor, EntityManagerInterface $em): Response
    {
        $partner = $this->getUser()?->getPartner();
        if ($partner === null) {
            return $this->redirectToRoute('dashboard');
        }

        if (!$this->isCsrfTokenValid('panel_consumer_group_order_'.$round->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('panel_consumer_group_show', ['id' => $round->getId()]);
        }

        if (!$round->canReceiveOrders()) {
            $this->addFlash('warning', 'Esta ronda ya no admite pedidos.');

            return $this->redirectToRoute('panel_consumer_group_show', ['id' => $round->getId()]);
        }

        // Cantidades enviadas: quantity[<roundItemId>]. Se resuelven contra los
        // items de la ronda (ignorando ids ajenos a ella).
        $raw = $request->request->all('quantity');
        $desired = [];
        foreach ($round->getItems() as $item) {
            $value = $raw[$item->getId()] ?? '0';
            $desired[] = ['item' => $item, 'quantity' => $this->normalizeQuantity($value)];
        }

        $order = $orders->findOneByRoundAndPartner($round, $partner) ?? new ConsumerGroupOrder($round, $partner);
        $editor->apply($order, $desired);

        // No persistir un pedido nuevo que queda vacío (no se ha pedido nada).
        if ($order->getId() === null && $order->isEmpty()) {
            $this->addFlash('info', 'No has apuntado ninguna cantidad, así que no se ha guardado ningún pedido.');

            return $this->redirectToRoute('panel_consumer_group_show', ['id' => $round->getId()]);
        }

        $em->persist($order);
        $em->flush();
        $this->addFlash('success', 'Pedido guardado. Podrás cambiarlo hasta que se cierre la ronda.');

        return $this->redirectToRoute('panel_consumer_group_show', ['id' => $round->getId()]);
    }

    /**
     * Normaliza una cantidad enviada (coma decimal → punto, vacío → "0", negativos
     * a "0"). Devuelve un decimal como string apto para la línea.
     */
    private function normalizeQuantity(mixed $value): string
    {
        $normalized = str_replace(',', '.', (string) $value);
        if (!is_numeric($normalized) || (float) $normalized < 0) {
            return '0';
        }

        return $normalized;
    }
}
