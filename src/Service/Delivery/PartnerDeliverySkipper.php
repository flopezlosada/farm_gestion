<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;

/**
 * Vaciar la entrega de un socio en una semana ("no recoge"), segregado de
 * {@see DeliveryShiftApplier} —`final` por convención de la casa— para que quien
 * solo necesita vaciar un día dependa de una abstracción testeable con un doble.
 * Symfony auto-aliasa la interfaz a su único implementador.
 */
interface PartnerDeliverySkipper
{
    /**
     * "No recoge" un día de patrón (crea un intent de skip; retira el WB si estaba materializado).
     */
    public function applySkipIntent(Partner $partner, Basket $basket, ?BasketComponent $component, ?string $actor = null): PartnerDeliveryShift;

    /**
     * "No recoge" una entrega que VINO MOVIDA a este día: re-apunta el cambio entrante a to=null.
     */
    public function skipMovedDelivery(PartnerDeliveryShift $incoming, ?string $actor = null): void;
}
