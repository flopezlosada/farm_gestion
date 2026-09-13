<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupRound;

/**
 * Agrega los pedidos de una ronda del grupo de consumo. Lógica pura (sin BBDD):
 * recorre los pedidos ya cargados y devuelve el resumen que la comisión necesita
 * para decidir si se supera el mínimo del productor y para pasarle el pedido:
 *   - participantCount: nº de socias con pedido NO vacío.
 *   - total: importe total de lo que se le encarga al productor, en euros
 *     (informativo; la app no cobra).
 *   - byItem: por cada producto de la ronda ({@see \App\Entity\ConsumerGroupRoundItem}),
 *     lo que piden las socias, lo que pide la asociación para el local, la suma
 *     de las dos y su subtotal. Incluye items sin pedir.
 *
 * LO QUE SE LE PIDE AL PRODUCTOR NO ES SÓLO LO DE LAS SOCIAS: la comisión encarga
 * además para el local, y eso viaja en la propia línea de la ronda
 * ({@see \App\Entity\ConsumerGroupRoundItem::getAssociationQuantity()}). Por eso
 * `totalQuantity` y `subtotal` —que son lo que se pide y lo que cuesta— incluyen
 * las dos cosas, mientras que `quantity` sigue siendo sólo lo de las socias, que
 * es lo que se les cobra a ellas.
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
     *     byItem: list<array{
     *         item: \App\Entity\ConsumerGroupRoundItem,
     *         quantity: float,
     *         associationQuantity: float,
     *         totalQuantity: float,
     *         subtotal: float
     *     }>
     * }
     */
    public function aggregate(ConsumerGroupRound $round): array
    {
        $qtyByItem = [];
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
            }
        }

        $byItem = [];
        $total = 0.0;
        foreach ($round->getItems() as $item) {
            $partners = round($qtyByItem[spl_object_id($item)] ?? 0.0, 2);
            $association = round((float) $item->getAssociationQuantity(), 2);
            $totalQuantity = round($partners + $association, 2);

            // El subtotal se calcula sobre la cantidad total y no sumando el de
            // cada socia: el precio es el mismo para todas y así la línea cuadra
            // siempre con lo que se le encarga al productor.
            $subtotal = round($totalQuantity * (float) $item->getPrice(), 2);

            $byItem[] = [
                'item' => $item,
                'quantity' => $partners,
                'associationQuantity' => $association,
                'totalQuantity' => $totalQuantity,
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
