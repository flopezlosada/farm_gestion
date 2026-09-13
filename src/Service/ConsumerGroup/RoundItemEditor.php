<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;

/**
 * Gestiona los productos de una ronda ({@see ConsumerGroupRoundItem}) a partir del
 * catálogo del productor. Lógica pura (sin BBDD).
 *
 *   - {@see seedFromCatalog()}: al crear la ronda, la puebla con todos los productos
 *     ACTIVOS del catálogo del productor, al precio de referencia.
 *   - {@see apply()}: reconcilia la selección que hace la comisión (qué productos
 *     entran en la ronda y a qué precio de ronda).
 *   - {@see applyAssociationQuantities()}: lo que la asociación encarga para el
 *     local, que se suma al pedido del productor sin pertenecer a ninguna socia.
 */
class RoundItemEditor
{
    /**
     * Puebla una ronda recién creada con los productos activos del catálogo de su
     * productor, al precio de referencia (0 si no lo tiene).
     */
    public function seedFromCatalog(ConsumerGroupRound $round): void
    {
        $producer = $round->getProducer();
        if ($producer === null) {
            return;
        }

        $position = 0;
        foreach ($producer->getActiveProducts() as $product) {
            $item = new ConsumerGroupRoundItem($round, $product, $product->getReferencePrice() ?? '0');
            $item->setSortOrder($position++);
            $round->addItem($item);
        }
    }

    /**
     * Reconcilia los items de la ronda con la selección enviada.
     *
     * @param array<array{product: \App\Entity\ConsumerGroupProduct, included: bool, price: string}> $desired
     */
    public function apply(ConsumerGroupRound $round, array $desired): void
    {
        // Items actuales indexados por identidad del producto.
        $existing = [];
        foreach ($round->getItems() as $item) {
            $product = $item->getProduct();
            if ($product !== null) {
                $existing[spl_object_id($product)] = $item;
            }
        }

        $position = 0;
        foreach ($desired as $entry) {
            $product = $entry['product'];
            $key = spl_object_id($product);
            $item = $existing[$key] ?? null;

            if ($entry['included']) {
                if ($item !== null) {
                    $item->setPrice($entry['price']);
                    $item->setSortOrder($position++);
                } else {
                    $new = new ConsumerGroupRoundItem($round, $product, $entry['price']);
                    $new->setSortOrder($position++);
                    $round->addItem($new);
                }
            } elseif ($item !== null) {
                $round->removeItem($item);
            }
        }
    }

    /**
     * Guarda lo que la asociación pide para el local en cada producto del pedido.
     *
     * Las cantidades llegan TAL CUAL del formulario y se ajustan aquí al salto de
     * cada producto ({@see OrderQuantity}), igual que las de las socias: el local
     * pide los mismos bultos que ellas, y por el mismo motivo no puede encargar
     * «0,03 garrafas». Lo que no venga vale cero, que es «no pido nada de esto»,
     * como en el pedido de una socia.
     *
     * @param array<array{item: ConsumerGroupRoundItem, quantity: mixed}> $desired
     *        cantidad para el local por item de ronda, sin normalizar
     */
    public function applyAssociationQuantities(array $desired): void
    {
        foreach ($desired as $entry) {
            $item = $entry['item'];
            $item->setAssociationQuantity(OrderQuantity::forItem($entry['quantity'], $item));
        }
    }
}
