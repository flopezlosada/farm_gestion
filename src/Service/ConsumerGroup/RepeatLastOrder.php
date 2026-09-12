<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupRound;
use App\Entity\Partner;
use App\Repository\ConsumerGroupOrderRepository;

/**
 * «Lo mismo que la vez pasada»: traslada el último pedido de una socia a este
 * mismo productor a los productos del pedido que tiene delante.
 *
 * EXISTE PORQUE LO QUE SE PIDE AQUÍ SE REPITE. El aceite, la legumbre o las
 * conservas se piden un par de veces al año y casi siempre lo mismo; teclear de
 * nuevo cinco cantidades es el trabajo que hace que se deje para luego y se pase
 * el plazo.
 *
 * SE CASA POR PRODUCTO, NO POR LÍNEA NI POR PRECIO. Cada pedido tiene sus
 * propias líneas —el precio cambia de una vez a otra, que es justo por lo que el
 * precio vive en la ronda y no en el catálogo—, así que los identificadores de
 * línea no valen de una vez a otra. Lo que la socia quiere repetir es «dos
 * garrafas de aceite», y el aceite sí es el mismo producto del catálogo.
 *
 * Lo que ya no esté a la venta esta vez simplemente no se propone: no se inventa
 * una línea para un producto que este pedido no ofrece.
 */
class RepeatLastOrder
{
    public function __construct(private readonly ConsumerGroupOrderRepository $orders)
    {
    }

    /**
     * Cantidades a proponer, listas para volcar en el formulario.
     *
     * @param ConsumerGroupRound $round   el pedido abierto que la socia tiene delante
     * @param Partner            $partner la socia
     *
     * @return array<int, string> id del item de ESTE pedido => cantidad de la vez pasada
     */
    public function quantitiesFor(ConsumerGroupRound $round, Partner $partner): array
    {
        $producer = $round->getProducer();
        if (null === $producer) {
            return [];
        }

        $previous = $this->orders->findLastForPartnerAndProducer($partner, $producer, $round->getId());
        if (null === $previous) {
            return [];
        }

        $byProduct = [];
        foreach ($previous->getLines() as $line) {
            $productId = $line->getRoundItem()?->getProduct()?->getId();
            if (null !== $productId && (float) $line->getQuantity() > 0) {
                $byProduct[$productId] = $line->getQuantity();
            }
        }

        $quantities = [];
        foreach ($round->getItems() as $item) {
            $productId = $item->getProduct()?->getId();
            if (null !== $productId && isset($byProduct[$productId])) {
                $quantities[$item->getId()] = $byProduct[$productId];
            }
        }

        return $quantities;
    }
}
