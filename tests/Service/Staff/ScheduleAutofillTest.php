<?php

namespace App\Tests\Service\Staff;

use App\Entity\Absence;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Worker;
use App\Entity\WorkSchedule;
use App\Repository\AbsenceRepository;
use App\Repository\HolidayRepository;
use App\Repository\TimeEntryRepository;
use App\Repository\WorkScheduleRepository;
use App\Service\Staff\PunchSequenceException;
use App\Service\Staff\ScheduleAutofill;
use App\Service\Staff\TimeEntryCorrector;
use PHPUnit\Framework\TestCase;

class ScheduleAutofillTest extends TestCase
{
    private function worker(): Worker
    {
        return new Worker();
    }

    private function schedule(array $times): WorkSchedule
    {
        return (new WorkSchedule())->setDayOfWeek(1)->setTimes($times);
    }

    private function entry(string $type, string $datetime): TimeEntry
    {
        return (new TimeEntry())
            ->setType($type)
            ->setOccurredAt(new \DateTimeImmutable($datetime, new \DateTimeZone('Europe/Madrid')));
    }

    /**
     * @param TimeEntry[] $dayEntries    Fichajes vigentes que el repo devolverá para el día.
     * @param string[]    $rejectedTimes Horas "H:i" que el corrector rechazará con PunchSequenceException.
     */
    private function autofill(
        ?WorkSchedule $schedule,
        array $dayEntries = [],
        array $holidayDates = [],
        array $approvedAbsences = [],
        array $rejectedTimes = [],
    ): ScheduleAutofill {
        $schedules = $this->createMock(WorkScheduleRepository::class);
        $schedules->method('forWorkerAndDay')->willReturn($schedule);

        $holidays = $this->createMock(HolidayRepository::class);
        $holidays->method('findNamesBetween')->willReturn($holidayDates);

        $absences = $this->createMock(AbsenceRepository::class);
        $absences->method('findApprovedForWorkerBetween')->willReturn($approvedAbsences);

        $timeEntries = $this->createMock(TimeEntryRepository::class);
        $timeEntries->method('findEffectiveForWorkerBetween')->willReturn($dayEntries);

        $corrector = $this->createMock(TimeEntryCorrector::class);
        $corrector->method('addEntry')->willReturnCallback(
            function (Worker $worker, string $type, \DateTimeImmutable $at, User $author, string $source, string $note) use ($rejectedTimes) {
                if (in_array($at->format('H:i'), $rejectedTimes, true)) {
                    throw new PunchSequenceException('No encaja con lo ya fichado.');
                }

                return (new TimeEntry())->setWorker($worker)->setType($type)->setOccurredAt($at)->setSource($source)->setAuthor($author)->setNote($note);
            }
        );

        return new ScheduleAutofill($schedules, $holidays, $absences, $timeEntries, $corrector);
    }

    private function monday(): \DateTimeImmutable
    {
        // 2026-06-22 es lunes.
        return new \DateTimeImmutable('2026-06-22 00:00:00', new \DateTimeZone('Europe/Madrid'));
    }

    public function testDiaLimpioSinFicharRellenaLosCuatroBordes(): void
    {
        $schedule = $this->schedule([['09:00', '14:00'], ['15:30', '18:00']]);
        $created = $this->autofill($schedule)->fill($this->worker(), $this->monday(), new User());

        $this->assertCount(4, $created);
        $this->assertSame(['09:00', '14:00', '15:30', '18:00'], array_map(
            static fn (TimeEntry $e) => $e->getOccurredAt()->format('H:i'),
            $created,
        ));
        $this->assertSame(
            [TimeEntry::TYPE_IN, TimeEntry::TYPE_OUT, TimeEntry::TYPE_IN, TimeEntry::TYPE_OUT],
            array_map(static fn (TimeEntry $e) => $e->getType(), $created),
        );
    }

    public function testEntradaYaFichadaSoloRellenaLoQueFalta(): void
    {
        $schedule = $this->schedule([['09:00', '14:00']]);
        $already = $this->entry(TimeEntry::TYPE_IN, '2026-06-22 09:00:00');

        $created = $this->autofill($schedule, [$already])->fill($this->worker(), $this->monday(), new User());

        // La entrada ya estaba fichada: solo se añade la salida.
        $this->assertCount(1, $created);
        $this->assertSame(TimeEntry::TYPE_OUT, $created[0]->getType());
        $this->assertSame('14:00', $created[0]->getOccurredAt()->format('H:i'));
    }

    public function testHoraQueNoEncajaSeSaltaYSigueConElResto(): void
    {
        $schedule = $this->schedule([['09:00', '14:00'], ['15:30', '18:00']]);

        // La salida de las 14:00 no encaja (p. ej. ya hay algo raro fichado a mano);
        // el resto de bordes se rellenan igual.
        $created = $this->autofill($schedule, [], rejectedTimes: ['14:00'])->fill($this->worker(), $this->monday(), new User());

        $this->assertCount(3, $created);
        $this->assertSame(['09:00', '15:30', '18:00'], array_map(
            static fn (TimeEntry $e) => $e->getOccurredAt()->format('H:i'),
            $created,
        ));
    }

    public function testSinHorarioAplicableNoRellenaNada(): void
    {
        $created = $this->autofill(null)->fill($this->worker(), $this->monday(), new User());

        $this->assertSame([], $created);
    }

    public function testDisponibleSoloSiHayHorarioSinFestivoNiAusencia(): void
    {
        $schedule = $this->schedule([['09:00', '14:00']]);

        $this->assertTrue($this->autofill($schedule)->isAvailableFor($this->worker(), $this->monday()));
        $this->assertFalse($this->autofill(null)->isAvailableFor($this->worker(), $this->monday()));
        $this->assertFalse($this->autofill($this->schedule([]))->isAvailableFor($this->worker(), $this->monday()));
        $this->assertFalse($this->autofill($schedule, holidayDates: ['2026-06-22' => 'Festivo'])->isAvailableFor($this->worker(), $this->monday()));
        $this->assertFalse($this->autofill($schedule, approvedAbsences: [new Absence()])->isAvailableFor($this->worker(), $this->monday()));
    }
}
