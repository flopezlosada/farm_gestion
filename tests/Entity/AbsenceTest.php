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
}
