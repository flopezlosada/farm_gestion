<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\PartnerBasketShare;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base común de las leyes: EntityManager inyectado y helpers de formato y
 * de resolución de la suscripción vigente. Las leyes NUNCA escriben.
 */
abstract class AbstractInvariant implements DeliveryInvariant
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
    ) {
    }

    public function severity(): string
    {
        return self::SEVERITY_ERROR;
    }

    /**
     * Fecha corta para mensajes de violación.
     *
     * @param \DateTimeInterface|null $date
     * @return string
     */
    protected function d(?\DateTimeInterface $date): string
    {
        return $date?->format('d/m/Y') ?? '¿fecha?';
    }

    /**
     * La suscripción (PBS) vigente de entre las dadas en una fecha concreta:
     * cuyo rango [start_date, end_date] cubre la fecha. Un PBS INACTIVO pero
     * acotado por end_date también cubre su rango: el cambio de modalidad
     * desactiva el viejo dejándole end_date, y las semanas anteriores al
     * cambio siguen siendo legítimamente suyas. Un PBS inactivo SIN end_date
     * no cubre nada (desactivado a secas). Con varias candidatas gana la
     * activa — la ley de "un solo PBS activo" vive en
     * ValidatePartnersConsistencyCommand, no aquí.
     *
     * @param iterable<PartnerBasketShare> $shares Candidatas (del mismo socio).
     * @param \DateTimeInterface           $on     Fecha de evaluación.
     * @return PartnerBasketShare|null
     */
    protected function pbsActiveAt(iterable $shares, \DateTimeInterface $on): ?PartnerBasketShare
    {
        $day = $on->format('Y-m-d');
        $fallback = null;
        foreach ($shares as $pbs) {
            if (!$pbs->getIsActive() && $pbs->getEndDate() === null) {
                continue;
            }
            $start = $pbs->getStartDate()?->format('Y-m-d');
            $end = $pbs->getEndDate()?->format('Y-m-d');
            if (($start === null || $start <= $day) && ($end === null || $end >= $day)) {
                if ($pbs->getIsActive()) {
                    return $pbs;
                }
                $fallback ??= $pbs;
            }
        }

        return $fallback;
    }
}
