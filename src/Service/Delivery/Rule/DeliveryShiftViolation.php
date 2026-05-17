<?php

namespace App\Service\Delivery\Rule;

/**
 * Resultado del chequeo de una regla. Si la regla considera que el
 * cambio puntual de viernes infringe su criterio, devuelve una de
 * estas. Si todo está bien, la regla devuelve null.
 *
 * El flag `bypassable` indica si admin puede ignorar esta violación
 * y forzar el cambio. Las violaciones no-bypassable son siempre
 * bloqueantes (ni admin las puede saltar).
 */
final class DeliveryShiftViolation
{
    public function __construct(
        public readonly string $rule,
        public readonly string $message,
        public readonly bool $bypassable,
    ) {
    }
}
