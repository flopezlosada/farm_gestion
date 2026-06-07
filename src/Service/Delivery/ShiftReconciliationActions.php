<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\PartnerDeliveryShift;

/**
 * Las acciones sobre cambios puntuales (PartnerDeliveryShift) que necesita la
 * reconciliación al cerrar una semana. Segrega de {@see DeliveryShiftApplier}
 * solo lo que {@see ClosureShiftReconciler} usa, para que el reconciliador
 * dependa de una abstracción (testeable con un doble) y no del servicio concreto
 * —que es `final` por convención de la casa—.
 *
 * Symfony auto-aliasa la interfaz a su único implementador (DeliveryShiftApplier).
 */
interface ShiftReconciliationActions
{
    /**
     * Cancela un cambio puntual de ENTREGA ENTERA (mover) y revierte su cascada.
     */
    public function cancel(PartnerDeliveryShift $shift, ?string $actor = null): void;

    /**
     * Cancela un intent de "no recoge" (to = null).
     */
    public function cancelSkipIntent(PartnerDeliveryShift $shift, ?string $actor = null): void;

    /**
     * Re-apunta el destino de un cambio puntual a otra semana, conservando su origen.
     */
    public function repointTarget(PartnerDeliveryShift $shift, Basket $newTarget, ?string $actor = null): void;
}
