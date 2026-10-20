<?php

namespace App\Tests\Service\Staff;

use App\Entity\Worker;
use App\Entity\WorkMonthClose;
use App\Repository\WorkMonthCloseRepository;
use App\Service\Staff\ClosedWorkMonthException;
use App\Service\Staff\ClosedWorkMonths;
use App\Service\Staff\WorkMonth;
use PHPUnit\Framework\TestCase;

class ClosedWorkMonthsTest extends TestCase
{
    /**
     * Candado con unos meses aprobados.
     *
     * @param list<string> $closedKeys Meses aprobados, 'YYYY-MM'.
     */
    private function closedMonths(array $closedKeys): ClosedWorkMonths
    {
        $repo = $this->createMock(WorkMonthCloseRepository::class);
        $repo->method('findStanding')->willReturnCallback(
            fn (Worker $worker, WorkMonth $month) => \in_array($month->key(), $closedKeys, true)
                ? new WorkMonthClose($worker, $month, null, new \DateTimeImmutable())
                : null
        );

        return new ClosedWorkMonths($repo);
    }

    private function day(string $ymd): \DateTimeImmutable
    {
        return new \DateTimeImmutable($ymd, new \DateTimeZone('Europe/Madrid'));
    }

    public function testDiceSiElMesDeUnDiaEstaAprobado(): void
    {
        $closed = $this->closedMonths(['2026-09']);

        $this->assertTrue($closed->isClosed(new Worker(), $this->day('2026-09-15')));
        $this->assertFalse($closed->isClosed(new Worker(), $this->day('2026-10-01')));
    }

    public function testUnRangoQueRozaUnMesAprobadoSeRechaza(): void
    {
        // Vacaciones del 28 de septiembre al 3 de octubre: octubre está abierto,
        // pero septiembre no, y basta con uno.
        $closed = $this->closedMonths(['2026-09']);

        $this->expectException(ClosedWorkMonthException::class);
        $this->expectExceptionMessage('septiembre de 2026');

        $closed->assertRangeOpen(new Worker(), $this->day('2026-09-28'), $this->day('2026-10-03'));
    }

    public function testUnRangoQueCruzaVariosMesesAbiertosPasa(): void
    {
        $closed = $this->closedMonths(['2026-06']);

        $closed->assertRangeOpen(new Worker(), $this->day('2026-11-20'), $this->day('2027-01-10'));

        $this->addToAssertionCount(1);
    }

    public function testElUltimoMesDelRangoTambienCuenta(): void
    {
        $closed = $this->closedMonths(['2027-01']);

        $this->expectException(ClosedWorkMonthException::class);

        $closed->assertRangeOpen(new Worker(), $this->day('2026-12-30'), $this->day('2027-01-02'));
    }
}
