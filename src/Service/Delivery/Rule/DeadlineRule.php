<?php

namespace App\Service\Delivery\Rule;

use App\Entity\Basket;
use App\Entity\Partner;

/**
 * Deadline: prohíbe pedir/cancelar el cambio puntual de viernes una vez
 * pasado el jueves 23:59 anterior al `from`. Pasado ese momento la
 * administración ya está cerrando el reparto y los cambios desordenan.
 *
 * **Bypassable**: admin puede saltarse esta regla si tiene que arreglar
 * algo a última hora. El socix nunca puede saltársela desde el panel.
 *
 * El valor del deadline es consistente con PanelController::PICKUP_DEADLINE_*
 * — si la asociación cambia el día/hora de cierre, basta con tocar
 * estas constantes en un solo sitio.
 */
final class DeadlineRule implements DeliveryShiftRule
{
    public const ID = 'deadline';

    /** ISO 8601: 4 = jueves. Mismo valor que el panel autoservicio. */
    private const DEADLINE_WEEKDAY = 4;
    private const DEADLINE_HOUR = 23;
    private const DEADLINE_MINUTE = 59;

    public function check(Partner $partner, Basket $from, Basket $to): ?DeliveryShiftViolation
    {
        $deadline = $this->deadlineFor($from);
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

    /**
     * Deadline efectivo para el Basket dado: jueves 23:59 (zona local)
     * anterior al viernes del Basket. Si la fecha del Basket cae en otro
     * día (excepción de calendario), se retrocede hasta el jueves más
     * cercano por seguridad.
     */
    private function deadlineFor(Basket $basket): ?\DateTimeImmutable
    {
        $date = $basket->getDate();
        if ($date === null) {
            return null;
        }

        $pickup = \DateTimeImmutable::createFromMutable($date);
        $diffDays = abs((int) $pickup->format('N') - self::DEADLINE_WEEKDAY);

        return $pickup
            ->setTime(self::DEADLINE_HOUR, self::DEADLINE_MINUTE, 0)
            ->modify(sprintf('-%d days', $diffDays));
    }
}
