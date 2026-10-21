<?php

namespace App\Tests\Service\Staff;

use App\Entity\TimeEntry;
use App\Service\Staff\WorkdayBuilder;
use App\Tests\Entity\StampsTimeEntryRecordedAt;
use PHPUnit\Framework\TestCase;

class WorkdayBuilderTest extends TestCase
{
    use StampsTimeEntryRecordedAt;

    private \DateTimeZone $utc;

    protected function setUp(): void
    {
        $this->utc = new \DateTimeZone('UTC');
    }

    /**
     * Crea un fichaje con tipo y hora dados (UTC).
     *
     * @param string $type 'in' u 'out'.
     * @param string $at   Fecha-hora 'Y-m-d H:i' en UTC.
     * @return TimeEntry
     */
    private function entry(string $type, string $at): TimeEntry
    {
        return (new TimeEntry())
            ->setType($type)
            ->setOccurredAt(new \DateTimeImmutable($at, $this->utc));
    }

    public function testJornadaSimpleSumaUnTramo(): void
    {
        $days = (new WorkdayBuilder())->buildDays([
            $this->entry(TimeEntry::TYPE_IN, '2026-06-20 09:00'),
            $this->entry(TimeEntry::TYPE_OUT, '2026-06-20 17:00'),
        ], $this->utc);

        $this->assertCount(1, $days);
        $this->assertSame(480, $days[0]['totalMinutes']);
        $this->assertFalse($days[0]['open']);
        $this->assertFalse($days[0]['anomaly']);
        $this->assertCount(1, $days[0]['segments']);
    }

    public function testDosTramosEnUnDiaSeSuman(): void
    {
        $days = (new WorkdayBuilder())->buildDays([
            $this->entry(TimeEntry::TYPE_IN, '2026-06-20 09:00'),
            $this->entry(TimeEntry::TYPE_OUT, '2026-06-20 13:00'),
            $this->entry(TimeEntry::TYPE_IN, '2026-06-20 14:00'),
            $this->entry(TimeEntry::TYPE_OUT, '2026-06-20 18:00'),
        ], $this->utc);

        $this->assertCount(1, $days);
        $this->assertSame(480, $days[0]['totalMinutes']);
        $this->assertCount(2, $days[0]['segments']);
    }

    public function testEntradaSinSalidaQuedaTramoAbierto(): void
    {
        $days = (new WorkdayBuilder())->buildDays([
            $this->entry(TimeEntry::TYPE_IN, '2026-06-20 09:00'),
        ], $this->utc);

        $this->assertTrue($days[0]['open']);
        $this->assertSame(0, $days[0]['totalMinutes']);
        $this->assertNull($days[0]['segments'][0]['minutes']);
    }

    public function testSalidaSinEntradaEsAnomalia(): void
    {
        $days = (new WorkdayBuilder())->buildDays([
            $this->entry(TimeEntry::TYPE_OUT, '2026-06-20 17:00'),
        ], $this->utc);

        $this->assertTrue($days[0]['anomaly']);
        $this->assertSame(0, $days[0]['totalMinutes']);
    }

    public function testAgrupaPorDiaYOrdenaDeRecienteAAntiguo(): void
    {
        $days = (new WorkdayBuilder())->buildDays([
            $this->entry(TimeEntry::TYPE_IN, '2026-06-18 09:00'),
            $this->entry(TimeEntry::TYPE_OUT, '2026-06-18 17:00'),
            $this->entry(TimeEntry::TYPE_IN, '2026-06-20 08:00'),
            $this->entry(TimeEntry::TYPE_OUT, '2026-06-20 15:00'),
        ], $this->utc);

        $this->assertCount(2, $days);
        $this->assertSame('2026-06-20', $days[0]['date']->format('Y-m-d'));
        $this->assertSame('2026-06-18', $days[1]['date']->format('Y-m-d'));
        $this->assertSame(420, $days[0]['totalMinutes']);
        $this->assertSame(480, $days[1]['totalMinutes']);
    }

    public function testMarcaElDiaComoDiferidoSiAlgunFichajeLoEs(): void
    {
        // El 20 tiene la salida apuntada al día siguiente; el 18 es todo en tiempo
        // real (sin sello, como una fila aún sin persistir: no cuenta como diferido).
        $days = (new WorkdayBuilder())->buildDays([
            $this->entry(TimeEntry::TYPE_IN, '2026-06-18 09:00'),
            $this->entry(TimeEntry::TYPE_OUT, '2026-06-18 17:00'),
            $this->entry(TimeEntry::TYPE_IN, '2026-06-20 08:00'),
            $this->setRecordedAt($this->entry(TimeEntry::TYPE_OUT, '2026-06-20 15:00'), new \DateTimeImmutable('2026-06-21 08:00', $this->utc)),
        ], $this->utc);

        $this->assertTrue($days[0]['deferred']);
        $this->assertFalse($days[1]['deferred']);
        $this->assertSame(TimeEntry::TYPE_OUT, $days[0]['lastDeferred']?->getType(), 'El chip del día sale del registro diferido.');
        $this->assertNull($days[1]['lastDeferred']);
    }
}
