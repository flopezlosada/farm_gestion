<?php

namespace App\Tests\Service\Staff;

use App\Entity\TimeEntry;
use App\Service\Staff\TimeClock;
use PHPUnit\Framework\TestCase;

/**
 * Lógica pura de alternancia entrada/salida del reloj de fichaje. La parte que
 * persiste (clockNext) se ejercita en pruebas funcionales / navegador.
 */
class TimeClockTest extends TestCase
{
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
