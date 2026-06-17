<?php

namespace App\Tests\Service\Hosting;

use App\Entity\HostingCapacity;
use App\Repository\HostingCapacityRepository;
use App\Service\AppSettings;
use App\Service\Hosting\HostingCapacityResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del HostingCapacityResolver. Mockea el repo de aforos y AppSettings
 * con expectativas concretas; verifica la regla de resolución: fila del mes si
 * existe (cerrado = 0), aforo físico de referencia si el mes no está configurado.
 */
class HostingCapacityResolverTest extends TestCase
{
    public function testDiaSueltoUsaElAforoDelMesCuandoHayFila(): void
    {
        $repository = $this->createMock(HostingCapacityRepository::class);
        $repository->expects($this->once())
            ->method('findForYearMonth')
            ->with(2026, 7)
            ->willReturn($this->capacity(2026, 7, true, 4));

        $resolver = new HostingCapacityResolver($repository, $this->settingsWithPhysical(99));

        $this->assertSame(4, $resolver->capacityOn(new \DateTimeImmutable('2026-07-10')));
    }

    public function testDiaSueltoConMesCerradoDaCero(): void
    {
        $repository = $this->createMock(HostingCapacityRepository::class);
        $repository->method('findForYearMonth')->willReturn($this->capacity(2026, 8, false, 4));

        $resolver = new HostingCapacityResolver($repository, $this->settingsWithPhysical(99));

        $this->assertSame(0, $resolver->capacityOn(new \DateTimeImmutable('2026-08-10')));
    }

    public function testDiaSueltoSinFilaCaeAlAforoFisico(): void
    {
        $repository = $this->createMock(HostingCapacityRepository::class);
        $repository->method('findForYearMonth')->willReturn(null);

        $resolver = new HostingCapacityResolver($repository, $this->settingsWithPhysical(6));

        $this->assertSame(6, $resolver->capacityOn(new \DateTimeImmutable('2026-09-10')));
    }

    public function testCallableDeRangoResuelveCadaDiaPorSuMes(): void
    {
        // Julio configurado (abierto, 3); agosto cerrado; septiembre sin fila.
        $repository = $this->createMock(HostingCapacityRepository::class);
        $repository->expects($this->once())
            ->method('findKeyedByMonth')
            ->with(2026, 7, 2026, 9)
            ->willReturn([
                '2026-07' => $this->capacity(2026, 7, true, 3),
                '2026-08' => $this->capacity(2026, 8, false, 5),
            ]);

        $resolver = new HostingCapacityResolver($repository, $this->settingsWithPhysical(2));

        $capacityForDay = $resolver->capacityForDayBetween(
            new \DateTimeImmutable('2026-07-15'),
            new \DateTimeImmutable('2026-09-15'),
        );

        $this->assertSame(3, $capacityForDay(new \DateTimeImmutable('2026-07-20')));  // fila abierta
        $this->assertSame(0, $capacityForDay(new \DateTimeImmutable('2026-08-20')));  // fila cerrada
        $this->assertSame(2, $capacityForDay(new \DateTimeImmutable('2026-09-20')));  // sin fila → físico
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
     * AppSettings mockeado que devuelve el aforo físico dado para la clave
     * HOSTING_PHYSICAL_CAPACITY.
     */
    private function settingsWithPhysical(int $physical): AppSettings
    {
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getInt')
            ->with(AppSettings::HOSTING_PHYSICAL_CAPACITY)
            ->willReturn($physical);

        return $settings;
    }
}
