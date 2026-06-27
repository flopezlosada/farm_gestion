<?php

namespace App\Tests\Service\Staff;

use App\Service\Staff\MonthlyTimesheetBuilder;
use PHPUnit\Framework\TestCase;

class MonthlyTimesheetBuilderTest extends TestCase
{
    private MonthlyTimesheetBuilder $builder;
    private \DateTimeZone $madrid;

    protected function setUp(): void
    {
        $this->builder = new MonthlyTimesheetBuilder();
        $this->madrid = new \DateTimeZone('Europe/Madrid');
    }

    /**
     * Un día trabajado (con tramos) construido al estilo de WorkdayBuilder.
     *
     * @param string $key     Día 'Y-m-d'.
     * @param int    $minutes Total del día.
     * @return array
     */
    private function workedDay(string $key, int $minutes): array
    {
        return [
            'date' => new \DateTimeImmutable($key . ' 00:00:00', $this->madrid),
            'segments' => [['in' => null, 'out' => null, 'minutes' => $minutes]],
            'totalMinutes' => $minutes,
            'open' => false,
            'anomaly' => false,
        ];
    }

    private function build(array $worked, array $covered, array $holidays, ?string $hire): array
    {
        return $this->builder->build(
            2026,
            2,
            $this->madrid,
            $worked,
            $covered,
            $holidays,
            $hire !== null ? new \DateTimeImmutable($hire, $this->madrid) : null,
            new \DateTimeImmutable('2026-06-15', $this->madrid), // hoy muy posterior: ningún día de feb es futuro
        );
    }

    public function testUnaFilaPorCadaDiaDelMes(): void
    {
        $sheet = $this->build([], [], [], null);

        // Febrero 2026 tiene 28 días.
        $this->assertCount(28, $sheet['rows']);
    }

    public function testClasificaCadaTipoDeDia(): void
    {
        $sheet = $this->build(
            [
                '2026-02-04' => $this->workedDay('2026-02-04', 480), // miércoles laborable
                '2026-02-07' => $this->workedDay('2026-02-07', 120), // sábado: trabajo manda sobre finde
            ],
            ['2026-02-05' => 'vacation'],         // jueves de vacaciones
            ['2026-02-06' => 'Festivo de prueba'], // viernes festivo
            '2026-02-03',                          // alta el 3: días 1 y 2 son anteriores
        );

        $rows = [];
        foreach ($sheet['rows'] as $row) {
            $rows[$row['date']->format('Y-m-d')] = $row;
        }

        $this->assertSame(MonthlyTimesheetBuilder::STATUS_BEFORE_HIRE, $rows['2026-02-01']['status']);
        $this->assertSame(MonthlyTimesheetBuilder::STATUS_BEFORE_HIRE, $rows['2026-02-02']['status']);
        $this->assertSame(MonthlyTimesheetBuilder::STATUS_WORKED, $rows['2026-02-04']['status']);
        $this->assertSame(MonthlyTimesheetBuilder::STATUS_ABSENCE, $rows['2026-02-05']['status']);
        $this->assertSame('vacation', $rows['2026-02-05']['absenceType']);
        $this->assertSame(MonthlyTimesheetBuilder::STATUS_HOLIDAY, $rows['2026-02-06']['status']);
        $this->assertSame('Festivo de prueba', $rows['2026-02-06']['holidayName']);
        // Sábado con fichaje: cuenta como trabajado, no como fin de semana.
        $this->assertSame(MonthlyTimesheetBuilder::STATUS_WORKED, $rows['2026-02-07']['status']);
        // Lunes 9 laborable, pasado, sin nada: hueco.
        $this->assertSame(MonthlyTimesheetBuilder::STATUS_MISSING, $rows['2026-02-09']['status']);
    }

    public function testSumaSoloLosDiasTrabajados(): void
    {
        $sheet = $this->build(
            [
                '2026-02-04' => $this->workedDay('2026-02-04', 480),
                '2026-02-07' => $this->workedDay('2026-02-07', 120),
            ],
            ['2026-02-05' => 'vacation'],
            [],
            null,
        );

        $this->assertSame(600, $sheet['totalMinutes']);
        $this->assertSame(2, $sheet['workedDays']);
        $this->assertGreaterThan(0, $sheet['missingDays']);
    }

    public function testDiaFuturoNoEsHueco(): void
    {
        // Mes en curso: hoy 2026-02-10, los días posteriores son futuros, no huecos.
        $sheet = $this->builder->build(
            2026,
            2,
            $this->madrid,
            [],
            [],
            [],
            null,
            new \DateTimeImmutable('2026-02-10', $this->madrid),
        );

        $rows = [];
        foreach ($sheet['rows'] as $row) {
            $rows[$row['date']->format('Y-m-d')] = $row;
        }

        // El 27 (viernes) es futuro respecto al 10: no cuenta como hueco.
        $this->assertSame(MonthlyTimesheetBuilder::STATUS_FUTURE, $rows['2026-02-27']['status']);
    }
}
