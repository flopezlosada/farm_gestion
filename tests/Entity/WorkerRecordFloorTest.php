<?php

namespace App\Tests\Entity;

use App\Entity\Worker;
use PHPUnit\Framework\TestCase;

/**
 * El suelo del registro de jornada: el más tardío entre el alta y el inicio del
 * registro en la app.
 */
class WorkerRecordFloorTest extends TestCase
{
    public function testSinInicioDeRegistroElSueloEsElAlta(): void
    {
        $worker = (new Worker())->setHireDate(new \DateTimeImmutable('2017-06-09'));

        $this->assertEquals(new \DateTimeImmutable('2017-06-09'), $worker->getRecordFloor());
    }

    public function testConInicioDeRegistroPosteriorManda(): void
    {
        $worker = (new Worker())
            ->setHireDate(new \DateTimeImmutable('2017-06-09'))
            ->setRecordingStartDate(new \DateTimeImmutable('2026-10-22'));

        $this->assertEquals(new \DateTimeImmutable('2026-10-22'), $worker->getRecordFloor());
        // El alta no se toca: es la del contrato.
        $this->assertEquals(new \DateTimeImmutable('2017-06-09'), $worker->getHireDate());
    }

    public function testInicioDeRegistroAnteriorAlAltaNoAdelantaElSuelo(): void
    {
        $worker = (new Worker())
            ->setHireDate(new \DateTimeImmutable('2026-05-11'))
            ->setRecordingStartDate(new \DateTimeImmutable('2026-01-01'));

        $this->assertEquals(new \DateTimeImmutable('2026-05-11'), $worker->getRecordFloor());
    }

    public function testFichaSinAltaNoTieneSuelo(): void
    {
        $this->assertNull((new Worker())->setRecordingStartDate(new \DateTimeImmutable('2026-10-22'))->getRecordFloor());
    }

    /**
     * Fichar se corta en el alta, día incluido, y la hora no cuenta: a las 23:59
     * del día anterior aún no ha empezado.
     */
    public function testEmpiezaATrabajarElDiaDelAlta(): void
    {
        $worker = (new Worker())->setHireDate(new \DateTimeImmutable('2026-11-09'));

        $this->assertFalse($worker->hasStartedOn(new \DateTimeImmutable('2026-11-08 23:59:59')));
        $this->assertTrue($worker->hasStartedOn(new \DateTimeImmutable('2026-11-09 00:00:00')));
        $this->assertTrue($worker->hasStartedOn(new \DateTimeImmutable('2026-12-01')));
    }

    /**
     * El corte de fichar es el alta, no el inicio del registro en la app: lo de
     * antes de empezar a registrar aquí aún se puede apuntar (había contrato).
     */
    public function testElInicioDelRegistroNoImpideApuntarDiasTrasElAlta(): void
    {
        $worker = (new Worker())
            ->setHireDate(new \DateTimeImmutable('2017-06-09'))
            ->setRecordingStartDate(new \DateTimeImmutable('2026-10-22'));

        $this->assertTrue($worker->hasStartedOn(new \DateTimeImmutable('2026-10-01')));
    }

    public function testSinAltaNoHaEmpezado(): void
    {
        $this->assertFalse((new Worker())->hasStartedOn(new \DateTimeImmutable('2026-11-09')));
    }
}
