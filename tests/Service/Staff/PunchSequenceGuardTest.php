<?php

namespace App\Tests\Service\Staff;

use App\Entity\TimeEntry;
use App\Service\Staff\PunchSequenceGuard;
use PHPUnit\Framework\TestCase;

/**
 * Alternancia entrada-salida al añadir/corregir un fichaje. La regla es local:
 * los vecinos por hora deben ser del tipo contrario y el día no empieza por
 * salida. Cubre el desastre que motivó esto (varias salidas seguidas) y el caso
 * legítimo de intercalar un fichaje olvidado a media mañana.
 */
class PunchSequenceGuardTest extends TestCase
{
    private PunchSequenceGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new PunchSequenceGuard();
    }

    private function at(string $hm): \DateTimeImmutable
    {
        return new \DateTimeImmutable("2026-06-19 $hm:00", new \DateTimeZone('Europe/Madrid'));
    }

    private function entry(string $type, string $hm): TimeEntry
    {
        return (new TimeEntry())->setType($type)->setOccurredAt($this->at($hm));
    }

    public function testEntradaEnDiaVacioEsValida(): void
    {
        $this->assertNull($this->guard->check(TimeEntry::TYPE_IN, $this->at('09:00'), []));
    }

    public function testSalidaEnDiaVacioSeRechaza(): void
    {
        // Una salida no puede ser el primer fichaje del día.
        $this->assertNotNull($this->guard->check(TimeEntry::TYPE_OUT, $this->at('09:00'), []));
    }

    public function testSalidaTrasEntradaAbiertaEsValida(): void
    {
        $day = [$this->entry(TimeEntry::TYPE_IN, '09:00')];
        $this->assertNull($this->guard->check(TimeEntry::TYPE_OUT, $this->at('14:00'), $day));
    }

    public function testSegundaEntradaSinSalidaIntermediaSeRechaza(): void
    {
        $day = [$this->entry(TimeEntry::TYPE_IN, '09:00')];
        $this->assertNotNull($this->guard->check(TimeEntry::TYPE_IN, $this->at('10:00'), $day));
    }

    public function testSegundaSalidaSeguidaSeRechaza(): void
    {
        // Como en la pantalla del desastre: ya hay entrada+salida, no se apila otra salida suelta.
        $day = [
            $this->entry(TimeEntry::TYPE_IN, '09:00'),
            $this->entry(TimeEntry::TYPE_OUT, '13:00'),
        ];
        $this->assertNotNull($this->guard->check(TimeEntry::TYPE_OUT, $this->at('14:00'), $day));
    }

    public function testEntradaQueCompletaUnaSalidaHuerfanaEsValida(): void
    {
        // Día con una salida suelta (estado roto): meter la entrada que falta
        // ANTES de ella la completa y es válido.
        $day = [$this->entry(TimeEntry::TYPE_OUT, '13:00')];
        $this->assertNull($this->guard->check(TimeEntry::TYPE_IN, $this->at('09:00'), $day));
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
        $this->assertNotNull($this->guard->check(TimeEntry::TYPE_IN, $this->at('09:00'), $day));
    }

    public function testSalidaIntermediaEntreEntradasEsValida(): void
    {
        // Mañana abierta + tarde: meter la salida de la mañana entre las dos entradas.
        $day = [
            $this->entry(TimeEntry::TYPE_IN, '09:00'),
            $this->entry(TimeEntry::TYPE_IN, '14:00'),
            $this->entry(TimeEntry::TYPE_OUT, '18:00'),
        ];
        $this->assertNull($this->guard->check(TimeEntry::TYPE_OUT, $this->at('13:00'), $day));
    }
}
