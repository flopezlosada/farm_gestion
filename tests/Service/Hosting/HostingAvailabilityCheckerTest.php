<?php

namespace App\Tests\Service\Hosting;

use App\Entity\HostingCapacity;
use App\Entity\Stay;
use App\Repository\HostingCapacityRepository;
use App\Repository\StayRepository;
use App\Service\AppSettings;
use App\Service\Hosting\HostingAvailabilityChecker;
use App\Service\Hosting\HostingCapacityResolver;
use App\Service\Hosting\OccupancyChecker;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del HostingAvailabilityChecker (el orquestador del guard). Mockea
 * sólo las FRONTERAS reales (repos y AppSettings) y usa las piezas de lógica
 * propias enteras y de verdad —OccupancyChecker y HostingCapacityResolver son
 * baratas y puras—, así el aforo se resuelve con datos reales en vez de un
 * callable inventado. Verifica que una solicitud sin confirmar no consulta nada,
 * y que una confirmada compara contra las existentes y el aforo del mes.
 */
class HostingAvailabilityCheckerTest extends TestCase
{
    public function testEstanciaNoConfirmadaNoConsultaBbddYSiempreCabe(): void
    {
        $stayRepository = $this->createMock(StayRepository::class);
        $stayRepository->expects($this->never())->method('findConfirmedOverlapping');

        $capacityRepository = $this->createMock(HostingCapacityRepository::class);
        $capacityRepository->expects($this->never())->method('findKeyedByMonth');

        $checker = $this->checkerWith($stayRepository, $capacityRepository, 5);

        $candidate = $this->stay('2026-07-10', '2026-07-15', Stay::STATUS_REQUESTED);

        $this->assertSame([], $checker->violationsFor($candidate));
        $this->assertTrue($checker->fits($candidate));
    }

    public function testEstanciaConfirmadaCabeCuandoHayAforo(): void
    {
        $candidate = $this->stay('2026-07-10', '2026-07-13', Stay::STATUS_CONFIRMED);  // 10, 11, 12

        $stayRepository = $this->createMock(StayRepository::class);
        $stayRepository->method('findConfirmedOverlapping')
            ->willReturn([$this->stay('2026-07-10', '2026-07-12', Stay::STATUS_CONFIRMED)]);  // 1 ocupada

        $capacityRepository = $this->createMock(HostingCapacityRepository::class);
        $capacityRepository->method('findKeyedByMonth')
            ->willReturn(['2026-07' => $this->capacity(2026, 7, true, 5)]);  // aforo 5

        $checker = $this->checkerWith($stayRepository, $capacityRepository, 0);

        $this->assertSame([], $checker->violationsFor($candidate));
        $this->assertTrue($checker->fits($candidate));
    }

    public function testEstanciaConfirmadaQueSaturaDevuelveLosDiasEnConflicto(): void
    {
        $candidate = $this->stay('2026-07-10', '2026-07-13', Stay::STATUS_CONFIRMED);  // 10, 11, 12

        $stayRepository = $this->createMock(StayRepository::class);
        $stayRepository->method('findConfirmedOverlapping')->willReturn([
            $this->stay('2026-07-10', '2026-07-12', Stay::STATUS_CONFIRMED),  // ocupa 10, 11
            $this->stay('2026-07-10', '2026-07-12', Stay::STATUS_CONFIRMED),  // ocupa 10, 11
        ]);

        $capacityRepository = $this->createMock(HostingCapacityRepository::class);
        $capacityRepository->method('findKeyedByMonth')
            ->willReturn(['2026-07' => $this->capacity(2026, 7, true, 2)]);  // aforo 2

        $checker = $this->checkerWith($stayRepository, $capacityRepository, 0);

        $violations = array_map(
            fn (\DateTimeImmutable $d) => $d->format('Y-m-d'),
            $checker->violationsFor($candidate),
        );

        $this->assertSame(['2026-07-10', '2026-07-11'], $violations);
        $this->assertFalse($checker->fits($candidate));
    }

    /**
     * Arma el orquestador con repos mockeados y un aforo físico de referencia
     * dado, usando las piezas de lógica reales (resolver + checker).
     */
    private function checkerWith(
        StayRepository $stayRepository,
        HostingCapacityRepository $capacityRepository,
        int $physicalDefault,
    ): HostingAvailabilityChecker {
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getInt')
            ->with(AppSettings::HOSTING_PHYSICAL_CAPACITY)
            ->willReturn($physicalDefault);

        $resolver = new HostingCapacityResolver($capacityRepository, $settings);

        return new HostingAvailabilityChecker($stayRepository, $resolver, new OccupancyChecker());
    }

    /**
     * Construye una fila de aforo mensual.
     */
    private function capacity(int $year, int $month, bool $open, int $capacity): HostingCapacity
    {
        return (new HostingCapacity())
            ->setYear($year)
            ->setMonth($month)
            ->setOpen($open)
            ->setCapacity($capacity);
    }

    /**
     * Construye una estancia con el estado dado.
     */
    private function stay(string $arrival, string $departure, string $status): Stay
    {
        return (new Stay())
            ->setArrivalDate(new \DateTimeImmutable($arrival))
            ->setDepartureDate(new \DateTimeImmutable($departure))
            ->setStatus($status);
    }
}
