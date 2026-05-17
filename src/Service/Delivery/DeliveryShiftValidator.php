<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Service\Delivery\Rule\DeliveryShiftRule;
use App\Service\Delivery\Rule\DeliveryShiftViolation;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Valida un cambio puntual de viernes pasando la solicitud por todas
 * las reglas registradas. Devuelve la lista de violaciones —
 * separadas en bloqueantes y "soft" (bypassables por admin) —
 * y deja la decisión final a quien lo invoca (panel del socix o
 * controller de admin).
 *
 * Las reglas se inyectan como iterable etiquetado con
 * `app.delivery_shift_rule`. Para añadir una regla nueva basta con
 * crear la clase: el contenedor la incluye sin tocar este servicio.
 */
final class DeliveryShiftValidator
{
    /**
     * @param iterable<DeliveryShiftRule> $rules
     */
    public function __construct(
        #[AutowireIterator('app.delivery_shift_rule')]
        private readonly iterable $rules,
    ) {
    }

    /**
     * Ejecuta todas las reglas y devuelve la lista completa de violaciones.
     *
     * @return DeliveryShiftViolation[]
     */
    public function validate(Partner $partner, Basket $from, Basket $to): array
    {
        $violations = [];
        foreach ($this->rules as $rule) {
            $v = $rule->check($partner, $from, $to);
            if ($v !== null) {
                $violations[] = $v;
            }
        }
        return $violations;
    }

    /**
     * Filtra las violaciones por bloqueantes (no-bypassables).
     *
     * @param DeliveryShiftViolation[] $violations
     * @return DeliveryShiftViolation[]
     */
    public function blocking(array $violations): array
    {
        return array_values(array_filter($violations, fn (DeliveryShiftViolation $v) => !$v->bypassable));
    }

    /**
     * Filtra las violaciones bypassables (admin puede ignorarlas).
     *
     * @param DeliveryShiftViolation[] $violations
     * @return DeliveryShiftViolation[]
     */
    public function bypassable(array $violations): array
    {
        return array_values(array_filter($violations, fn (DeliveryShiftViolation $v) => $v->bypassable));
    }
}
