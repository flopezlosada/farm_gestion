<?php

namespace App\Tests\Entity;

use App\Entity\DeliveryException;
use PHPUnit\Framework\TestCase;

class DeliveryExceptionTest extends TestCase
{
    public function testRegistroSinShiftedDateRepresentaCancelacion(): void
    {
        $exception = new DeliveryException();
        $exception->setFridayDate(new \DateTimeImmutable('2026-08-14'));
        $exception->setShiftedDate(null);

        $this->assertTrue($exception->isCancelled());
        $this->assertFalse($exception->isShifted());
    }

    public function testRegistroConShiftedDateDistintaRepresentaDesplazamiento(): void
    {
        $exception = new DeliveryException();
        $exception->setFridayDate(new \DateTimeImmutable('2026-08-14'));
        $exception->setShiftedDate(new \DateTimeImmutable('2026-08-13'));

        $this->assertFalse($exception->isCancelled());
        $this->assertTrue($exception->isShifted());
    }

    public function testShiftedDateIgualAFridayDateNoEsDesplazamiento(): void
    {
        $exception = new DeliveryException();
        $exception->setFridayDate(new \DateTimeImmutable('2026-08-14'));
        $exception->setShiftedDate(new \DateTimeImmutable('2026-08-14'));

        $this->assertFalse($exception->isCancelled());
        $this->assertFalse($exception->isShifted());
    }
}
