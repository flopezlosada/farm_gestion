<?php

namespace App\Tests\Service\Hosting;

use App\Entity\Helper;
use App\Entity\HelperSource;
use App\Entity\Stay;
use App\Repository\StayRepository;
use App\Service\Hosting\HostingStatsBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del HostingStatsBuilder. Mockea el repo de estancias y verifica la
 * agregación por procedencia: voluntarios distintos (uno que repite cuenta una
 * vez), nº de estancias (confirmadas + solicitadas) y noches (sólo confirmadas).
 */
class HostingStatsBuilderTest extends TestCase
{
    public function testAgregaPorProcedencia(): void
    {
        $wwoof = $this->source(1, 'WWOOF');
        $workaway = $this->source(2, 'Workaway');

        $ana = $this->helper(1, $wwoof);
        $beto = $this->helper(2, $wwoof);
        $cora = $this->helper(3, $workaway);

        $stays = [
            $this->stay($ana, '2026-07-01', '2026-07-08', Stay::STATUS_CONFIRMED),   // 7 noches
            $this->stay($ana, '2026-08-01', '2026-08-03', Stay::STATUS_CONFIRMED),   // 2 noches (Ana repite)
            $this->stay($beto, '2026-07-10', '2026-07-12', Stay::STATUS_REQUESTED),  // solicitada: cuenta estancia, 0 noches
            $this->stay($cora, '2026-09-01', '2026-09-05', Stay::STATUS_CONFIRMED),  // 4 noches
        ];

        $repository = $this->createMock(StayRepository::class);
        $repository->expects($this->once())
            ->method('findByArrivalYear')
            ->with(2026)
            ->willReturn($stays);

        $stats = (new HostingStatsBuilder($repository))->bySource(2026);

        $this->assertSame(2026, $stats['year']);

        $bySourceName = [];
        foreach ($stats['rows'] as $row) {
            $bySourceName[$row['source']->getName()] = $row;
        }

        // WWOOF: Ana + Beto = 2 voluntarios; 3 estancias; 7+2 = 9 noches (Beto solicitada no suma).
        $this->assertSame(2, $bySourceName['WWOOF']['helpers']);
        $this->assertSame(3, $bySourceName['WWOOF']['stays']);
        $this->assertSame(9, $bySourceName['WWOOF']['nights']);

        // Workaway: Cora = 1 voluntario; 1 estancia; 4 noches.
        $this->assertSame(1, $bySourceName['Workaway']['helpers']);
        $this->assertSame(1, $bySourceName['Workaway']['stays']);
        $this->assertSame(4, $bySourceName['Workaway']['nights']);

        // Totales.
        $this->assertSame(['helpers' => 3, 'stays' => 4, 'nights' => 13], $stats['totals']);
    }

    public function testSinEstanciasDevuelveVacio(): void
    {
        $repository = $this->createMock(StayRepository::class);
        $repository->method('findByArrivalYear')->willReturn([]);

        $stats = (new HostingStatsBuilder($repository))->bySource(2030);

        $this->assertSame([], $stats['rows']);
        $this->assertSame(['helpers' => 0, 'stays' => 0, 'nights' => 0], $stats['totals']);
    }

    /**
     * Procedencia mockeada con id y nombre.
     */
    private function source(int $id, string $name): HelperSource
    {
        $source = $this->createMock(HelperSource::class);
        $source->method('getId')->willReturn($id);
        $source->method('getName')->willReturn($name);

        return $source;
    }

    /**
     * Voluntario mockeado con id y procedencia.
     */
    private function helper(int $id, HelperSource $source): Helper
    {
        $helper = $this->createMock(Helper::class);
        $helper->method('getId')->willReturn($id);
        $helper->method('getSource')->willReturn($source);

        return $helper;
    }

    /**
     * Estancia de un voluntario con fechas y estado.
     */
    private function stay(Helper $helper, string $arrival, string $departure, string $status): Stay
    {
        return (new Stay())
            ->setHelper($helper)
            ->setArrivalDate(new \DateTimeImmutable($arrival))
            ->setDepartureDate(new \DateTimeImmutable($departure))
            ->setStatus($status);
    }
}
