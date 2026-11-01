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
}
