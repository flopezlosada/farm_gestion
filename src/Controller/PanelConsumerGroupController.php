<?php

namespace App\Controller;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\Image;
use App\Repository\ConsumerGroupOrderRepository;
use App\Repository\ConsumerGroupRoundRepository;
use App\Service\AppSettings;
use App\Service\ConsumerGroup\OrderEditor;
use App\Service\ConsumerGroup\RepeatLastOrder;
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
    public function index(ConsumerGroupRoundRepository $rounds, ConsumerGroupOrderRepository $orders, AppSettings $settings): Response
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

        // Lo confirmado se parte en DOS: lo que está por llegar y lo que ya pasó.
        // Juntos bajo «se te entregará con la cesta» iban los pedidos de hace
        // meses, ya entregados y cobrados, anunciando una entrega que no va a
        // pasar; y la lista sólo podía crecer.
        $today = new \DateTime('today');
        $upcoming = [];
        $past = [];
        foreach ($myOrders as $order) {
            $round = $order->getRound();
            if (!$round->isConfirmed() || $order->isEmpty()) {
                continue;
            }

            $delivery = $round->getDeliveryDate();
            $isPast = $round->getStatus() === ConsumerGroupRound::STATUS_DELIVERED
                || ($delivery !== null && $delivery < $today);

            // Sin fecha de entrega cuenta como pendiente: la comisión aún no la ha
            // puesto, así que el pedido está por llegar, no vivido.
            $isPast ? $past[] = $order : $upcoming[] = $order;
        }

        return $this->render('Panel/consumer_group/index.html.twig', [
            'open_rounds'    => $rounds->findOpen(),
            'order_by_round' => $orderByRound,
            'confirmed'      => $upcoming,
            'past'           => $past,
            'payment_info'   => $settings->getString(AppSettings::CONSUMER_GROUP_PAYMENT_INFO),
        ]);
    }

    /**
     * Ficha de una ronda para la socia: si está abierta, el formulario para
     * apuntarse/editar; si no, un resumen de su pedido y el estado.
     */
    #[Route('/{id}', name: 'panel_consumer_group_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        ConsumerGroupRound $round,
        ConsumerGroupOrderRepository $orders,
        RepeatLastOrder $repeatLastOrder,
        AppSettings $settings,
        EntityManagerInterface $em,
    ): Response {
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

        // Fotos de los productos del pedido, en UNA consulta: la lista la recorre
        // entera el formulario, y preguntar por fila sería una consulta por
        // producto.
        $productIds = [];
        foreach ($round->getItems() as $item) {
            $productId = $item->getProduct()?->getId();
            if ($productId !== null) {
                $productIds[] = $productId;
            }
        }
        $photos = $em->getRepository(Image::class)
            ->findOneForObjects(ConsumerGroupProduct::OBJECT_CLASS, $productIds);

        return $this->render('Panel/consumer_group/show.html.twig', [
            'photos'     => $photos,
            'round'      => $round,
            'order'      => $order,
            'quantities' => $quantities,
            'can_order'  => $round->canReceiveOrders(),
            // Lo que pidió la vez pasada a este mismo productor, para el botón de
            // repetir. Sólo si todavía no ha apuntado nada: con el pedido ya
            // escrito, ofrecer "lo mismo que la otra vez" sería ofrecer pisarlo.
            'repeatable' => $order === null || $order->isEmpty()
                ? $repeatLastOrder->quantitiesFor($round, $partner)
                : [],
            'payment_info' => $settings->getString(AppSettings::CONSUMER_GROUP_PAYMENT_INFO),
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
        // items de la ronda (ignorando ids ajenos a ella) y se pasan TAL CUAL:
        // normalizarlas es cosa de OrderEditor, que es por donde pasan todas las
        // líneas vengan de donde vengan.
        $raw = $request->request->all('quantity');
        $desired = [];
        foreach ($round->getItems() as $item) {
            $desired[] = ['item' => $item, 'quantity' => $raw[$item->getId()] ?? '0'];
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

}
