<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Partner;

/**
 * Añadir (SUMAR) cestas/huevos a la entrega de un socio en una semana, segregado de
 * {@see ExtraBasketEditor} —`final` por convención de la casa— para que quien solo
 * necesita esa acción dependa de una abstracción testeable con un doble. Symfony
 * auto-aliasa la interfaz a su único implementador.
 */
interface ExtraBasketAdder
{
    /**
     * @param array<int, string> $addAmounts Incremento por componente, [BasketComponent id => cantidad].
     */
    public function addToDelivery(
        Partner $partner,
        Basket $basket,
        array $addAmounts,
        ?string $note = null,
        ?string $actor = null,
    ): void;
}
