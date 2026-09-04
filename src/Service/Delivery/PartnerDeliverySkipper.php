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
     *
     * @param Basket|null $accumulatedTo Semana a cuya entrega se SUMÓ esta cesta, si el día
     *                                   se vacía por un traslado sumando en vez de por un
     *                                   "no recoge". Se marca en el mismo flush para que la
     *                                   cesta no quede ni un instante contada dos veces
     *                                   (pendiente aquí y sumada allí).
     */
    public function applySkipIntent(Partner $partner, Basket $basket, ?BasketComponent $component, ?string $actor = null, ?Basket $accumulatedTo = null): PartnerDeliveryShift;

    /**
     * "No recoge" una entrega que VINO MOVIDA a este día: re-apunta el cambio entrante a to=null.
     *
     * @param Basket|null $accumulatedTo Igual que en {@see self::applySkipIntent()}.
     */
    public function skipMovedDelivery(PartnerDeliveryShift $incoming, ?string $actor = null, ?Basket $accumulatedTo = null): void;

    /**
     * Cancela un intent sin destino: la entrega de esa semana vuelve a su patrón. Solo
     * borra el intent; en una semana YA generada, re-materializar la entrega es cosa del
     * llamante (ver el docblock de la implementación).
     */
    public function cancelSkipIntent(PartnerDeliveryShift $shift, ?string $actor = null): void;
}
