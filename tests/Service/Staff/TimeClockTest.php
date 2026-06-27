<?php

namespace App\Tests\Service\Staff;

use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Worker;
use App\Repository\TimeEntryRepository;
use App\Service\Staff\TimeClock;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Lógica pura de alternancia entrada/salida del reloj de fichaje. La parte que
 * persiste (clockNext) se ejercita en pruebas funcionales / navegador.
 */
class TimeClockTest extends TestCase
{
    /**
     * Construye un TimeClock con el reloj fijado a un instante de Madrid y un
     * repositorio que devuelve un último-global (stale) y un último-de-hoy a la
     * carta, para probar que el estado se decide SOLO con el de hoy.
     */
    private function clockAt(string $madridTime, ?TimeEntry $globalLast, ?TimeEntry $todayLast, ?EntityManagerInterface $em = null): TimeClock
    {
        $repo = $this->createMock(TimeEntryRepository::class);
        $repo->method('findLastEffectiveForWorker')->willReturn($globalLast);
        $repo->method('findLastEffectiveForWorkerBetween')->willReturn($todayLast);

        return new TimeClock(
            $em ?? $this->createMock(EntityManagerInterface::class),
            $repo,
            new MockClock($madridTime, 'Europe/Madrid'),
        );
    }

    public function testEntradaAbiertaDeAyerNoCuentaComoTrabajandoHoy(): void
    {
        // Ayer quedó una entrada sin cerrar (último global = IN), pero hoy no hay
        // ningún fichaje: el estado se reinicia, no está "trabajando".
        $staleIn = (new TimeEntry())->setType(TimeEntry::TYPE_IN);
        $clock = $this->clockAt('2026-06-27 09:30:00', $staleIn, null);

        $this->assertFalse($clock->hasOpenInterval(new Worker()));
    }

    public function testClockNextArrancaEnEntradaAunqueQuedaraEntradaAbiertaAyer(): void
    {
        $staleIn = (new TimeEntry())->setType(TimeEntry::TYPE_IN);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $clock = $this->clockAt('2026-06-27 09:30:00', $staleIn, null, $em);
        $entry = $clock->clockNext(new Worker(), new User());

        $this->assertSame(TimeEntry::TYPE_IN, $entry->getType());
        $this->assertSame('2026-06-27', $entry->getOccurredAt()->format('Y-m-d'));
    }

    public function testTrasFicharEntradaHoyElSiguienteEsSalida(): void
    {
        $todayIn = (new TimeEntry())->setType(TimeEntry::TYPE_IN);
        $clock = $this->clockAt('2026-06-27 14:00:00', $todayIn, $todayIn);

        $this->assertTrue($clock->hasOpenInterval(new Worker()));
    }

    public function testSinFichajesPreviosElSiguienteEsEntrada(): void
    {
        $this->assertSame(TimeEntry::TYPE_IN, TimeClock::nextTypeAfter(null));
    }

    public function testTrasUnaEntradaElSiguienteEsSalida(): void
    {
        $last = (new TimeEntry())->setType(TimeEntry::TYPE_IN);

        $this->assertSame(TimeEntry::TYPE_OUT, TimeClock::nextTypeAfter($last));
    }

    public function testTrasUnaSalidaElSiguienteEsEntrada(): void
    {
        $last = (new TimeEntry())->setType(TimeEntry::TYPE_OUT);

        $this->assertSame(TimeEntry::TYPE_IN, TimeClock::nextTypeAfter($last));
    }
}
