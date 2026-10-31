<?php

namespace App\Tests\Service\Accounting\Remittance;

use App\Service\Accounting\Remittance\BankBusinessDays;
use PHPUnit\Framework\TestCase;

/**
 * Los días hábiles de los adeudos SEPA: lunes a viernes menos los cierres de TARGET2.
 * Las Pascuas son las del calendario (2026: 5 de abril; 2027: 28 de marzo).
 */
class BankBusinessDaysTest extends TestCase
{
    /** El domingo de Pascua de varios años, incluido uno de marzo. */
    public function testElDomingoDePascua(): void
    {
        $this->assertSame('2024-03-31', BankBusinessDays::easterSunday(2024)->format('Y-m-d'));
        $this->assertSame('2025-04-20', BankBusinessDays::easterSunday(2025)->format('Y-m-d'));
        $this->assertSame('2026-04-05', BankBusinessDays::easterSunday(2026)->format('Y-m-d'));
        $this->assertSame('2027-03-28', BankBusinessDays::easterSunday(2027)->format('Y-m-d'));
        $this->assertSame('2028-04-16', BankBusinessDays::easterSunday(2028)->format('Y-m-d'));
    }

    /**
     * Cierran los fines de semana y los seis de TARGET2; abren los festivos que no son
     * de TARGET2 (el 12 de octubre, el Jueves Santo, el 6 de enero).
     */
    public function testQueDiasSonHabiles(): void
    {
        $closed = ['2026-10-17', '2026-10-18', '2026-01-01', '2026-04-03', '2026-04-06', '2026-05-01', '2026-12-25', '2027-12-26' /* domingo */, '2028-12-26'];
        foreach ($closed as $day) {
            $this->assertFalse(BankBusinessDays::isBusinessDay(new \DateTimeImmutable($day)), $day);
        }
        $open = ['2026-10-12', '2026-04-02', '2026-01-06', '2026-04-07', '2026-12-24', '2026-11-18'];
        foreach ($open as $day) {
            $this->assertTrue(BankBusinessDays::isBusinessDay(new \DateTimeImmutable($day)), $day);
        }
    }

    /**
     * Un cargo pedido en día no hábil se hace el siguiente: el sábado pasa al lunes; el
     * Viernes Santo, saltando el Lunes de Pascua, al martes; Navidad en viernes salta
     * el 26 y el fin de semana. Un día hábil se queda, sin hora.
     */
    public function testElSiguienteHabil(): void
    {
        $this->assertSame('2026-10-19', BankBusinessDays::nextOrSame(new \DateTimeImmutable('2026-10-17'))->format('Y-m-d'));
        $this->assertSame('2026-04-07', BankBusinessDays::nextOrSame(new \DateTimeImmutable('2026-04-03'))->format('Y-m-d'));
        $this->assertSame('2026-12-28', BankBusinessDays::nextOrSame(new \DateTimeImmutable('2026-12-25'))->format('Y-m-d'));
        $this->assertSame('2026-11-18 00:00', BankBusinessDays::nextOrSame(new \DateTimeImmutable('2026-11-18 16:30'))->format('Y-m-d H:i'));
    }

    /** Sumar y restar días hábiles se salta los cierres; cero deja el día como está. */
    public function testSumarYRestarDiasHabiles(): void
    {
        $monday = new \DateTimeImmutable('2026-10-19');
        $this->assertSame('2026-10-21', BankBusinessDays::add($monday, 2)->format('Y-m-d'));
        $this->assertSame('2026-10-15', BankBusinessDays::add($monday, -2)->format('Y-m-d'), 'del lunes, dos atrás es el jueves');
        $this->assertSame('2026-04-01', BankBusinessDays::add(new \DateTimeImmutable('2026-04-07'), -2)->format('Y-m-d'), 'saltando el Lunes de Pascua y el Viernes Santo');
        $this->assertSame('2026-04-09', BankBusinessDays::add(new \DateTimeImmutable('2026-04-07'), 2)->format('Y-m-d'));
        $this->assertSame('2026-12-29', BankBusinessDays::add(new \DateTimeImmutable('2026-12-24'), 2)->format('Y-m-d'));
        $this->assertSame('2026-10-17', BankBusinessDays::add(new \DateTimeImmutable('2026-10-17'), 0)->format('Y-m-d'));
    }
}
