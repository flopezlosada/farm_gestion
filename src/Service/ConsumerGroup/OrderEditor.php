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
 *
 * LAS CANTIDADES SE NORMALIZAN AQUÍ, y no en cada pantalla. Lo que se pide son
 * BULTOS —una garrafa de 5 L, un saco, una caja—, así que una línea de «0,03
 * garrafas» es un estado ilegal: no significa nada para la socia y llega así al
 * pedido que se le pasa al productor. Antes cada controller normalizaba por su
 * cuenta y el de gestión reutilizaba para las cantidades el mismo normalizador
 * que para los PRECIOS, donde los decimales sí valen; de ahí venía el 0,03.
 * Poniéndolo en el único sitio por el que pasan todas las líneas, ninguna
 * pantalla futura puede saltárselo por descuido.
 */
class OrderEditor
{
    /**
     * @param ConsumerGroupOrder $order   Pedido (nuevo o existente) a sincronizar.
     * @param array<array{item: ConsumerGroupRoundItem, quantity: mixed}> $desired
     *        Cantidades deseadas por item de ronda, TAL CUAL llegan del
     *        formulario: normalizarlas es cosa de aquí.
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
            $quantity = $this->units($entry['quantity']);
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

    /**
     * Convierte lo que llegue del formulario en un número ENTERO de unidades.
     *
     * Acepta la coma decimal porque es como se teclea aquí, y redondea: de un
     * campo que sólo ofrece enteros no puede salir «1,5», pero sí de un
     * formulario manipulado o de un pegado. Lo negativo y lo que no es número
     * valen cero, que es «no pido nada».
     *
     * @param mixed $value cantidad tal cual llega del formulario (puede ser
     *                     cualquier cosa: el formulario lo manda quien quiera)
     *
     * @return string unidades, como cadena apta para la línea
     */
    private function units(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '0';
        }

        $normalized = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($normalized) || (float) $normalized <= 0) {
            return '0';
        }

        return (string) (int) round((float) $normalized);
    }
}
