<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\MarkupType;
use App\Repository\ConsumerGroupRoundItemRepository;

/**
 * Gestiona los productos de una ronda ({@see ConsumerGroupRoundItem}) a partir del
 * catálogo del productor.
 *
 *   - {@see seedFromCatalog()}: al crear la ronda, la puebla con todos los productos
 *     ACTIVOS del catálogo del productor, al precio de la última ronda que los usó.
 *   - {@see apply()}: reconcilia la selección que hace la comisión (qué productos
 *     entran en la ronda y a qué precio de ronda).
 *   - {@see applyAssociationQuantities()}: lo que la asociación encarga para el
 *     local, que se suma al pedido del productor sin pertenecer a ninguna socia.
 */
class RoundItemEditor
{
    public function __construct(
        private readonly ConsumerGroupRoundItemRepository $roundItems,
        private readonly RoundPricing $pricing,
    ) {
    }

    /**
     * Puebla una ronda recién creada con los productos activos del catálogo de su
     * productor. No hay precio de referencia en el catálogo (se quitó: nadie lo
     * mantenía al día): el arranque es lo último que se cobró por cada producto
     * ({@see ConsumerGroupRoundItemRepository::findLastPriceForProduct()}), o 0 si es la
     * primera vez.
     *
     * Con un productor gestionado por la comisión se parte además de su último precio
     * de productor, si lo hay, y el precio final se recalcula con el incremento del
     * pedido. Sin él, el precio final de la última vez queda como arranque y la
     * comisión tiene que poner el precio del productor al revisar.
     */
    public function seedFromCatalog(ConsumerGroupRound $round): void
    {
        $producer = $round->getProducer();
        if ($producer === null) {
            return;
        }

        $position = 0;
        foreach ($producer->getActiveProducts() as $product) {
            $price = $this->roundItems->findLastPriceForProduct($product) ?? '0';
            $item = new ConsumerGroupRoundItem($round, $product, $price);
            $item->setSortOrder($position++);
            $round->addItem($item);

            $producerPrice = $producer->isSelfManaged()
                ? ($price === '0' ? null : $price)
                : $this->roundItems->findLastProducerPriceForProduct($product);
            if ($producerPrice !== null) {
                $item->setProducerPrice($producerPrice);
                $this->pricing->reprice($item);
            }
        }
    }

    /**
     * Reconcilia los items de la ronda con la selección enviada.
     *
     * Cada entrada lleva un `mode` que dice cómo se fija el precio:
     *   - `plain` (por defecto): `price` es el dato. Con productor autogestionado es
     *     también su precio de productor.
     *   - `managed`: el dato es `producerPrice` y, opcional, un incremento propio
     *     (`markupType`/`markupValue`); el precio final sale de {@see RoundPricing}.
     *     `reviewed` da por validado un incremento pendiente.
     *   - `breakdown`: producto sin desglosar; el precio final NO cambia y, si llega
     *     `producerPrice`, se desglosa ({@see RoundPricing::breakDown()}).
     *
     * @param array<array{
     *     product: ConsumerGroupProduct,
     *     included: bool,
     *     price: string,
     *     mode?: 'plain'|'managed'|'breakdown',
     *     producerPrice?: ?string,
     *     markupType?: ?MarkupType,
     *     markupValue?: ?string,
     *     reviewed?: bool,
     * }> $desired
     *
     * @throws PriceLocked si un precio con socias apuntadas cambiaría
     * @throws \InvalidArgumentException si un desglose no es válido
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
                if ($item === null) {
                    $item = new ConsumerGroupRoundItem($round, $product, '0');
                    $round->addItem($item);
                }
                $item->setSortOrder($position++);
                $this->fillPrice($item, $entry);
            } elseif ($item !== null) {
                $round->removeItem($item);
            }
        }
    }

    /**
     * Fija el precio de un producto según el `mode` de su entrada ({@see apply()}).
     *
     * @param array<string, mixed> $entry
     */
    private function fillPrice(ConsumerGroupRoundItem $item, array $entry): void
    {
        switch ($entry['mode'] ?? 'plain') {
            case 'managed':
                $this->pricing->setBreakdown(
                    $item,
                    (string) $entry['producerPrice'],
                    $entry['markupType'] ?? null,
                    $entry['markupValue'] ?? null,
                    (bool) ($entry['reviewed'] ?? false),
                );
                break;
            case 'breakdown':
                if (($entry['producerPrice'] ?? null) !== null) {
                    $this->pricing->breakDown($item, $entry['producerPrice']);
                }
                break;
            default:
                $item->setPrice($entry['price']);
                if ($item->getRound()?->getProducer()?->isSelfManaged()) {
                    $item->setProducerPrice($entry['price']);
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
