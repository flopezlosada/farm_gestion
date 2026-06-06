<?php

namespace App\Service\Delivery\Rule;

use App\Entity\Basket;
use App\Entity\Partner;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contrato de las reglas que validan un cambio puntual de viernes.
 *
 * Cada regla representa un criterio independiente (modalidad permitida,
 * equilibrio de carga entre viernes, deadline temporal, etc). El servicio
 * DeliveryShiftValidator las invoca en cadena y devuelve la lista de
 * violaciones a quien pidió el cambio.
 *
 * Añadir una regla nueva = crear una clase que implemente esta interfaz.
 * El AutoconfigureTag de abajo + autoconfigure: true en services.yaml
 * hace que el contenedor la incluya automáticamente en el iterable
 * inyectado al validator.
 */
#[AutoconfigureTag('app.delivery_shift_rule')]
interface DeliveryShiftRule
{
    /**
     * Comprueba si el intercambio from→to para este Partner respeta el
     * criterio de la regla.
     *
     * @return DeliveryShiftViolation|null Violación con el motivo, o null si todo OK.
     */
    public function check(Partner $partner, Basket $from, Basket $to): ?DeliveryShiftViolation;
}
