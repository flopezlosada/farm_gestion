<?php

namespace App\Tests\Service\Staff;

use App\Service\Staff\YearCalendarBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Calendario anual de "Mis vacaciones": 12 mini-meses con festivos, ausencias
 * por estado, fines de semana y hoy.
 */
class YearCalendarBuilderTest extends TestCase
{
    private function build(): array
    {
        return (new YearCalendarBuilder())->build(
            2026,
            new \DateTimeZone('Europe/Madrid'),
            new \DateTimeImmutable('2026-06-21 00:00:00', new \DateTimeZone('Europe/Madrid')),
            ['2026-01-01' => 'Año Nuevo'],
            ['2026-07-15' => 'approved', '2026-07-16' => 'requested'],
        );
    }

    private function cell(array $months, int $month, int $day): ?array
    {
        foreach ($months[$month - 1]['weeks'] as $week) {
            foreach ($week as $cell) {
                if ($cell['inMonth'] && $cell['day'] === $day) {
                    return $cell;
                }
            }
        }

        return null;
    }

    public function testTieneDoceMeses(): void
    {
        $this->assertCount(12, $this->build());
    }

    public function testCadaSemanaTieneSieteDias(): void
    {
        foreach ($this->build() as $month) {
            foreach ($month['weeks'] as $week) {
                $this->assertCount(7, $week);
            }
        }
    }

    public function testMarcaFestivo(): void
    {
        $cell = $this->cell($this->build(), 1, 1);
        $this->assertSame('Año Nuevo', $cell['holiday']);
    }

    public function testMarcaAusenciasPorEstado(): void
    {
        $months = $this->build();
        $this->assertSame('approved', $this->cell($months, 7, 15)['absence']);
        $this->assertSame('requested', $this->cell($months, 7, 16)['absence']);
    }

    public function testMarcaFinDeSemanaYHoy(): void
    {
        $months = $this->build();
        // 2026-06-21 es domingo (fin de semana) y es "hoy".
        $cell = $this->cell($months, 6, 21);
        $this->assertTrue($cell['weekend']);
        $this->assertTrue($cell['isToday']);
        // Un día laborable cualquiera sin nada: no es finde, ni hoy, ni ausencia.
        $plain = $this->cell($months, 6, 17);
        $this->assertFalse($plain['weekend']);
        $this->assertFalse($plain['isToday']);
        $this->assertNull($plain['absence']);
    }
}
