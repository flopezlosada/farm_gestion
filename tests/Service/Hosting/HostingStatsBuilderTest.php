<?php

namespace App\Tests\Service\Hosting;

use App\Entity\Helper;
use App\Entity\Stay;
use App\Repository\HostingCapacityRepository;
use App\Repository\StayRepository;
use App\Service\AppSettings;
use App\Service\Hosting\HostingCapacityResolver;
use App\Service\Hosting\HostingStatsBuilder;
use App\Service\Hosting\OccupancyChecker;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del HostingStatsBuilder (métricas de ocupación). Mockea las
 * fronteras (repos + settings) y usa la lógica real (resolver + checker).
 * Verifica camas-noche usadas/disponibles, % de ocupación, estancia media y el
 * mes más lleno.
 */
class HostingStatsBuilderTest extends TestCase
{
    public function testMetricasDeOcupacion(): void
    {
        $ana = $this->helper(1);
        $beto = $this->helper(2);

        // Julio: Ana 01→11 (10 noches), Beto 05→08 (3 noches) solapando 5,6,7.
        $stays = [
            $this->stay($ana, '2026-07-01', '2026-07-11'),
            $this->stay($beto, '2026-07-05', '2026-07-08'),
        ];

        $stayRepository = $this->createMock(StayRepository::class);
        $stayRepository->method('findConfirmedOverlapping')->willReturn($stays);

        $builder = new HostingStatsBuilder(
            $stayRepository,
            $this->resolverWithPhysical(2),  // 2 camas todo el año (sin filas de mes)
            new OccupancyChecker(),
        );

        $stats = $builder->forYear(2026);

        $this->assertSame(2026, $stats['year']);
        // Usadas: 7 días con 1 (1-4 y 8-10) + 3 días con 2 (5-7) = 13.
        $this->assertSame(13, $stats['used_nights']);
        // Disponibles: 2 camas × 365 días (2026 no bisiesto) = 730.
        $this->assertSame(730, $stats['available_nights']);
        $this->assertSame(2, $stats['occupancy_pct']);  // round(13/730*100) = 2
        $this->assertSame(6.5, $stats['avg_stay']);     // (10 + 3) / 2
        $this->assertSame(2, $stats['helpers']);
        $this->assertSame(2, $stats['stays']);
        $this->assertSame(7, $stats['busiest_month']);
        $this->assertSame(2, $stats['busiest_peak']);
    }

    public function testSinEstanciasConfirmadas(): void
    {
        $stayRepository = $this->createMock(StayRepository::class);
        $stayRepository->method('findConfirmedOverlapping')->willReturn([]);

        $builder = new HostingStatsBuilder(
            $stayRepository,
            $this->resolverWithPhysical(3),
            new OccupancyChecker(),
        );

        $stats = $builder->forYear(2030);

        $this->assertSame(0, $stats['used_nights']);
        $this->assertSame(3 * 365, $stats['available_nights']);
        $this->assertSame(0, $stats['occupancy_pct']);
        $this->assertSame(0.0, $stats['avg_stay']);
        $this->assertSame(0, $stats['stays']);
        $this->assertNull($stats['busiest_month']);
    }

    /**
     * Resolver real con aforo físico fijo y sin filas de mes (todo el año = físico).
     */
    private function resolverWithPhysical(int $physical): HostingCapacityResolver
    {
        $capacityRepository = $this->createMock(HostingCapacityRepository::class);
        $capacityRepository->method('findKeyedByMonth')->willReturn([]);

        $settings = $this->createMock(AppSettings::class);
        $settings->method('getInt')->willReturn($physical);

        return new HostingCapacityResolver($capacityRepository, $settings);
    }

    /**
     * Voluntario mockeado con id.
     */
    private function helper(int $id): Helper
    {
        $helper = $this->createMock(Helper::class);
        $helper->method('getId')->willReturn($id);

        return $helper;
    }

    /**
     * Estancia confirmada de un voluntario.
     */
    private function stay(Helper $helper, string $arrival, string $departure): Stay
    {
        return (new Stay())
            ->setHelper($helper)
            ->setArrivalDate(new \DateTimeImmutable($arrival))
            ->setDepartureDate(new \DateTimeImmutable($departure))
            ->setStatus(Stay::STATUS_CONFIRMED);
    }
}
