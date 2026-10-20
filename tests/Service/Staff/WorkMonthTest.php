<?php

namespace App\Tests\Service\Staff;

use App\Service\Staff\WorkMonth;
use PHPUnit\Framework\TestCase;

class WorkMonthTest extends TestCase
{
    public function testLimitesDelMesEnHoraDeMadrid(): void
    {
        $month = WorkMonth::of(2026, 2);

        $this->assertSame('2026-02-01 00:00', $month->start()->format('Y-m-d H:i'));
        $this->assertSame('2026-03-01 00:00', $month->end()->format('Y-m-d H:i'));
        $this->assertSame('Europe/Madrid', $month->start()->getTimezone()->getName());
    }

    public function testCambioDeAnoHaciaAtrasYHaciaDelante(): void
    {
        $this->assertTrue(WorkMonth::of(2026, 1)->previous()->equals(WorkMonth::of(2025, 12)));
        $this->assertTrue(WorkMonth::of(2025, 12)->next()->equals(WorkMonth::of(2026, 1)));
    }

    public function testElMesDeUnInstanteSeCuentaEnHoraDeMadrid(): void
    {
        // 31 de marzo a las 23:30 UTC ya es 1 de abril en Madrid (horario de verano).
        $at = new \DateTimeImmutable('2026-03-31 23:30:00', new \DateTimeZone('UTC'));

        $this->assertTrue(WorkMonth::containing($at)->equals(WorkMonth::of(2026, 4)));
    }

    public function testSoloHaTerminadoDesdeElDiaUnoDelSiguiente(): void
    {
        $month = WorkMonth::of(2026, 9);
        $madrid = new \DateTimeZone('Europe/Madrid');

        $this->assertFalse($month->hasEndedAt(new \DateTimeImmutable('2026-09-30 23:59:59', $madrid)));
        $this->assertTrue($month->hasEndedAt(new \DateTimeImmutable('2026-10-01 00:00:00', $madrid)));
    }

    public function testEtiquetaYClave(): void
    {
        $month = WorkMonth::of(2026, 3);

        $this->assertSame('marzo de 2026', $month->label());
        $this->assertSame('2026-03', $month->key());
    }

    public function testMesImposibleSeRechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WorkMonth::of(2026, 13);
    }
}
