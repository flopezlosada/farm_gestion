<?php

namespace App\Tests\Service\Staff;

use App\Service\Staff\SpanishHolidayCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Generación de los festivos habituales de Madrid. Sobre todo la Semana Santa
 * (calculada con Computus): se contrasta con fechas de Pascua conocidas.
 */
class SpanishHolidayCalendarTest extends TestCase
{
    private SpanishHolidayCalendar $cal;

    protected function setUp(): void
    {
        $this->cal = new SpanishHolidayCalendar();
    }

    public function testIncluyeLosFijosNacionalesYDeMadrid(): void
    {
        $h = $this->cal->forYear(2026);
        $this->assertSame('Año Nuevo', $h['2026-01-01']);
        $this->assertSame('Fiesta del Trabajo', $h['2026-05-01']);
        $this->assertSame('Día de la Comunidad de Madrid', $h['2026-05-02']);
        $this->assertSame('Natividad del Señor', $h['2026-12-25']);
    }

    public function testCalculaLaSemanaSanta2026(): void
    {
        // Domingo de Pascua 2026 = 5 de abril → Jueves 2, Viernes 3.
        $h = $this->cal->forYear(2026);
        $this->assertSame('Jueves Santo', $h['2026-04-02']);
        $this->assertSame('Viernes Santo', $h['2026-04-03']);
    }

    public function testCalculaLaSemanaSanta2025(): void
    {
        // Domingo de Pascua 2025 = 20 de abril → Jueves 17, Viernes 18.
        $h = $this->cal->forYear(2025);
        $this->assertSame('Jueves Santo', $h['2025-04-17']);
        $this->assertSame('Viernes Santo', $h['2025-04-18']);
    }

    public function testGeneraDoceFestivos(): void
    {
        // 10 fijos + Jueves y Viernes Santo.
        $this->assertCount(12, $this->cal->forYear(2026));
    }
}
