<?php

namespace App\Tests\Service\Staff;

use App\Entity\TimeEntry;
use App\Entity\Worker;
use App\Repository\AbsenceRepository;
use App\Repository\HolidayRepository;
use App\Repository\TimeEntryRepository;
use App\Repository\WorkerRepository;
use App\Service\Staff\GapFinder;
use App\Service\Staff\GapReport;
use PHPUnit\Framework\TestCase;

class GapReportTest extends TestCase
{
    private \DateTimeZone $madrid;

    protected function setUp(): void
    {
        $this->madrid = new \DateTimeZone('Europe/Madrid');
    }

    private function worker(string $name): Worker
    {
        return (new Worker())->setName($name);
    }

    private function entry(string $type, string $at): TimeEntry
    {
        return (new TimeEntry())
            ->setType($type)
            ->setOccurredAt(new \DateTimeImmutable($at, $this->madrid));
    }

    /**
     * Monta el GapReport con un WorkerRepository y un TimeEntryRepository
     * preparados; los demás colaboradores no intervienen en staleOpenShifts.
     *
     * @param Worker[]                        $active
     * @param array<string, TimeEntry|null>   $lastByName Último fichaje vigente por nombre de worker.
     */
    private function report(array $active, array $lastByName): GapReport
    {
        $workers = $this->createMock(WorkerRepository::class);
        $workers->method('findActive')->willReturn($active);

        $timeEntries = $this->createMock(TimeEntryRepository::class);
        $timeEntries->method('findLastEffectiveForWorker')
            ->willReturnCallback(fn (Worker $w) => $lastByName[$w->getName()] ?? null);

        return new GapReport(
            $workers,
            $timeEntries,
            $this->createMock(AbsenceRepository::class),
            new GapFinder(),
            $this->createMock(HolidayRepository::class),
        );
    }

    public function testEntradaSinCerrarDeDiaAnteriorEsSalidaAbierta(): void
    {
        $sara = $this->worker('Sara');
        $report = $this->report([$sara], ['Sara' => $this->entry(TimeEntry::TYPE_IN, '2026-06-24 09:00')]);

        $rows = $report->staleOpenShifts(new \DateTimeImmutable('2026-06-26', $this->madrid));

        $this->assertCount(1, $rows);
        $this->assertSame($sara, $rows[0]['worker']);
        $this->assertEquals(new \DateTimeImmutable('2026-06-24 09:00', $this->madrid), $rows[0]['since']);
    }

    public function testEntradaAbiertaDeHoyNoCuenta(): void
    {
        $sara = $this->worker('Sara');
        $report = $this->report([$sara], ['Sara' => $this->entry(TimeEntry::TYPE_IN, '2026-06-26 09:00')]);

        $rows = $report->staleOpenShifts(new \DateTimeImmutable('2026-06-26', $this->madrid));

        // La jornada del propio día puede seguir en curso: no es un olvido.
        $this->assertSame([], $rows);
    }

    public function testUltimoFichajeSalidaNoEsSalidaAbierta(): void
    {
        $sara = $this->worker('Sara');
        $report = $this->report([$sara], ['Sara' => $this->entry(TimeEntry::TYPE_OUT, '2026-06-24 17:00')]);

        $rows = $report->staleOpenShifts(new \DateTimeImmutable('2026-06-26', $this->madrid));

        $this->assertSame([], $rows);
    }

    public function testSinFichajesNoEsSalidaAbierta(): void
    {
        $sara = $this->worker('Sara');
        $report = $this->report([$sara], ['Sara' => null]);

        $rows = $report->staleOpenShifts(new \DateTimeImmutable('2026-06-26', $this->madrid));

        $this->assertSame([], $rows);
    }
}
