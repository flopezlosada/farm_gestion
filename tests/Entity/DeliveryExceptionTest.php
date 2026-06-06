<?php

namespace App\Tests\Entity;

use App\Entity\Basket;
use App\Entity\DeliveryException;
use App\Entity\Node;
use PHPUnit\Framework\TestCase;

class DeliveryExceptionTest extends TestCase
{
    public function testRegistroSinShiftedDateRepresentaCancelacion(): void
    {
        $exception = new DeliveryException();
        $exception->setBasket(new Basket());
        $exception->setShiftedDate(null);

        $this->assertTrue($exception->isCancelled());
        $this->assertFalse($exception->isShifted());
    }

    public function testRegistroConShiftedDateRepresentaDesplazamiento(): void
    {
        $exception = new DeliveryException();
        $exception->setBasket(new Basket());
        $exception->setShiftedDate(new \DateTimeImmutable('2026-08-13'));

        $this->assertFalse($exception->isCancelled());
        $this->assertTrue($exception->isShifted());
    }

    public function testSinNodoLaExcepcionEsGlobal(): void
    {
        $exception = new DeliveryException();
        $exception->setBasket(new Basket());

        $this->assertTrue($exception->isGlobal());
    }

    public function testConNodoLaExcepcionNoEsGlobal(): void
    {
        $exception = new DeliveryException();
        $exception->setBasket(new Basket());
        $exception->setNode(new Node());

        $this->assertFalse($exception->isGlobal());
    }
}
