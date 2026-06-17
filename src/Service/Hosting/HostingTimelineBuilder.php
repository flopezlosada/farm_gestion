<?php

namespace App\Service\Hosting;

use App\Entity\Stay;
use App\Repository\StayRepository;

/**
 * Construye el calendario de ocupación del albergue (vista timeline/Gantt) para
 * una ventana de fechas: una fila por voluntario con sus estancias como barras,
 * y la ocupación de cada día con su estado (libre / lleno / sobre aforo /
 * cerrado). Junta las tres piezas —estancias ({@see StayRepository}), aforo
 * ({@see HostingCapacityResolver}) y ocupación ({@see OccupancyChecker})— y
 * devuelve datos planos listos para pintar; la plantilla no hace cálculo.
 *
 * Sólo confirmadas y solicitadas entran (las canceladas no se muestran). La
 * ocupación cuenta sólo confirmadas (lo decide {@see OccupancyChecker}); las
 * solicitadas aparecen como barras pero no consumen aforo, para ver lo que está
 * por confirmar sin falsear el lleno.
 */
final class HostingTimelineBuilder
{
    public function __construct(
        private readonly StayRepository $stayRepository,
        private readonly HostingCapacityResolver $capacityResolver,
        private readonly OccupancyChecker $occupancyChecker,
    ) {
    }

    /**
     * Datos del timeline para la ventana [$from, $to) (ambos normalizados a día).
     *
     * @param \DateTimeImmutable $from Primer día mostrado (incluido).
     * @param \DateTimeImmutable $to   Primer día NO mostrado (excluido).
     * @return array{
     *     from: \DateTimeImmutable,
     *     to: \DateTimeImmutable,
     *     day_count: int,
     *     days: list<array{date: \DateTimeImmutable, occupied: int, capacity: int, state: string}>,
     *     rows: list<array{helper: \App\Entity\Helper, segments: list<array{offset: int, span: int, status: string, clipped_left: bool, clipped_right: bool}>}>
     * }
     */
    public function build(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $from = $from->setTime(0, 0, 0);
        $to = $to->setTime(0, 0, 0);
        $dayCount = max(0, $this->daysBetween($from, $to));

        $stays = $this->stayRepository->findOverlapping($from, $to);
        $capacityForDay = $this->capacityResolver->capacityForDayBetween($from, $to->modify('-1 day'));

        return [
            'from' => $from,
            'to' => $to,
            'day_count' => $dayCount,
            'days' => $this->buildDays($from, $dayCount, $stays, $capacityForDay),
            'rows' => $this->buildRows($from, $to, $dayCount, $stays),
        ];
    }

    /**
     * Ocupación y estado de cada día de la ventana.
     *
     * @param \DateTimeImmutable                $from
     * @param int                               $dayCount
     * @param Stay[]                            $stays
     * @param callable(\DateTimeImmutable): int $capacityForDay
     * @return list<array{date: \DateTimeImmutable, occupied: int, capacity: int, state: string}>
     */
    private function buildDays(\DateTimeImmutable $from, int $dayCount, array $stays, callable $capacityForDay): array
    {
        $days = [];
        for ($i = 0; $i < $dayCount; $i++) {
            $date = $from->modify(sprintf('+%d day', $i));
            $occupied = $this->occupancyChecker->occupancyOn($date, $stays);
            $capacity = $capacityForDay($date);
            $days[] = [
                'date' => $date,
                'occupied' => $occupied,
                'capacity' => $capacity,
                'state' => $this->dayState($occupied, $capacity),
            ];
        }

        return $days;
    }

    /**
     * Estado de un día: cerrado (aforo 0 sin nadie), sobre aforo (más ocupadas
     * que plazas), lleno (justo el aforo) o libre.
     *
     * @param int $occupied
     * @param int $capacity
     * @return string closed|over|full|free
     */
    private function dayState(int $occupied, int $capacity): string
    {
        if ($capacity <= 0) {
            return $occupied > 0 ? 'over' : 'closed';
        }
        if ($occupied > $capacity) {
            return 'over';
        }

        return $occupied === $capacity ? 'full' : 'free';
    }

    /**
     * Una fila por voluntario (en el orden que trae el repo) con sus estancias
     * recortadas a la ventana como segmentos posicionables.
     *
     * @param \DateTimeImmutable $from
     * @param \DateTimeImmutable $to
     * @param int                $dayCount
     * @param Stay[]             $stays
     * @return list<array{helper: \App\Entity\Helper, segments: list<array{offset: int, span: int, status: string, clipped_left: bool, clipped_right: bool}>}>
     */
    private function buildRows(\DateTimeImmutable $from, \DateTimeImmutable $to, int $dayCount, array $stays): array
    {
        $rows = [];
        foreach ($stays as $stay) {
            $helper = $stay->getHelper();
            $helperId = $helper->getId();
            if (!isset($rows[$helperId])) {
                $rows[$helperId] = ['helper' => $helper, 'segments' => []];
            }

            $start = $stay->getArrivalDate()->setTime(0, 0, 0);
            $end = $stay->getDepartureDate()->setTime(0, 0, 0);
            $clippedStart = $start < $from ? $from : $start;
            $clippedEnd = $end > $to ? $to : $end;

            $offset = $this->daysBetween($from, $clippedStart);
            $span = $this->daysBetween($clippedStart, $clippedEnd);
            if ($span <= 0) {
                continue;
            }

            $rows[$helperId]['segments'][] = [
                'offset' => $offset,
                'span' => min($span, $dayCount - $offset),
                'status' => $stay->getStatus(),
                'clipped_left' => $start < $from,
                'clipped_right' => $end > $to,
            ];
        }

        return array_values($rows);
    }

    /**
     * Días enteros entre dos fechas a medianoche (con signo). Las fechas vienen
     * sin hora, así que el cálculo es exacto y a salvo de horario de verano.
     *
     * @param \DateTimeImmutable $a
     * @param \DateTimeImmutable $b
     * @return int
     */
    private function daysBetween(\DateTimeImmutable $a, \DateTimeImmutable $b): int
    {
        return (int) $a->diff($b)->format('%r%a');
    }
}
