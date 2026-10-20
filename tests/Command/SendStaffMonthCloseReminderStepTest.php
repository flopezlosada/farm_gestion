<?php

namespace App\Tests\Command;

use App\Command\SendStaffMonthCloseReminderCommand;
use PHPUnit\Framework\TestCase;

/**
 * El escalón del aviso de cierre: qué aviso toca cada día del mes. Es lo que
 * decide que un reloj caído el día 1 no se coma el aviso (el día 2 sigue siendo
 * el escalón 1, aún sin enviar) y que pasado el 10 no se insista más.
 */
class SendStaffMonthCloseReminderStepTest extends TestCase
{
    public function testCadaDiaCaeEnElEscalonMasAltoAlcanzado(): void
    {
        $this->assertSame(1, SendStaffMonthCloseReminderCommand::stepFor(1));
        $this->assertSame(1, SendStaffMonthCloseReminderCommand::stepFor(4));
        $this->assertSame(5, SendStaffMonthCloseReminderCommand::stepFor(5));
        $this->assertSame(5, SendStaffMonthCloseReminderCommand::stepFor(9));
        $this->assertSame(10, SendStaffMonthCloseReminderCommand::stepFor(10));
        $this->assertSame(10, SendStaffMonthCloseReminderCommand::stepFor(31));
    }
}
