<?php

namespace App\Tests\Service\Staff;

use App\Service\Staff\TeamMonthBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Cabecera de días del calendario del equipo: número, fin de semana, festivo y hoy.
 */
class TeamMonthBuilderTest extends TestCase
{
    private function days(): array
    {
        return (new TeamMonthBuilder())->monthDays(
            2026,
            6,
            new \DateTimeZone('Europe/Madrid'),
            new \DateTimeImmutable('2026-06-21 00:00:00', new \DateTimeZone('Europe/Madrid')),
            ['2026-06-17' => 'San Festivo'],
        );
    }

    public function testJunioTiene30Dias(): void
    {
        $this->assertCount(30, $this->days());
    }

    public function testMarcaFinDeSemanaFestivoYHoy(): void
    {
        $days = $this->days();
        // 2026-06-17 festivo (miércoles), 2026-06-20 sábado, 2026-06-21 domingo y hoy.
        $this->assertSame('San Festivo', $days[16]['holiday']); // día 17 → índice 16
        $this->assertFalse($days[16]['weekend']);
        $this->assertTrue($days[19]['weekend']);               // día 20 (sáb)
        $this->assertTrue($days[20]['weekend']);               // día 21 (dom)
        $this->assertTrue($days[20]['today']);
    }
}
