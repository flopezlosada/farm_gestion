<?php

namespace App\Service\Hosting;

use App\Entity\HostingCapacity;
use App\Repository\HostingCapacityRepository;
use App\Service\AppSettings;

/**
 * Resuelve el aforo EFECTIVO de cada día del albergue. Es el puente entre el
 * mundo de datos (las filas {@see HostingCapacity} por mes y el aforo físico de
 * referencia en {@see AppSettings}) y el {@see OccupancyChecker}, que es puro y
 * sólo sabe pedir "¿cuántas plazas hay este día?" a través de un callable.
 *
 * Regla de resolución de un día:
 *   - si su mes tiene fila {@see HostingCapacity}, manda su aforo efectivo
 *     (0 si el mes está cerrado, su capacidad operativa si está abierto);
 *   - si el mes no tiene fila, se cae al aforo FÍSICO de referencia
 *     ({@see AppSettings::HOSTING_PHYSICAL_CAPACITY}): un mes sin configurar
 *     hereda el aforo de la casa, no se asume 0.
 */
final class HostingCapacityResolver
{
    public function __construct(
        private readonly HostingCapacityRepository $repository,
        private readonly AppSettings $settings,
    ) {
    }

    /**
     * Aforo efectivo de un día suelto. Una query por llamada: para comprobar
     * rangos usa {@see self::capacityForDayBetween()}, que precarga.
     *
     * @param \DateTimeImmutable $day
     * @return int
     */
    public function capacityOn(\DateTimeImmutable $day): int
    {
        $row = $this->repository->findForYearMonth((int) $day->format('Y'), (int) $day->format('n'));

        return $row?->effectiveCapacity() ?? $this->physicalDefault();
    }

    /**
     * Devuelve un callable `capacityForDay` listo para el {@see OccupancyChecker},
     * con las filas de aforo del rango [$from, $to] precargadas en UNA query.
     * Resuelve cada día por su mes, cayendo al aforo físico cuando el mes no
     * tiene fila. El callable se puede invocar para cualquier día; los de fuera
     * del rango precargado simplemente caen al default (no fallan).
     *
     * @param \DateTimeImmutable $from Primer día a cubrir (incluido).
     * @param \DateTimeImmutable $to   Último día a cubrir (incluido).
     * @return callable(\DateTimeImmutable): int
     */
    public function capacityForDayBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): callable
    {
        $keyed = $this->repository->findKeyedByMonth(
            (int) $from->format('Y'),
            (int) $from->format('n'),
            (int) $to->format('Y'),
            (int) $to->format('n'),
        );
        $default = $this->physicalDefault();

        return static function (\DateTimeImmutable $day) use ($keyed, $default): int {
            $row = $keyed[$day->format('Y-m')] ?? null;

            return $row?->effectiveCapacity() ?? $default;
        };
    }

    /**
     * Aforo físico de referencia (default de los meses sin configurar).
     *
     * @return int
     */
    private function physicalDefault(): int
    {
        return $this->settings->getInt(AppSettings::HOSTING_PHYSICAL_CAPACITY);
    }
}
