<?php

namespace App\Tests\Service\Staff;

use App\Service\Staff\WorkMonth;
use App\Service\Staff\WorkPeriod;
use PHPUnit\Framework\TestCase;

/**
 * El periodo del registro que se entrega a la Inspección: que no se pueda
 * construir uno al revés ni desmesurado, y cómo se parte por años para el PDF.
 */
class WorkPeriodTest extends TestCase
{
    public function testMesesEnOrdenCruzandoElAno(): void
    {
        $period = WorkPeriod::between(WorkMonth::of(2025, 11), WorkMonth::of(2026, 2));

        $this->assertSame(['2025-11', '2025-12', '2026-01', '2026-02'], array_map(static fn (WorkMonth $m) => $m->key(), $period->months()));
        $this->assertSame(4, $period->length());
        $this->assertSame('2025-11_2026-02', $period->key());
        $this->assertSame('de noviembre de 2025 a febrero de 2026', $period->label());
    }

    public function testUnSoloMes(): void
    {
        $period = WorkPeriod::single(WorkMonth::of(2026, 3));

        $this->assertSame(1, $period->length());
        $this->assertSame('2026-03', $period->key());
        $this->assertSame('marzo de 2026', $period->label());
        $this->assertSame('2026-03-01 00:00', $period->start()->format('Y-m-d H:i'));
        $this->assertSame('2026-04-01 00:00', $period->end()->format('Y-m-d H:i'));
    }

    public function testAlRevesSeRechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WorkPeriod::between(WorkMonth::of(2026, 10), WorkMonth::of(2026, 1));
    }

    /** Los cuatro años que hay que conservar caben enteros desde cualquier mes. */
    public function testElTopeAdmiteCuatroAnosYRechazaUnoMas(): void
    {
        $this->assertSame(WorkPeriod::MAX_MONTHS, WorkPeriod::between(WorkMonth::of(2021, 11), WorkMonth::of(2026, 10))->length());

        $this->expectException(\InvalidArgumentException::class);
        WorkPeriod::between(WorkMonth::of(2021, 10), WorkMonth::of(2026, 10));
    }

    public function testPorAnosRespetaLosExtremos(): void
    {
        $years = WorkPeriod::between(WorkMonth::of(2023, 3), WorkMonth::of(2025, 6))->byYear();

        $this->assertSame(['2023-03_2023-12', '2024-01_2024-12', '2025-01_2025-06'], array_map(static fn (WorkPeriod $p) => $p->key(), $years));
        foreach ($years as $year) {
            $this->assertTrue($year->isWithinOneYear());
        }
    }

    public function testDentroDeUnAnoEsUnaSolaPieza(): void
    {
        $period = WorkPeriod::between(WorkMonth::of(2026, 1), WorkMonth::of(2026, 10));

        $this->assertTrue($period->isWithinOneYear());
        $this->assertCount(1, $period->byYear());
        $this->assertFalse(WorkPeriod::between(WorkMonth::of(2025, 12), WorkMonth::of(2026, 1))->isWithinOneYear());
    }
}
