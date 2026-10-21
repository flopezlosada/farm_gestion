<?php

namespace App\Tests\Service\Staff;

use App\Service\Staff\MonthlyTimesheetBuilder;
use App\Service\Staff\WorkingWeek;
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
     * @param string $key      Día 'Y-m-d'.
     * @param int    $minutes  Total del día.
     * @param bool   $deferred Si algún fichaje del día es un registro diferido.
     * @return array
     */
    private function workedDay(string $key, int $minutes, bool $deferred = false): array
    {
        return [
            'date' => new \DateTimeImmutable($key . ' 00:00:00', $this->madrid),
            'segments' => [['in' => null, 'out' => null, 'minutes' => $minutes]],
            'totalMinutes' => $minutes,
            'open' => false,
            'anomaly' => false,
            'deferred' => $deferred,
        ];
    }

    private function build(array $worked, array $covered, array $holidays, ?string $hire, ?WorkingWeek $week = null): array
    {
        return $this->builder->build(
            2026,
            2,
            $this->madrid,
            $worked,
            $covered,
            $holidays,
            $week ?? WorkingWeek::mondayToFriday(),
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
            WorkingWeek::mondayToFriday(),
            null,
            new \DateTimeImmutable('2026-02-10', $this->madrid),
        );

        $rows = [];
        foreach ($sheet['rows'] as $row) {
            $rows[$row['date']->format('Y-m-d')] = $row;
        }

        // El 27 (viernes) es futuro respecto al 10: no cuenta como hueco.
        $this->assertSame(MonthlyTimesheetBuilder::STATUS_FUTURE, $rows['2026-02-27']['status']);
        // Hoy (martes 10) aún puede ficharse: tampoco es un olvido.
        $this->assertSame(MonthlyTimesheetBuilder::STATUS_FUTURE, $rows['2026-02-10']['status']);
        $this->assertSame(MonthlyTimesheetBuilder::STATUS_MISSING, $rows['2026-02-09']['status']);
    }

    public function testCuentaYMarcaLosDiasApuntadosAMano(): void
    {
        $sheet = $this->build(
            [
                '2026-02-04' => $this->workedDay('2026-02-04', 480, deferred: true),
                '2026-02-05' => $this->workedDay('2026-02-05', 480),
            ],
            [],
            [],
            null,
        );

        $rows = [];
        foreach ($sheet['rows'] as $row) {
            $rows[$row['date']->format('Y-m-d')] = $row;
        }

        $this->assertSame(1, $sheet['deferredDays']);
        $this->assertTrue($rows['2026-02-04']['deferred']);
        $this->assertFalse($rows['2026-02-05']['deferred']);
        // Un día sin fichajes no es diferido, aunque sea hueco.
        $this->assertFalse($rows['2026-02-06']['deferred']);
    }

    /**
     * Media jornada de lunes a jueves: los viernes son descanso (no hueco) y los
     * fines de semana siguen siendo fin de semana.
     */
    public function testElDiaQueNoLeTocaEsDescansoNoHueco(): void
    {
        $sheet = $this->build([], [], [], null, WorkingWeek::of(1, 2, 3, 4));

        $rows = [];
        foreach ($sheet['rows'] as $row) {
            $rows[$row['date']->format('Y-m-d')] = $row;
        }

        $this->assertSame(MonthlyTimesheetBuilder::STATUS_REST, $rows['2026-02-06']['status']);
        $this->assertSame(MonthlyTimesheetBuilder::STATUS_WEEKEND, $rows['2026-02-07']['status']);
        $this->assertSame(MonthlyTimesheetBuilder::STATUS_MISSING, $rows['2026-02-05']['status']);
        // Febrero 2026: 4 lunes, 4 martes, 4 miércoles y 4 jueves.
        $this->assertSame(16, $sheet['missingDays']);
    }

    /**
     * Un sábado que le toca y no fichó es hueco, no fin de semana.
     */
    public function testUnSabadoQueLeTocaSinFicharEsHueco(): void
    {
        $sheet = $this->build([], [], [], null, WorkingWeek::of(6));

        $rows = [];
        foreach ($sheet['rows'] as $row) {
            $rows[$row['date']->format('Y-m-d')] = $row;
        }

        $this->assertSame(MonthlyTimesheetBuilder::STATUS_MISSING, $rows['2026-02-07']['status']);
        $this->assertSame(MonthlyTimesheetBuilder::STATUS_WEEKEND, $rows['2026-02-08']['status']);
        $this->assertSame(MonthlyTimesheetBuilder::STATUS_REST, $rows['2026-02-09']['status']);
    }

    /**
     * Una huelga (o un permiso) explica el día: no es hueco.
     */
    public function testUnaHuelgaExplicaElDia(): void
    {
        $sheet = $this->build([], ['2026-02-09' => 'strike'], [], null);

        $rows = [];
        foreach ($sheet['rows'] as $row) {
            $rows[$row['date']->format('Y-m-d')] = $row;
        }

        $this->assertSame(MonthlyTimesheetBuilder::STATUS_ABSENCE, $rows['2026-02-09']['status']);
        $this->assertSame('strike', $rows['2026-02-09']['absenceType']);
    }

    /**
     * Las jornadas a medias (entrada sin salida, tramos descuadrados) se cuentan
     * aparte de los huecos: es lo que dice el cierre del mes.
     */
    public function testCuentaLasJornadasAMedias(): void
    {
        $open = $this->workedDay('2026-02-04', 0);
        $open['open'] = true;
        $anomaly = $this->workedDay('2026-02-05', 60);
        $anomaly['anomaly'] = true;

        $sheet = $this->build(['2026-02-04' => $open, '2026-02-05' => $anomaly, '2026-02-06' => $this->workedDay('2026-02-06', 480)], [], [], null);

        $this->assertSame(2, $sheet['issueDays']);
    }
}
