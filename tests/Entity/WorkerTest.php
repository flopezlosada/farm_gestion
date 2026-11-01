<?php

namespace App\Tests\Entity;

use App\Entity\Worker;
use App\Entity\WorkerBasketWeek;
use App\Entity\WorkerLeaveBasket;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * La baja de un trabajador y lo que decide sobre su cesta, con fechas fijas: el día de
 * la baja aún trabaja, y las fechas tienen que encajar entre sí.
 */
class WorkerTest extends KernelTestCase
{
    private static function worker(?string $leave = null, ?WorkerLeaveBasket $after = null, ?string $giftUntil = null): Worker
    {
        return (new Worker())
            ->setName('Alba')
            ->setHireDate(new \DateTimeImmutable('2020-03-02'))
            ->setWorkdayType(Worker::WORKDAY_FULL_TIME)
            ->setLeaveDate($leave !== null ? new \DateTimeImmutable($leave) : null)
            ->setBasketAfterLeave($after)
            ->setBasketGiftUntil($giftUntil !== null ? new \DateTimeImmutable($giftUntil) : null);
    }

    /** El último día cuenta como trabajado; el siguiente ya no. Sin baja, siempre. */
    public function testElDiaDeLaBajaAunTrabaja(): void
    {
        $worker = self::worker('2026-08-17');

        $this->assertTrue($worker->isActiveOn(new \DateTimeImmutable('2026-08-17')));
        $this->assertFalse($worker->isActiveOn(new \DateTimeImmutable('2026-08-18')));
        $this->assertTrue(self::worker()->isActiveOn(new \DateTimeImmutable('2099-01-01')));
    }

    /** Antes del alta paga como cualquiera; del alta a la baja, gratis; después, según se decida. */
    public function testLaCestaSegunElDia(): void
    {
        $this->assertNull(self::worker()->basketWeek(new \DateTimeImmutable('2020-02-28')), 'Antes del alta.');
        $this->assertSame(WorkerBasketWeek::Free, self::worker('2026-08-17')->basketWeek(new \DateTimeImmutable('2026-08-17')));
        $this->assertSame(WorkerBasketWeek::Undecided, self::worker('2026-08-17')->basketWeek(new \DateTimeImmutable('2026-08-21')));
        $this->assertNull(self::worker('2026-08-17', WorkerLeaveBasket::Paid)->basketWeek(new \DateTimeImmutable('2026-08-21')));
        $this->assertSame(WorkerBasketWeek::Free, self::worker('2026-08-17', WorkerLeaveBasket::Gift, '2026-08-21')->basketWeek(new \DateTimeImmutable('2026-08-21')), 'El último día del regalo cuenta.');
        $this->assertNull(self::worker('2026-08-17', WorkerLeaveBasket::Gift, '2026-08-21')->basketWeek(new \DateTimeImmutable('2026-08-28')));
    }

    /** Las fechas tienen que encajar, y sin baja no hay nada que decidir de la cesta. */
    public function testLasFechasTienenQueEncajar(): void
    {
        $validator = static::getContainer()->get(ValidatorInterface::class);
        $paths = static fn (Worker $w): array => array_map(static fn ($v): string => $v->getPropertyPath(), iterator_to_array($validator->validate($w)));

        $this->assertSame([], $paths(self::worker('2026-08-17', WorkerLeaveBasket::Gift, '2026-12-31')));
        $this->assertSame(['leaveDate'], $paths(self::worker('2019-12-31')));
        $this->assertSame(['basketAfterLeave'], $paths(self::worker(null, WorkerLeaveBasket::Paid)));
        $this->assertSame(['basketGiftUntil'], $paths(self::worker('2026-08-17', WorkerLeaveBasket::Gift, '2026-08-16')));
    }
}
