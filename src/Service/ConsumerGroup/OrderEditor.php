<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupRoundItem;

/**
 * Sincroniza las líneas de un pedido del grupo de consumo con las cantidades que la
 * socia ha enviado desde el panel. Lógica pura (sin BBDD): opera sobre la entidad
 * {@see ConsumerGroupOrder} y sus líneas; la persistencia (flush) y la orphanRemoval
 * de las líneas quitadas las resuelve el caller/Doctrine.
 *
 * Regla: por cada item de ronda con cantidad > 0 se crea o actualiza su línea; con
 * cantidad 0 (o ausente) se quita la línea si existía. Así vaciar un producto
 * equivale a no pedirlo, sin dejar líneas a cero.
 */
class OrderEditor
{
    /**
     * @param ConsumerGroupOrder $order   Pedido (nuevo o existente) a sincronizar.
     * @param array<array{item: ConsumerGroupRoundItem, quantity: string}> $desired
     *        Cantidades deseadas por item de ronda (tal como llegan del panel).
     */
    public function apply(ConsumerGroupOrder $order, array $desired): void
    {
        // Líneas actuales indexadas por identidad del item (no por id de BBDD, para
        // funcionar igual con entidades en memoria).
        $existing = [];
        foreach ($order->getLines() as $line) {
            $item = $line->getRoundItem();
            if ($item !== null) {
                $existing[spl_object_id($item)] = $line;
            }
        }

        foreach ($desired as $entry) {
            $item = $entry['item'];
            $quantity = $entry['quantity'];
            $key = spl_object_id($item);
            $line = $existing[$key] ?? null;

            if ((float) $quantity > 0) {
                if ($line !== null) {
                    $line->setQuantity($quantity);
                } else {
                    $order->addLine(new ConsumerGroupOrderLine($order, $item, $quantity));
                }
            } elseif ($line !== null) {
                $order->removeLine($line);
            }
        }
    }
}
