<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupRound;

/**
 * Agrega los pedidos de una ronda del grupo de consumo. Lógica pura (sin BBDD):
 * recorre los pedidos ya cargados y devuelve el resumen que la comisión necesita
 * para decidir si se supera el mínimo del productor y para pasarle el pedido:
 *   - participantCount: nº de socias con pedido NO vacío.
 *   - total: importe total de la ronda en euros (informativo; la app no cobra).
 *   - byItem: por cada producto de la ronda ({@see \App\Entity\ConsumerGroupRoundItem}),
 *     la cantidad total pedida y su subtotal. Incluye items con cantidad 0.
 *
 * La acumulación se indexa por identidad de objeto ({@see spl_object_id}) para
 * funcionar igual con entidades en memoria (tests) que persistidas.
 */
class OrderAggregator
{
    /**
     * @return array{
     *     participantCount: int,
     *     total: float,
     *     byItem: list<array{item: \App\Entity\ConsumerGroupRoundItem, quantity: float, subtotal: float}>
     * }
     */
    public function aggregate(ConsumerGroupRound $round): array
    {
        $qtyByItem = [];
        $subByItem = [];
        $participantCount = 0;

        foreach ($round->getOrders() as $order) {
            if ($order->isEmpty()) {
                continue;
            }
            ++$participantCount;

            foreach ($order->getLines() as $line) {
                $item = $line->getRoundItem();
                if ($item === null) {
                    continue;
                }
                $key = spl_object_id($item);
                $qtyByItem[$key] = ($qtyByItem[$key] ?? 0.0) + (float) $line->getQuantity();
                $subByItem[$key] = ($subByItem[$key] ?? 0.0) + $line->getSubtotal();
            }
        }

        $byItem = [];
        $total = 0.0;
        foreach ($round->getItems() as $item) {
            $key = spl_object_id($item);
            $quantity = round($qtyByItem[$key] ?? 0.0, 2);
            $subtotal = round($subByItem[$key] ?? 0.0, 2);
            $byItem[] = [
                'item' => $item,
                'quantity' => $quantity,
                'subtotal' => $subtotal,
            ];
            $total += $subtotal;
        }

        return [
            'participantCount' => $participantCount,
            'total' => round($total, 2),
            'byItem' => $byItem,
        ];
    }
}
