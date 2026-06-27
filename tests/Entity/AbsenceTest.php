<?php

namespace App\Tests\Entity;

use App\Entity\Absence;
use PHPUnit\Framework\TestCase;

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
}
