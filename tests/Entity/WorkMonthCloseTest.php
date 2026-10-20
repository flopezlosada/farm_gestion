<?php

namespace App\Tests\Entity;

use App\Entity\User;
use App\Entity\Worker;
use App\Entity\WorkMonthClose;
use App\Service\Staff\WorkMonth;
use PHPUnit\Framework\TestCase;

class WorkMonthCloseTest extends TestCase
{
    public function testNaceVigenteConQuienYCuandoLoAprobo(): void
    {
        $by = new User();
        $at = new \DateTimeImmutable('2026-10-01 09:00');
        $close = new WorkMonthClose(new Worker(), WorkMonth::of(2026, 9), $by, $at);

        $this->assertTrue($close->isStanding());
        $this->assertTrue($close->getMonth()->equals(WorkMonth::of(2026, 9)));
        $this->assertSame($by, $close->getApprovedBy());
        $this->assertSame($at, $close->getApprovedAt());
    }

    public function testReabrirDejaLaTrazaYYaNoVale(): void
    {
        $close = new WorkMonthClose(new Worker(), WorkMonth::of(2026, 9), null, new \DateTimeImmutable('2026-10-01'));
        $by = new User();

        $close->reopen($by, new \DateTimeImmutable('2026-10-05'));

        $this->assertFalse($close->isStanding());
        $this->assertSame($by, $close->getReopenedBy());
        $this->assertSame('2026-10-05', $close->getReopenedAt()->format('Y-m-d'));
    }

    public function testNoSeReabreDosVeces(): void
    {
        $close = new WorkMonthClose(new Worker(), WorkMonth::of(2026, 9), null, new \DateTimeImmutable('2026-10-01'));
        $close->reopen(null, new \DateTimeImmutable('2026-10-05'));

        $this->expectException(\LogicException::class);

        $close->reopen(null, new \DateTimeImmutable('2026-10-06'));
    }
}
