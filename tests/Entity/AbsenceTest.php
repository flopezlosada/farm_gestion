<?php

namespace App\Tests\Entity;

use App\Entity\Absence;
use App\Entity\Worker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

class AbsenceTest extends TestCase
{
    public function testVacacionAprobadaDescuentaDelSaldo(): void
    {
        $absence = (new Absence())
            ->setType(Absence::TYPE_VACATION)
            ->setStatus(Absence::STATUS_APPROVED);

        $this->assertTrue($absence->countsAgainstVacationBalance());
    }

    public function testVacacionSoloSolicitadaNoDescuenta(): void
    {
        $absence = (new Absence())
            ->setType(Absence::TYPE_VACATION)
            ->setStatus(Absence::STATUS_REQUESTED);

        $this->assertFalse($absence->countsAgainstVacationBalance());
    }

    public function testBajaAprobadaNoDescuentaDelSaldoDeVacaciones(): void
    {
        $absence = (new Absence())
            ->setType(Absence::TYPE_SICK_LEAVE)
            ->setStatus(Absence::STATUS_APPROVED);

        $this->assertFalse($absence->countsAgainstVacationBalance());
    }

    public function testAusenciaDeUnSoloDiaCuentaUno(): void
    {
        $absence = (new Absence())
            ->setStartDate(new \DateTimeImmutable('2026-08-10'))
            ->setEndDate(new \DateTimeImmutable('2026-08-10'));

        $this->assertSame(1, $absence->getCalendarDayCount());
    }

    public function testRangoDeAusenciaCuentaAmbosExtremos(): void
    {
        $absence = (new Absence())
            ->setStartDate(new \DateTimeImmutable('2026-08-10'))
            ->setEndDate(new \DateTimeImmutable('2026-08-14'));

        $this->assertSame(5, $absence->getCalendarDayCount());
    }

    public function testCancelarAusenciaYaTerminadaNoLaToca(): void
    {
        $absence = (new Absence())
            ->setStatus(Absence::STATUS_APPROVED)
            ->setStartDate(new \DateTimeImmutable('2026-08-01'))
            ->setEndDate(new \DateTimeImmutable('2026-08-05'));

        $today = new \DateTimeImmutable('2026-08-10');

        $this->assertSame(Absence::CANCEL_TOO_LATE, $absence->cancelAsOf($today));
        $this->assertSame(Absence::STATUS_APPROVED, $absence->getStatus());
        $this->assertEquals(new \DateTimeImmutable('2026-08-05'), $absence->getEndDate());
    }

    public function testCancelarAusenciaFuturaLaCancelaEntera(): void
    {
        $absence = (new Absence())
            ->setStatus(Absence::STATUS_APPROVED)
            ->setStartDate(new \DateTimeImmutable('2026-08-20'))
            ->setEndDate(new \DateTimeImmutable('2026-08-25'));

        $today = new \DateTimeImmutable('2026-08-10');

        $this->assertSame(Absence::CANCEL_FULL, $absence->cancelAsOf($today));
        $this->assertSame(Absence::STATUS_CANCELLED, $absence->getStatus());
        $this->assertEquals(new \DateTimeImmutable('2026-08-25'), $absence->getEndDate());
    }

    public function testCancelarAusenciaEnCursoLaTruncaAHoy(): void
    {
        $absence = (new Absence())
            ->setStatus(Absence::STATUS_APPROVED)
            ->setStartDate(new \DateTimeImmutable('2026-08-05'))
            ->setEndDate(new \DateTimeImmutable('2026-08-25'));

        $today = new \DateTimeImmutable('2026-08-10');

        // En curso: se trunca a hoy (conserva lo disfrutado) y NO se marca cancelada.
        $this->assertSame(Absence::CANCEL_TRUNCATED, $absence->cancelAsOf($today));
        $this->assertSame(Absence::STATUS_APPROVED, $absence->getStatus());
        $this->assertEquals($today, $absence->getEndDate());
    }

    public function testCancelarAusenciaQueEmpiezaHoyLaTrunca(): void
    {
        $absence = (new Absence())
            ->setStatus(Absence::STATUS_APPROVED)
            ->setStartDate(new \DateTimeImmutable('2026-08-10'))
            ->setEndDate(new \DateTimeImmutable('2026-08-25'));

        $today = new \DateTimeImmutable('2026-08-10');

        // El día de inicio es hoy: cuenta como en curso, trunca a hoy (1 día).
        $this->assertSame(Absence::CANCEL_TRUNCATED, $absence->cancelAsOf($today));
        $this->assertEquals($today, $absence->getEndDate());
    }

    public function testCancelarAusenciaQueTerminoAyerEsDemasiadoTarde(): void
    {
        $absence = (new Absence())
            ->setStatus(Absence::STATUS_APPROVED)
            ->setStartDate(new \DateTimeImmutable('2026-08-05'))
            ->setEndDate(new \DateTimeImmutable('2026-08-09'));

        // Hoy es el día siguiente al fin: ya terminó, no se toca.
        $this->assertSame(Absence::CANCEL_TOO_LATE, $absence->cancelAsOf(new \DateTimeImmutable('2026-08-10')));
        $this->assertEquals(new \DateTimeImmutable('2026-08-09'), $absence->getEndDate());
    }

    public function testCancelarAusenciaQueTerminaHoyLaTrunca(): void
    {
        $absence = (new Absence())
            ->setStatus(Absence::STATUS_APPROVED)
            ->setStartDate(new \DateTimeImmutable('2026-08-05'))
            ->setEndDate(new \DateTimeImmutable('2026-08-10'));

        $today = new \DateTimeImmutable('2026-08-10');

        // El último día es hoy: sigue en curso (límite inclusivo), trunca a hoy.
        $this->assertSame(Absence::CANCEL_TRUNCATED, $absence->cancelAsOf($today));
        $this->assertEquals($today, $absence->getEndDate());
    }

    public function testCancelarAusenciaSinFechasNoLaMuta(): void
    {
        $absence = (new Absence())->setStatus(Absence::STATUS_APPROVED);

        // Sin start/end no hay nada que cancelar con sentido: se trata como
        // "demasiado tarde" y NO se muta (ni estado ni fechas).
        $this->assertSame(Absence::CANCEL_TOO_LATE, $absence->cancelAsOf(new \DateTimeImmutable('2026-08-10')));
        $this->assertSame(Absence::STATUS_APPROVED, $absence->getStatus());
        $this->assertNull($absence->getEndDate());
    }

    public function testBajaSinFechaDeAltaEstaAbiertaYCubreHastaLoQueSePida(): void
    {
        $absence = $this->openSickLeave('2026-10-05');
        $horizon = new \DateTimeImmutable('2026-10-23');

        $this->assertTrue($absence->isOpen());
        $this->assertEquals($horizon, $absence->getEndDateOr($horizon));
        $this->assertSame(0, $absence->getCalendarDayCount());
    }

    public function testAusenciaCerradaIgnoraElHorizonte(): void
    {
        $absence = $this->openSickLeave('2026-10-05')->setEndDate(new \DateTimeImmutable('2026-10-09'));

        $this->assertFalse($absence->isOpen());
        $this->assertEquals(new \DateTimeImmutable('2026-10-09'), $absence->getEndDateOr(new \DateTimeImmutable('2026-12-31')));
    }

    public function testDarElAltaFijaElUltimoDiaDeBaja(): void
    {
        $absence = $this->openSickLeave('2026-10-05');

        $absence->closeOn(new \DateTimeImmutable('2026-10-20'));

        $this->assertFalse($absence->isOpen());
        $this->assertEquals(new \DateTimeImmutable('2026-10-20'), $absence->getEndDate());
    }

    public function testElAltaElMismoDiaDelInicioEsUnDiaDeBaja(): void
    {
        $absence = $this->openSickLeave('2026-10-05');

        $absence->closeOn(new \DateTimeImmutable('2026-10-05'));

        $this->assertSame(1, $absence->getCalendarDayCount());
    }

    public function testNoSePuedeDarElAltaAntesDelInicio(): void
    {
        $absence = $this->openSickLeave('2026-10-05');

        $this->expectException(\DomainException::class);
        $absence->closeOn(new \DateTimeImmutable('2026-10-04'));
    }

    public function testNoSePuedeDarElAltaDosVeces(): void
    {
        $absence = $this->openSickLeave('2026-10-05');
        $absence->closeOn(new \DateTimeImmutable('2026-10-20'));

        $this->expectException(\DomainException::class);
        $absence->closeOn(new \DateTimeImmutable('2026-10-21'));
    }

    public function testCancelarUnaBajaAbiertaEnCursoLaCierraHoy(): void
    {
        $absence = $this->openSickLeave('2026-10-05');
        $today = new \DateTimeImmutable('2026-10-23');

        $this->assertSame(Absence::CANCEL_TRUNCATED, $absence->cancelAsOf($today));
        $this->assertEquals($today, $absence->getEndDate());
        $this->assertSame(Absence::STATUS_APPROVED, $absence->getStatus());
    }

    public function testCancelarUnaBajaAbiertaQueNoHaEmpezadoLaAnula(): void
    {
        $absence = $this->openSickLeave('2026-11-02');

        $this->assertSame(Absence::CANCEL_FULL, $absence->cancelAsOf(new \DateTimeImmutable('2026-10-23')));
        $this->assertSame(Absence::STATUS_CANCELLED, $absence->getStatus());
        $this->assertTrue($absence->isOpen());
    }

    public function testSoloLaBajaMedicaPuedeQuedarSinFechaDeFin(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $this->assertCount(0, $validator->validate($this->openSickLeave('2026-10-05')), 'Una baja abierta es válida.');

        foreach ([Absence::TYPE_VACATION, Absence::TYPE_PERMIT, Absence::TYPE_STRIKE, Absence::TYPE_OTHER] as $type) {
            $violations = $validator->validate($this->openSickLeave('2026-10-05')->setType($type));
            $this->assertCount(1, $violations, $type . ' sin fecha de fin no debe validar.');
            $this->assertSame('endDate', $violations[0]->getPropertyPath());
        }
    }

    /**
     * Baja médica aprobada, sin fecha de alta. Lleva un Worker para que la
     * validación de la entidad entera no falle por otra cosa.
     */
    private function openSickLeave(string $start): Absence
    {
        return (new Absence())
            ->setWorker(new Worker())
            ->setType(Absence::TYPE_SICK_LEAVE)
            ->setStatus(Absence::STATUS_APPROVED)
            ->setStartDate(new \DateTimeImmutable($start));
    }
}
