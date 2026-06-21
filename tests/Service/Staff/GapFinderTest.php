<?php

namespace App\Tests\Service\Staff;

use App\Service\Staff\GapFinder;
use PHPUnit\Framework\TestCase;

class GapFinderTest extends TestCase
{
    private function d(string $ymd): \DateTimeImmutable
    {
        return new \DateTimeImmutable($ymd . ' 00:00:00', new \DateTimeZone('UTC'));
    }

    /**
     * Semana del lunes 2026-06-15 al viernes 19. Hoy = lunes 22. Sin nada
     * trabajado ni cubierto: los 5 laborables son huecos (sábado y domingo no).
     */
    public function testLosLaborablesSinFichajeSonHuecos(): void
    {
        $gaps = (new GapFinder())->gapsFor(
            $this->d('2026-06-15'),
            $this->d('2026-06-22'),
            $this->d('2020-01-01'),
            [],
            [],
        );

        $keys = array_map(static fn ($d) => $d->format('Y-m-d'), $gaps);
        $this->assertSame(['2026-06-15', '2026-06-16', '2026-06-17', '2026-06-18', '2026-06-19'], $keys);
    }

    public function testDiaTrabajadoNoEsHueco(): void
    {
        $gaps = (new GapFinder())->gapsFor(
            $this->d('2026-06-15'),
            $this->d('2026-06-22'),
            $this->d('2020-01-01'),
            ['2026-06-16' => true, '2026-06-18' => true],
            [],
        );

        $keys = array_map(static fn ($d) => $d->format('Y-m-d'), $gaps);
        $this->assertSame(['2026-06-15', '2026-06-17', '2026-06-19'], $keys);
    }

    public function testDiaCubiertoPorAusenciaNoEsHueco(): void
    {
        $gaps = (new GapFinder())->gapsFor(
            $this->d('2026-06-15'),
            $this->d('2026-06-22'),
            $this->d('2020-01-01'),
            [],
            ['2026-06-15' => true, '2026-06-16' => true],
        );

        $keys = array_map(static fn ($d) => $d->format('Y-m-d'), $gaps);
        $this->assertSame(['2026-06-17', '2026-06-18', '2026-06-19'], $keys);
    }

    public function testNoHayHuecosAntesDelAlta(): void
    {
        $gaps = (new GapFinder())->gapsFor(
            $this->d('2026-06-15'),
            $this->d('2026-06-22'),
            $this->d('2026-06-18'), // alta el jueves
            [],
            [],
        );

        $keys = array_map(static fn ($d) => $d->format('Y-m-d'), $gaps);
        $this->assertSame(['2026-06-18', '2026-06-19'], $keys);
    }

    public function testElDiaDeHoyNoCuentaComoHueco(): void
    {
        // Ventana hasta el viernes con "hoy" = viernes 19: solo cuenta hasta el jueves.
        $gaps = (new GapFinder())->gapsFor(
            $this->d('2026-06-15'),
            $this->d('2026-06-19'),
            $this->d('2020-01-01'),
            [],
            [],
        );

        $keys = array_map(static fn ($d) => $d->format('Y-m-d'), $gaps);
        $this->assertSame(['2026-06-15', '2026-06-16', '2026-06-17', '2026-06-18'], $keys);
    }
}
