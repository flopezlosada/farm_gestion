<?php

namespace App\Service\Delivery\Rule;

use App\Entity\Basket;
use App\Entity\Partner;
use App\Service\Delivery\NodeDeliveryDate;

/**
 * Deadline: prohíbe pedir/cancelar el cambio puntual de viernes una vez
 * pasado el día anterior al reparto físico del nodo del partner a las
 * 23:59. Pasado ese momento la administración ya está cerrando el reparto
 * y los cambios desordenan.
 *
 * **Bypassable**: admin puede saltarse esta regla si tiene que arreglar
 * algo a última hora. El socix nunca puede saltársela desde el panel.
 *
 * Cambio sub-fase 8.8b2 (2026-05-26): hasta ahora el deadline asumía
 * Basket=viernes y restaba abs(N-4) días al pickup. Funcionaba sólo
 * porque todos los Basket eran viernes. Con la entrada de Madrid (Cascorro
 * + Midori, miércoles) ese cálculo daba deadlines absurdos. Ahora el
 * deadline se calcula sobre la fecha física del reparto del nodo
 * (vía NodeDeliveryDate), no sobre el viernes-ciclo.
 *
 * Deuda: si la operativa real requiere "previous business day con un
 * laboral por medio para preparar cosecha" (ej. miércoles físico →
 * deadline lunes 23:59, no martes), reabrir esto. De momento usamos
 * la regla simple "día anterior al reparto físico" que es lo que la
 * UI legacy hacía para Torremocha.
 */
final class DeadlineRule implements DeliveryShiftRule
{
    public const ID = 'deadline';

    private const DEADLINE_HOUR = 23;
    private const DEADLINE_MINUTE = 59;

    public function __construct(
        private readonly NodeDeliveryDate $nodeDeliveryDate,
    ) {
    }

    public function check(Partner $partner, Basket $from, Basket $to): ?DeliveryShiftViolation
    {
        $deadline = $this->deadlineFor($from, $partner);
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
     * Calcula el deadline efectivo para que `$partner` cambie el `$basket`.
     *
     * Estrategia:
     *   1. Si el partner pertenece a un nodo configurado, resolver la
     *      fecha física de reparto del nodo en ese Basket vía
     *      NodeDeliveryDate.
     *   2. Si el nodo no reparte en ese Basket (cadence biweekly fuera
     *      de fase), no hay deadline aplicable: devuelve null.
     *   3. El deadline es el día anterior a la fecha física, a las 23:59
     *      hora local.
     *
     * Fallback legacy: si el partner no tiene nodo asignado (datos
     * pre-Node), se asume el viernes-ciclo como día de reparto físico y
     * el deadline cae el día anterior al viernes (jueves 23:59).
     *
     * @param Basket $basket Cesta-ciclo (un viernes).
     * @param Partner $partner Socio que pide el cambio.
     * @return \DateTimeImmutable|null
     */
    private function deadlineFor(Basket $basket, Partner $partner): ?\DateTimeImmutable
    {
        $node = $partner->getWeeklyBasketGroup()?->getNode();

        if ($node !== null) {
            $physicalDate = $this->nodeDeliveryDate->physicalDateFor($basket, $node);
            if ($physicalDate === null) {
                return null;
            }
            return $physicalDate
                ->modify('-1 day')
                ->setTime(self::DEADLINE_HOUR, self::DEADLINE_MINUTE, 0);
        }

        // Fallback sin nodo: usar la fecha del Basket como día físico.
        $date = $basket->getDate();
        if ($date === null) {
            return null;
        }

        $pickup = $date instanceof \DateTimeImmutable
            ? $date
            : \DateTimeImmutable::createFromInterface($date);

        return $pickup
            ->modify('-1 day')
            ->setTime(self::DEADLINE_HOUR, self::DEADLINE_MINUTE, 0);
    }
}
