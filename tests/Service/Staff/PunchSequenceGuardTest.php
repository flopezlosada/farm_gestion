<?php

namespace App\Tests\Service\Staff;

use App\Entity\TimeEntry;
use App\Service\Staff\PunchSequenceGuard;
use PHPUnit\Framework\TestCase;

/**
 * Alternancia entrada-salida al añadir/corregir un fichaje. La secuencia del día
 * resultante debe alternar empezando por entrada (la última entrada puede quedar
 * abierta). Cubre el desastre que motivó esto (varias salidas seguidas), el caso
 * legítimo de completar un huérfano, y mover una entrada por encima de su salida.
 */
class PunchSequenceGuardTest extends TestCase
{
    private PunchSequenceGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new PunchSequenceGuard();
    }

    private function hm(string $hm): \DateTimeImmutable
    {
        return new \DateTimeImmutable("2026-06-19 $hm:00", new \DateTimeZone('Europe/Madrid'));
    }

    private function entry(string $type, string $hm): TimeEntry
    {
        return (new TimeEntry())->setType($type)->setOccurredAt($this->hm($hm));
    }

    public function testEntradaEnDiaVacioEsValida(): void
    {
        $this->assertNull($this->guard->check(TimeEntry::TYPE_IN, $this->hm('09:00'), []));
    }

    public function testSalidaEnDiaVacioSeRechaza(): void
    {
        // Una salida no puede ser el primer fichaje del día.
        $this->assertNotNull($this->guard->check(TimeEntry::TYPE_OUT, $this->hm('09:00'), []));
    }

    public function testSalidaTrasEntradaAbiertaEsValida(): void
    {
        $day = [$this->entry(TimeEntry::TYPE_IN, '09:00')];
        $this->assertNull($this->guard->check(TimeEntry::TYPE_OUT, $this->hm('14:00'), $day));
    }

    public function testSegundaEntradaSinSalidaIntermediaSeRechaza(): void
    {
        $day = [$this->entry(TimeEntry::TYPE_IN, '09:00')];
        $this->assertNotNull($this->guard->check(TimeEntry::TYPE_IN, $this->hm('10:00'), $day));
    }

    public function testSegundaSalidaSeguidaSeRechaza(): void
    {
        // Como en la pantalla del desastre: ya hay entrada+salida, no se apila otra salida suelta.
        $day = [
            $this->entry(TimeEntry::TYPE_IN, '09:00'),
            $this->entry(TimeEntry::TYPE_OUT, '13:00'),
        ];
        $this->assertNotNull($this->guard->check(TimeEntry::TYPE_OUT, $this->hm('14:00'), $day));
    }

    public function testEntradaQueCompletaUnaSalidaHuerfanaEsValida(): void
    {
        // Día con una salida suelta (estado roto): meter la entrada que falta
        // ANTES de ella la completa y es válido.
        $day = [$this->entry(TimeEntry::TYPE_OUT, '13:00')];
        $this->assertNull($this->guard->check(TimeEntry::TYPE_IN, $this->hm('09:00'), $day));
    }

    public function testEntradaAntesDeOtraEntradaSeRechaza(): void
    {
        // Insertar una entrada por delante de otra entrada daría entrada+entrada:
        // un segmento entero no se intercala así (hay que añadir al final y
        // corregir la hora, o anular y rehacer en orden).
        $day = [
            $this->entry(TimeEntry::TYPE_IN, '14:00'),
            $this->entry(TimeEntry::TYPE_OUT, '18:00'),
        ];
        $this->assertNotNull($this->guard->check(TimeEntry::TYPE_IN, $this->hm('09:00'), $day));
    }

    public function testEntradaMovidaDespuesDeSuSalidaSeRechaza(): void
    {
        // Corregir una entrada a una hora POSTERIOR a su salida la deja huérfana:
        // el día quedaría salida(11:11), entrada(12:00) → empieza por salida.
        $day = [$this->entry(TimeEntry::TYPE_OUT, '11:11')];
        $this->assertNotNull($this->guard->check(TimeEntry::TYPE_IN, $this->hm('12:00'), $day));
    }

    public function testSalidaIntermediaEntreEntradasEsValida(): void
    {
        // Mañana abierta + tarde: meter la salida de la mañana entre las dos entradas.
        $day = [
            $this->entry(TimeEntry::TYPE_IN, '09:00'),
            $this->entry(TimeEntry::TYPE_IN, '14:00'),
            $this->entry(TimeEntry::TYPE_OUT, '18:00'),
        ];
        $this->assertNull($this->guard->check(TimeEntry::TYPE_OUT, $this->hm('13:00'), $day));
    }
}
