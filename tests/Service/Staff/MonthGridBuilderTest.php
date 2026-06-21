<?php

namespace App\Tests\Service\Staff;

use App\Service\Staff\MonthGridBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Rejilla del calendario mensual. Cubre sobre todo el festivo: una celda festiva
 * lleva el nombre del festivo y NO se marca como hueco (aunque sea laborable y
 * pasado), que es justo lo que evita el "hueco falso" del registro.
 */
class MonthGridBuilderTest extends TestCase
{
    private MonthGridBuilder $builder;
    private \DateTimeZone $tz;

    protected function setUp(): void
    {
        $this->builder = new MonthGridBuilder();
        $this->tz = new \DateTimeZone('Europe/Madrid');
    }

    /**
     * Busca la celda de una fecha 'Y-m-d' en la rejilla.
     */
    private function cell(array $weeks, string $ymd): ?array
    {
        foreach ($weeks as $week) {
            foreach ($week as $cell) {
                if ($cell['date']->format('Y-m-d') === $ymd) {
                    return $cell;
                }
            }
        }

        return null;
    }

    public function testElFestivoSeMarcaYNoEsHueco(): void
    {
        // Junio 2026, hoy = 30. El miércoles 17 (laborable, pasado) es festivo.
        $today = new \DateTimeImmutable('2026-06-30 00:00:00', $this->tz);
        $weeks = $this->builder->build(
            2026, 6, $this->tz,
            [],                                   // sin fichajes
            [],                                   // sin ausencias
            $today,
            new \DateTimeImmutable('2020-01-01', $this->tz),
            ['2026-06-17' => 'San Festivo'],      // festivo
        );

        $holiday = $this->cell($weeks, '2026-06-17');
        $this->assertNotNull($holiday);
        $this->assertSame('San Festivo', $holiday['holiday']);
        $this->assertFalse($holiday['isGap'], 'Un festivo no debe contar como hueco.');

        // Un laborable pasado sin festivo ni fichaje SÍ es hueco (control).
        $gap = $this->cell($weeks, '2026-06-16');
        $this->assertNull($gap['holiday']);
        $this->assertTrue($gap['isGap']);
    }
}
