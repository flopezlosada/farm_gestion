<?php

namespace App\Service\Delivery;

use App\Entity\Partner;

/**
 * Lo que hace falta para avisar a un socix de su reparto por el móvil y en su
 * bandeja: a quién, qué recoge, cuándo y dónde.
 *
 * Existe para que el recordatorio y la confirmación avisen por la MISMA vía
 * ({@see PickupNoticePusher}) sin que ésta tenga que saber de dónde viene cada
 * uno: el recordatorio parte de cestas materializadas y la confirmación de una
 * audiencia que incluye a quien NO recoge.
 *
 * Lo que recoge es nullable a propósito y no un "recoge sí/no" aparte: así no
 * puede existir un aviso que diga que recoge sin decir qué.
 */
final class PickupNotice
{
    /**
     * @param Partner            $partner    Socix a quien se avisa.
     * @param string|null        $contents   Lo que recoge, ya en texto ("de verduras y huevos"), o null si no recoge.
     * @param \DateTimeImmutable $pickupDate Fecha física del reparto.
     * @param string|null        $nodeName   Nodo del reparto, si lo hay.
     * @param bool               $wasShifted Si el reparto cae fuera del día habitual del nodo (festivo).
     */
    public function __construct(
        public readonly Partner $partner,
        public readonly ?string $contents,
        public readonly \DateTimeImmutable $pickupDate,
        public readonly ?string $nodeName,
        public readonly bool $wasShifted,
    ) {
    }
}
