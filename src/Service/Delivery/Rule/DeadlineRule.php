<?php

namespace App\Service\Delivery\Rule;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Service\Delivery\DeliveryDeadline;

/**
 * Deadline: prohíbe pedir/cancelar el cambio puntual de viernes una vez
 * pasado el plazo de cierre. El cálculo del plazo (antelación en días + hora,
 * configurables en /gestion/settings, sobre la fecha física del reparto
 * del nodo) vive en {@see DeliveryDeadline}, compartido con el autoservicio del
 * panel para que admin y socix vean exactamente el mismo plazo. Por defecto,
 * el día anterior al reparto a las 23:59.
 *
 * **Bypassable**: admin puede saltarse esta regla si tiene que arreglar
 * algo a última hora. El socix nunca puede saltársela desde el panel.
 */
final class DeadlineRule implements DeliveryShiftRule
{
    public const ID = 'deadline';

    public function __construct(
        private readonly DeliveryDeadline $deadline,
    ) {
    }

    public function check(Partner $partner, Basket $from, Basket $to): ?DeliveryShiftViolation
    {
        // null = sin plazo aplicable (Basket sin fecha, o el nodo no reparte
        // ese viernes) ⇒ no hay nada que prohibir.
        $deadline = $this->deadline->forPartnerAndBasket($partner, $from);
        if ($deadline === null) {
            return null;
        }

        if (new \DateTimeImmutable('now') < $deadline) {
            return null;
        }

        return new DeliveryShiftViolation(
            self::ID,
            sprintf(
                'El plazo para pedir un cambio puntual de viernes terminó el %s. La administración puede forzar el cambio fuera de plazo si fuera necesario.',
                $deadline->format('d/m/Y H:i'),
            ),
            bypassable: true,
        );
    }
}
