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
use App\Service\Staff\ClosedWorkMonths;
use App\Service\Staff\PunchSequenceException;
use App\Service\Staff\ScheduleAutofill;
use App\Service\Staff\TimeEntryCorrector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

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
        string $now = '2026-06-23 12:00:00',
        bool $monthClosed = false,
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

        $closed = $this->createMock(ClosedWorkMonths::class);
        $closed->method('isClosed')->willReturn($monthClosed);

        return new ScheduleAutofill($schedules, $holidays, $absences, $timeEntries, $corrector, new MockClock($now, 'Europe/Madrid'), $closed);
    }

    private function monday(): \DateTimeImmutable
    {
        // 2026-06-22 es lunes.
        return new \DateTimeImmutable('2026-06-22 00:00:00', new \DateTimeZone('Europe/Madrid'));
    }

    public function testDiaLimpioSinFicharRellenaLosCuatroBordes(): void
    {
        $schedule = $this->schedule([['09:00', '14:00'], ['15:30', '18:00']]);
        $created = $this->autofill($schedule)->fill($this->worker(), $this->monday(), new User(), TimeEntry::SOURCE_SELF);

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

        $created = $this->autofill($schedule, [$already])->fill($this->worker(), $this->monday(), new User(), TimeEntry::SOURCE_SELF);

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
        $created = $this->autofill($schedule, [], rejectedTimes: ['14:00'])->fill($this->worker(), $this->monday(), new User(), TimeEntry::SOURCE_SELF);

        $this->assertCount(3, $created);
        $this->assertSame(['09:00', '15:30', '18:00'], array_map(
            static fn (TimeEntry $e) => $e->getOccurredAt()->format('H:i'),
            $created,
        ));
    }

    public function testSinHorarioAplicableNoRellenaNada(): void
    {
        $created = $this->autofill(null)->fill($this->worker(), $this->monday(), new User(), TimeEntry::SOURCE_SELF);

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

    public function testDiaFuturoNoOfreceRellenarYHoySi(): void
    {
        $schedule = $this->schedule([['09:00', '14:00']]);

        // El domingo anterior, el lunes aún no ha llegado: no se rellena por adelantado.
        $this->assertFalse($this->autofill($schedule, now: '2026-06-21 20:00:00')->isAvailableFor($this->worker(), $this->monday()));
        // El propio lunes a media mañana sí: el corrector sólo admitirá los bordes ya pasados.
        $this->assertTrue($this->autofill($schedule, now: '2026-06-22 10:30:00')->isAvailableFor($this->worker(), $this->monday()));
    }

    public function testMesAprobadoNiSeOfreceNiRellena(): void
    {
        $schedule = $this->schedule([['09:00', '14:00']]);
        $autofill = $this->autofill($schedule, monthClosed: true);

        $this->assertFalse($autofill->isAvailableFor($this->worker(), $this->monday()));
        $this->assertSame([], $autofill->fill($this->worker(), $this->monday(), new User(), TimeEntry::SOURCE_SELF));
    }

    public function testElOrigenQueSeIndicaQuedaEnCadaFichaje(): void
    {
        $schedule = $this->schedule([['09:00', '14:00']]);
        $supervisor = new User();

        $created = $this->autofill($schedule)->fill($this->worker(), $this->monday(), $supervisor, TimeEntry::SOURCE_SUPERVISOR);

        // Lo rellena el supervisor: la traza tiene que decirlo, no «fichado por ella».
        $this->assertCount(2, $created);
        foreach ($created as $entry) {
            $this->assertSame(TimeEntry::SOURCE_SUPERVISOR, $entry->getSource());
            $this->assertSame($supervisor, $entry->getAuthor());
        }
    }
}
