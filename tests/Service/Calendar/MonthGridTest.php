<?php

namespace App\Tests\Service\Calendar;

use App\Service\Calendar\MonthGrid;
use PHPUnit\Framework\TestCase;

/**
 * La rejilla mensual común: semanas enteras de lunes a domingo que cubren el
 * mes. Es la base de todos los calendarios mensuales, así que un fallo aquí
 * descoloca los días en varias pantallas a la vez.
 */
class MonthGridTest extends TestCase
{
    /**
     * Septiembre de 2026 empieza en martes y acaba en miércoles: la rejilla
     * arranca el lunes 31 de agosto y termina el domingo 4 de octubre, cinco
     * semanas.
     */
    public function testLasSemanasCubrenElMesDeLunesADomingo(): void
    {
        $weeks = MonthGrid::weeks(2026, 9);

        $this->assertCount(5, $weeks);
        $this->assertSame('2026-08-31', $weeks[0][0]->format('Y-m-d'));
        $this->assertSame('2026-10-04', $weeks[4][6]->format('Y-m-d'));
        foreach ($weeks as $week) {
            $this->assertCount(7, $week);
            $this->assertSame('1', $week[0]->format('N'), 'Cada semana empieza en lunes.');
        }
    }

    /**
     * Un mes que empieza en lunes no arrastra una semana de relleno delante:
     * junio de 2026 arranca el propio lunes 1.
     */
    public function testUnMesQueEmpiezaEnLunesNoLlevaRellenoDelante(): void
    {
        $weeks = MonthGrid::weeks(2026, 6);

        $this->assertSame('2026-06-01', $weeks[0][0]->format('Y-m-d'));
        $this->assertTrue(MonthGrid::inMonth($weeks[0][0], 6));
    }

    /**
     * El relleno se distingue del mes: el 31 de agosto que abre la rejilla de
     * septiembre no es de septiembre.
     */
    public function testElRellenoNoEsDelMes(): void
    {
        $weeks = MonthGrid::weeks(2026, 9);

        $this->assertFalse(MonthGrid::inMonth($weeks[0][0], 9));
        $this->assertTrue(MonthGrid::inMonth($weeks[0][1], 9));
    }
}
