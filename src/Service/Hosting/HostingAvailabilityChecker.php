<?php

namespace App\Service\Hosting;

use App\Entity\Stay;
use App\Repository\StayRepository;

/**
 * Responde la pregunta operativa "¿cabe esta estancia sin pasarse de aforo?"
 * juntando las tres piezas: las estancias confirmadas que solapan
 * ({@see StayRepository}), el aforo de cada día ({@see HostingCapacityResolver})
 * y la aritmética pura de ocupación ({@see OccupancyChecker}).
 *
 * Es lo que invoca el guard al crear o editar una estancia. Trabaja sobre la
 * estancia CANDIDATA tal cual viene del formulario (aún sin persistir, o ya
 * persistida si se está editando): se excluye a sí misma por id para no contarse.
 */
final class HostingAvailabilityChecker
{
    public function __construct(
        private readonly StayRepository $stayRepository,
        private readonly HostingCapacityResolver $capacityResolver,
        private readonly OccupancyChecker $occupancyChecker,
    ) {
    }

    /**
     * Días del rango de la estancia candidata en los que confirmarla superaría
     * el aforo. Lista vacía = cabe. Sólo tiene sentido para estancias que vayan
     * a ocupar cama (confirmadas); una solicitud sin confirmar nunca da
     * conflicto, así que se devuelve vacío sin tocar BBDD.
     *
     * @param Stay $candidate Estancia a comprobar (con llegada y salida puestas).
     * @return \DateTimeImmutable[] Días en conflicto, en orden (vacío si cabe).
     */
    public function violationsFor(Stay $candidate): array
    {
        if (!$candidate->occupiesCapacity()) {
            return [];
        }

        $from = $candidate->getArrivalDate();
        $to = $candidate->getDepartureDate();

        $existing = $this->stayRepository->findConfirmedOverlapping($from, $to, $candidate->getId());
        $capacityForDay = $this->capacityResolver->capacityForDayBetween($from, $to->modify('-1 day'));

        return $this->occupancyChecker->violationsFor($candidate, $existing, $capacityForDay);
    }

    /**
     * ¿Cabe la estancia candidata sin superar el aforo ningún día?
     *
     * @param Stay $candidate
     * @return bool
     */
    public function fits(Stay $candidate): bool
    {
        return $this->violationsFor($candidate) === [];
    }
}
