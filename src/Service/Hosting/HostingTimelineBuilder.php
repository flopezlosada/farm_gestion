<?php

namespace App\Service\Hosting;

use App\Entity\Stay;
use App\Repository\StayRepository;

/**
 * Construye el calendario de ocupación del albergue (vista timeline/Gantt) para
 * una ventana de fechas, en dos granularidades: por DÍAS (detalle, semanas a la
 * vista) o por MESES (panorámica de un año). Junta las tres piezas —estancias
 * ({@see StayRepository}), aforo ({@see HostingCapacityResolver}) y ocupación
 * ({@see OccupancyChecker})— y devuelve una estructura PLANA de columnas lista
 * para pintar; la plantilla no hace cálculo y es la misma para ambas vistas.
 *
 * Sólo confirmadas y solicitadas entran (las canceladas no). La ocupación cuenta
 * sólo confirmadas (lo decide {@see OccupancyChecker}); las solicitadas aparecen
 * como barras pero no consumen aforo. En la vista mensual, la ocupación de cada
 * mes es el PICO de ocupación de sus días (el peor caso del mes).
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
     * Timeline por DÍAS para la ventana [$from, $to). Cada columna es un día.
     *
     * @param \DateTimeImmutable $from Primer día (incluido).
     * @param \DateTimeImmutable $to   Primer día NO mostrado (excluido).
     * @return array{unit: string, from: \DateTimeImmutable, to: \DateTimeImmutable, col_count: int, cols: list<array{date: \DateTimeImmutable, weekend: bool, occupied: int, capacity: int, state: string}>, rows: list<array{helper: \App\Entity\Helper, segments: list<array{offset: int, span: int, status: string, clipped_left: bool, clipped_right: bool}>}>}
     */
    public function buildDaily(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $from = $from->setTime(0, 0, 0);
        $to = $to->setTime(0, 0, 0);
        $dayCount = max(0, $this->daysBetween($from, $to));

        $stays = $this->stayRepository->findOverlapping($from, $to);
        $capacityForDay = $this->capacityResolver->capacityForDayBetween($from, $to->modify('-1 day'));

        $cols = [];
        for ($i = 0; $i < $dayCount; $i++) {
            $date = $from->modify(sprintf('+%d day', $i));
            $occupied = $this->occupancyChecker->occupancyOn($date, $stays);
            $capacity = $capacityForDay($date);
            $cols[] = [
                'date' => $date,
                'weekend' => in_array($date->format('N'), ['6', '7'], true),
                'occupied' => $occupied,
                'capacity' => $capacity,
                'state' => $this->state($occupied, $capacity),
            ];
        }

        return [
            'unit' => 'day',
            'from' => $from,
            'to' => $to,
            'col_count' => $dayCount,
            'cols' => $cols,
            'rows' => $this->buildRows($stays, $from, $to, $dayCount, fn (\DateTimeImmutable $d) => $this->daysBetween($from, $d)),
        ];
    }

    /**
     * Timeline por MESES: $monthCount meses desde el mes de $from. Cada columna
     * es un mes; su ocupación es el pico de ocupación de los días del mes.
     *
     * @param \DateTimeImmutable $from       Cualquier día del primer mes.
     * @param int                $monthCount Nº de meses a mostrar.
     * @return array{unit: string, from: \DateTimeImmutable, to: \DateTimeImmutable, col_count: int, cols: list<array{date: \DateTimeImmutable, weekend: bool, occupied: int, capacity: int, state: string}>, rows: list<array{helper: \App\Entity\Helper, segments: list<array{offset: int, span: int, status: string, clipped_left: bool, clipped_right: bool}>}>}
     */
    public function buildMonthly(\DateTimeImmutable $from, int $monthCount): array
    {
        $monthCount = max(1, $monthCount);
        $from = $from->modify('first day of this month')->setTime(0, 0, 0);
        $to = $from->modify(sprintf('+%d months', $monthCount));

        $stays = $this->stayRepository->findOverlapping($from, $to);
        $capacityForDay = $this->capacityResolver->capacityForDayBetween($from, $to->modify('-1 day'));

        $cols = [];
        for ($m = 0; $m < $monthCount; $m++) {
            $monthStart = $from->modify(sprintf('+%d months', $m));
            $monthEnd = $monthStart->modify('+1 month');
            $peak = 0;
            for ($d = $monthStart; $d < $monthEnd; $d = $d->modify('+1 day')) {
                $peak = max($peak, $this->occupancyChecker->occupancyOn($d, $stays));
            }
            $capacity = $capacityForDay($monthStart);
            $cols[] = [
                'date' => $monthStart,
                'weekend' => false,
                'occupied' => $peak,
                'capacity' => $capacity,
                'state' => $this->state($peak, $capacity),
                'active' => $peak > 0,
            ];
        }

        $rows = $this->buildRows($stays, $from, $to, $monthCount, fn (\DateTimeImmutable $d) => $this->monthIndex($from, $d));

        // Un mes es "activo" (se pinta ancho) si tiene ocupación o lo atraviesa
        // alguna estancia; los meses muertos se colapsan a una columna estrecha.
        foreach ($rows as $row) {
            foreach ($row['segments'] as $segment) {
                for ($i = $segment['offset']; $i < $segment['offset'] + $segment['span']; $i++) {
                    if (isset($cols[$i])) {
                        $cols[$i]['active'] = true;
                    }
                }
            }
        }

        return [
            'unit' => 'month',
            'from' => $from,
            'to' => $to,
            'col_count' => $monthCount,
            'cols' => $cols,
            'rows' => $rows,
        ];
    }

    /**
     * Estado de una columna: cerrado (aforo 0 sin nadie), sobre aforo (más
     * ocupadas que plazas), lleno (justo el aforo) o libre.
     *
     * @param int $occupied
     * @param int $capacity
     * @return string closed|over|full|free
     */
    private function state(int $occupied, int $capacity): string
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
     * Una fila por voluntario (en el orden del repo) con sus estancias recortadas
     * a la ventana como segmentos. $columnOf mapea una fecha a su índice de
     * columna (día o mes), lo que hace genérica la colocación para ambas vistas.
     *
     * @param Stay[]                                  $stays
     * @param \DateTimeImmutable                      $from
     * @param \DateTimeImmutable                      $to
     * @param int                                     $colCount
     * @param callable(\DateTimeImmutable): int       $columnOf
     * @return list<array{helper: \App\Entity\Helper, segments: list<array{offset: int, span: int, status: string, clipped_left: bool, clipped_right: bool}>}>
     */
    private function buildRows(array $stays, \DateTimeImmutable $from, \DateTimeImmutable $to, int $colCount, callable $columnOf): array
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
            if ($clippedEnd <= $clippedStart) {
                continue;
            }

            // El último día ocupado es el anterior a la salida (intervalo
            // medio-abierto); su columna marca el final del segmento.
            $offset = $columnOf($clippedStart);
            $lastCol = $columnOf($clippedEnd->modify('-1 day'));
            $span = $lastCol - $offset + 1;
            if ($span <= 0) {
                continue;
            }

            $rows[$helperId]['segments'][] = [
                'offset' => $offset,
                'span' => min($span, $colCount - $offset),
                'status' => $stay->getStatus(),
                'clipped_left' => $start < $from,
                'clipped_right' => $end > $to,
            ];
        }

        return array_values($rows);
    }

    /**
     * Días enteros entre dos fechas a medianoche (con signo). A salvo de DST.
     *
     * @param \DateTimeImmutable $a
     * @param \DateTimeImmutable $b
     * @return int
     */
    private function daysBetween(\DateTimeImmutable $a, \DateTimeImmutable $b): int
    {
        return (int) $a->diff($b)->format('%r%a');
    }

    /**
     * Índice de mes de $date relativo al mes de $from (0 = mismo mes).
     *
     * @param \DateTimeImmutable $from
     * @param \DateTimeImmutable $date
     * @return int
     */
    private function monthIndex(\DateTimeImmutable $from, \DateTimeImmutable $date): int
    {
        return ((int) $date->format('Y') - (int) $from->format('Y')) * 12
            + ((int) $date->format('n') - (int) $from->format('n'));
    }
}
