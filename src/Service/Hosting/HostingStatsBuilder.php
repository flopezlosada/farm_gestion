<?php

namespace App\Service\Hosting;

use App\Entity\Stay;
use App\Repository\StayRepository;

/**
 * Métricas de OCUPACIÓN del albergue para un año: cómo de aprovechado está
 * (camas-noche usadas vs disponibles), cuánto se queda la gente de media y cuál
 * es el mes más lleno. A diferencia de un conteo por procedencia (que ya se ve
 * en el listado), esto dice algo operativo aunque haya poca gente.
 *
 * Camas-noche USADAS = suma, día a día del año, de las camas ocupadas por
 * estancias confirmadas. DISPONIBLES = suma del aforo de cada día (físico, o el
 * operativo del mes, 0 si está cerrado). El % de ocupación es su cociente; puede
 * pasar de 100% si algún día se metió más gente que plazas (sobre aforo).
 */
final class HostingStatsBuilder
{
    public function __construct(
        private readonly StayRepository $stayRepository,
        private readonly HostingCapacityResolver $capacityResolver,
        private readonly OccupancyChecker $occupancyChecker,
    ) {
    }

    /**
     * Métricas de ocupación del año dado.
     *
     * @param int $year
     * @return array{
     *     year: int, used_nights: int, available_nights: int, occupancy_pct: int,
     *     avg_stay: float, helpers: int, stays: int, busiest_month: int|null, busiest_peak: int
     * }
     */
    public function forYear(int $year): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-01-01', $year));
        $to = new \DateTimeImmutable(sprintf('%04d-01-01', $year + 1));

        // findOverlapping (no findConfirmedOverlapping) porque trae el Helper en
        // el mismo SELECT: el bucle de abajo lo necesita y así no hay N+1.
        $stays = $this->stayRepository->findOverlapping($from, $to, [Stay::STATUS_CONFIRMED]);
        $capacityForDay = $this->capacityResolver->capacityForDayBetween($from, $to->modify('-1 day'));

        $used = 0;
        $available = 0;
        $peakByMonth = [];
        for ($day = $from; $day < $to; $day = $day->modify('+1 day')) {
            $occupied = $this->occupancyChecker->occupancyOn($day, $stays);
            $used += $occupied;
            $available += $capacityForDay($day);
            $month = (int) $day->format('n');
            $peakByMonth[$month] = max($peakByMonth[$month] ?? 0, $occupied);
        }

        $helperIds = [];
        $nightsSum = 0;
        foreach ($stays as $stay) {
            $helperIds[$stay->getHelper()->getId()] = true;
            $nightsSum += (int) $stay->getArrivalDate()->diff($stay->getDepartureDate())->format('%a');
        }
        $staysCount = count($stays);

        $busiestMonth = null;
        $busiestPeak = 0;
        foreach ($peakByMonth as $month => $peak) {
            if ($peak > $busiestPeak) {
                $busiestPeak = $peak;
                $busiestMonth = $month;
            }
        }

        return [
            'year' => $year,
            'used_nights' => $used,
            'available_nights' => $available,
            'occupancy_pct' => $available > 0 ? (int) round($used / $available * 100) : 0,
            'avg_stay' => $staysCount > 0 ? round($nightsSum / $staysCount, 1) : 0.0,
            'helpers' => count($helperIds),
            'stays' => $staysCount,
            'busiest_month' => $busiestMonth,
            'busiest_peak' => $busiestPeak,
        ];
    }
}
