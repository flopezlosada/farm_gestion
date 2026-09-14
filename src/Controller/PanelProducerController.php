<?php

namespace App\Controller;

use App\Entity\ConsumerGroupEventLog;
use App\Entity\ConsumerGroupRound;
use App\Form\ConsumerGroupRoundType;
use App\Repository\ConsumerGroupRoundRepository;
use App\Service\ConsumerGroup\ConsumerGroupEventRecorder;
use App\Service\ConsumerGroup\InvalidRoundTransition;
use App\Service\ConsumerGroup\OrderAggregator;
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
 * Panel de autogestión del PRODUCTOR: abrir/cerrar sus propias rondas y elegir
 * qué productos de su catálogo entran en cada una, a qué precio.
 *
 * NO toca el pedido agregado de las socias (no es su competencia), ni confirma
 * la ronda (implica que se supera el mínimo y dispara el cobro, lo decide la
 * comisión), ni avisa a la asociación: eso sigue en `/gestion/consumer-group`.
 *
 * Acceso: ROLE_PRODUCER (derivado de tener un Producer vinculado). Cada acción
 * comprueba además que la ronda sea DEL PRODUCTOR logueado — «solo lo mío»,
 * calcado del filtro por Partner del panel del socix.
 */
#[Route('/panel/producer')]
#[IsGranted('FEATURE_GRUPO_CONSUMO')]
#[IsGranted('ROLE_PRODUCER')]
class PanelProducerController extends AbstractController
{
    /**
     * Listado de las rondas de este productor.
     */
    #[Route('', name: 'panel_producer_index', methods: ['GET'])]
    public function index(ConsumerGroupRoundRepository $rounds): Response
    {
        $producer = $this->getUser()->getProducer();

        return $this->render('Panel/producer/index.html.twig', [
            'rounds' => $rounds->findAllForProducer($producer),
        ]);
    }

    /**
     * Abrir una ronda propia. El productor no se elige (es él mismo): el campo
     * queda bloqueado en el form, igual que en la edición desde gestión.
     */
    #[Route('/rounds/new', name: 'panel_producer_round_new', methods: ['GET', 'POST'])]
    public function new(Request $request, RoundItemEditor $itemEditor, ConsumerGroupEventRecorder $recorder, EntityManagerInterface $em): Response
    {
        $producer = $this->getUser()->getProducer();

        $round = new ConsumerGroupRound();
        $round->setProducer($producer);
        $form = $this->createForm(ConsumerGroupRoundType::class, $round, ['lock_producer' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $itemEditor->seedFromCatalog($round);
            $round->setCreatedBy($this->getUser());
            $em->persist($round);
            $recorder->record(ConsumerGroupEventLog::KIND_ROUND_CREATED, $round, $this->getUser(), 'Pedido creado por el productor.');
            $em->flush();
            $this->addFlash('success', 'Pedido creado. Revisa los productos y precios.');

            return $this->redirectToRoute('panel_producer_round_items', ['id' => $round->getId()]);
        }

        return $this->render('Panel/producer/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Ficha de una ronda propia: agregado (sin nombres de socias, es el mismo
     * dato que hoy se exporta en CSV) y transiciones disponibles.
     */
    #[Route('/rounds/{id}', name: 'panel_producer_round_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(ConsumerGroupRound $round, OrderAggregator $aggregator, RoundStateMachine $machine): Response
    {
        $this->assertOwnRound($round);

        return $this->render('Panel/producer/show.html.twig', [
            'round'       => $round,
            'aggregate'   => $aggregator->aggregate($round),
            'transitions' => $machine->allowedTransitions($round),
        ]);
    }

    /**
     * Elegir qué productos del catálogo entran en la ronda y a qué precio.
     * Mismo servicio y misma lógica que la pantalla equivalente de gestión.
     */
    #[Route('/rounds/{id}/items', name: 'panel_producer_round_items', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function items(Request $request, ConsumerGroupRound $round, RoundItemEditor $itemEditor, ConsumerGroupEventRecorder $recorder, EntityManagerInterface $em): Response
    {
        $this->assertOwnRound($round);

        if (!$round->canManageOrders()) {
            $this->addFlash('warning', 'Este pedido ya no admite cambios en sus productos.');

            return $this->redirectToRoute('panel_producer_round_show', ['id' => $round->getId()]);
        }

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
            if (!$this->isCsrfTokenValid('panel_producer_items_'.$round->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('warning', 'Token de seguridad inválido.');

                return $this->redirectToRoute('panel_producer_round_items', ['id' => $round->getId()]);
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
            $recorder->record(ConsumerGroupEventLog::KIND_ITEMS_UPDATED, $round, $this->getUser(), 'Productos y precios actualizados por el productor.');
            $em->flush();
            $this->addFlash('success', 'Productos y precios actualizados.');

            return $this->redirectToRoute('panel_producer_round_show', ['id' => $round->getId()]);
        }

        $currentPrice = [];
        $included = [];
        foreach ($round->getItems() as $item) {
            if ($item->getProduct() !== null) {
                $currentPrice[$item->getProduct()->getId()] = $item->getPrice();
                $included[$item->getProduct()->getId()] = true;
            }
        }

        return $this->render('Panel/producer/items.html.twig', [
            'round'         => $round,
            'catalog'       => array_values($catalog),
            'current_price' => $currentPrice,
            'included'      => $included,
        ]);
    }

    /**
     * Transición de estado de una ronda propia: cerrar/cancelar/entregar/reabrir.
     * Confirmar NO está aquí: lo decide la comisión (implica cobro a socias).
     */
    #[Route('/rounds/{id}/transition', name: 'panel_producer_round_transition', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function transition(Request $request, ConsumerGroupRound $round, RoundStateMachine $machine, ConsumerGroupEventRecorder $recorder, EntityManagerInterface $em): Response
    {
        $this->assertOwnRound($round);

        if (!$this->isCsrfTokenValid('panel_producer_transition_'.$round->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'Token de seguridad inválido.');

            return $this->redirectToRoute('panel_producer_round_show', ['id' => $round->getId()]);
        }

        $to = $request->request->getInt('to');
        try {
            $machine->transition($round, $to);
        } catch (InvalidRoundTransition $e) {
            $this->addFlash('warning', $e->getMessage());

            return $this->redirectToRoute('panel_producer_round_show', ['id' => $round->getId()]);
        }

        if ($to === ConsumerGroupRound::STATUS_CANCELLED) {
            $reason = trim((string) $request->request->get('reason'));
            if ($reason !== '') {
                $round->setCancelReason($reason);
            }
        }

        $recorder->record(ConsumerGroupEventLog::KIND_ROUND_TRANSITIONED, $round, $this->getUser(), sprintf('Pedido marcado como "%s" por el productor.', $round->getStatusLabel()));
        $em->flush();
        $this->addFlash('success', sprintf('Pedido marcado como "%s".', $round->getStatusLabel()));

        return $this->redirectToRoute('panel_producer_round_show', ['id' => $round->getId()]);
    }

    /**
     * Export CSV del agregado: mismo formato que la comisión ve en gestión.
     */
    #[Route('/rounds/{id}/export', name: 'panel_producer_round_export', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function export(ConsumerGroupRound $round, OrderAggregator $aggregator): StreamedResponse
    {
        $this->assertOwnRound($round);

        $aggregate = $aggregator->aggregate($round);

        $response = new StreamedResponse(function () use ($aggregate): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
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
     * 404 si la ronda no es de este productor: nunca un 403 que confirme que
     * el id pertenece a OTRO productor.
     */
    private function assertOwnRound(ConsumerGroupRound $round): void
    {
        $producer = $this->getUser()->getProducer();
        if ($round->getProducer()?->getId() !== $producer?->getId()) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * Normaliza un PRECIO enviado (coma → punto, vacío/negativo/no numérico → "0").
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
