<?php

namespace App\Tests\Service\Volunteering;

use App\Service\Volunteering\CreditedTime;
use PHPUnit\Framework\TestCase;

/**
 * Horas en pantalla, minutos por dentro.
 *
 * La conversión parece trivial y no lo es: aquí está el fallo que tumbaba la
 * página al dejar el campo en blanco, y la diferencia entre "no he puesto nada"
 * y "he puesto cero", que significan cosas contrarias.
 */
class CreditedTimeTest extends TestCase
{
    /**
     * EN BLANCO Y CERO NO SON LO MISMO. Vacío significa "usa lo que valga la
     * tarea"; cero significa "vino, pero esto no le computa". Confundirlos —con
     * un `?:`, por ejemplo— le devolvería a alguien las horas de la tarea justo
     * cuando se ha pedido lo contrario.
     */
    public function testEnBlancoNoEsCero(): void
    {
        $this->assertNull(CreditedTime::minutesFromHours(''));
        $this->assertNull(CreditedTime::minutesFromHours('   '));
        $this->assertNull(CreditedTime::minutesFromHours(null));
        $this->assertSame(0, CreditedTime::minutesFromHours('0'));
    }

    /**
     * Lo normal: horas enteras y medias.
     */
    public function testHorasEnterasYMedias(): void
    {
        $this->assertSame(240, CreditedTime::minutesFromHours('4'));
        $this->assertSame(30, CreditedTime::minutesFromHours('0.5'));
        $this->assertSame(90, CreditedTime::minutesFromHours('1.5'));
        $this->assertSame(15, CreditedTime::minutesFromHours('0.25'));
    }

    /**
     * Con coma, que es como se escribe un decimal en castellano. Un
     * input[type=number] normaliza a punto, pero el mismo campo puede llegar de
     * un formulario sin JavaScript o de alguien que lo escribió a mano.
     */
    public function testAceptaLaComaDecimal(): void
    {
        $this->assertSame(90, CreditedTime::minutesFromHours('1,5'));
        $this->assertSame(30, CreditedTime::minutesFromHours('0,5'));
    }

    /**
     * Un día es el tope, y no se admiten negativos: lo que pasa de ahí es un
     * dedo torcido, y dejarlo entrar mete horas imposibles en el contador de
     * alguien.
     */
    public function testAcotaLoImposible(): void
    {
        $this->assertSame(1440, CreditedTime::minutesFromHours('48'));
        $this->assertSame(0, CreditedTime::minutesFromHours('-3'));
    }

    /**
     * Un array no revienta: llega así si alguien manipula el POST, y lo que toca
     * es ignorarlo, no tumbar la página — que es justo lo que hacía getInt().
     */
    public function testUnArrayNoRevienta(): void
    {
        $this->assertNull(CreditedTime::minutesFromHours(['4']));
    }

    /**
     * Y de vuelta, para pintar el formulario.
     */
    public function testDeMinutosAHoras(): void
    {
        $this->assertSame(4.0, CreditedTime::hoursFromMinutes(240));
        $this->assertSame(0.5, CreditedTime::hoursFromMinutes(30));
        $this->assertNull(CreditedTime::hoursFromMinutes(null));
    }

    /**
     * Ida y vuelta sin pérdida en los valores que se usan de verdad: si media
     * hora volviera como 0,49 al reeditar una tarea, cada guardado le iría
     * comiendo minutos.
     */
    public function testIdaYVueltaNoPierdeMinutos(): void
    {
        foreach ([15, 30, 45, 60, 90, 120, 240] as $minutes) {
            $this->assertSame(
                $minutes,
                CreditedTime::minutesFromHours((string) CreditedTime::hoursFromMinutes($minutes)),
                sprintf('%d minutos tienen que volver como %d.', $minutes, $minutes)
            );
        }
    }
}
