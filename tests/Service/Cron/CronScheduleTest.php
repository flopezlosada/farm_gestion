<?php

namespace App\Tests\Service\Cron;

use App\Entity\CronRun;
use App\Service\Cron\CronManifest;
use App\Service\Cron\CronSchedule;
use PHPUnit\Framework\TestCase;

/**
 * El evaluador de cadencias: la pieza que decide, en cada tick, a qué tareas les
 * toca correr.
 *
 * Es la regla más delicada de todo el planificador, porque un error aquí no da
 * un fallo visible: da una tarea que no corre y nadie echa de menos hasta que
 * alguien se queda sin su cesta. Por eso los casos que más se cuidan son los de
 * recuperación —el tick que se perdió— y los de frontera con la hora declarada.
 */
class CronScheduleTest extends TestCase
{
    /** Cadencia del congelado real: los lunes a las 06:00. */
    private const WEEKLY = ['freq' => 'weekly', 'dow' => 1, 'hour' => 6];

    /** Cadencia del recordatorio real: a diario a las 09:00. */
    private const DAILY = ['freq' => 'daily', 'hour' => 9];

    /**
     * Una tarea que nunca ha corrido siempre toca: no hay constancia de que se
     * hiciera, y las tareas son idempotentes por estado.
     */
    public function testSinNingunaEjecucionSiempreToca(): void
    {
        $this->assertTrue($this->schedule()->isDue(self::DAILY, null, $this->moment('2099-03-04 09:30')));
    }

    /**
     * Lo normal: corrió ayer, hoy ya ha pasado su hora, le toca.
     */
    public function testDiariaTocaCuandoPasaLaHoraYNoHaCorridoHoy(): void
    {
        $ayer = $this->execution('2099-03-03 09:00');

        $this->assertTrue($this->schedule()->isDue(self::DAILY, $ayer, $this->moment('2099-03-04 09:00')));
        $this->assertTrue($this->schedule()->isDue(self::DAILY, $ayer, $this->moment('2099-03-04 23:59')));
    }

    /**
     * Ya corrió hoy después de su hora: no se repite por muchos ticks que pasen.
     * Es lo que hace que un tick horario no dispare la misma tarea 24 veces.
     */
    public function testDiariaNoSeRepiteSiYaCorrioHoy(): void
    {
        $hoy = $this->execution('2099-03-04 09:02');

        $this->assertFalse($this->schedule()->isDue(self::DAILY, $hoy, $this->moment('2099-03-04 10:00')));
        $this->assertFalse($this->schedule()->isDue(self::DAILY, $hoy, $this->moment('2099-03-04 23:00')));
    }

    /**
     * Antes de su hora no toca, aunque hoy no haya corrido todavía: la anterior
     * ejecución cubre la ocurrencia de ayer.
     */
    public function testDiariaNoTocaAntesDeSuHora(): void
    {
        $ayer = $this->execution('2099-03-03 09:01');

        $this->assertFalse($this->schedule()->isDue(self::DAILY, $ayer, $this->moment('2099-03-04 08:59')));
    }

    /**
     * EL CASO QUE JUSTIFICA LA REGLA. El tick del lunes a las 06:00 no llegó
     * (servidor caído, reloj dormido, lo que sea). El martes por la mañana la
     * tarea SIGUE tocando, porque no ha corrido desde la última vez que le
     * tocaba. Con la regla ingenua —"¿son las seis de un lunes?"— esa semana se
     * habría perdido para siempre, que es exactamente lo que pasó en julio.
     */
    public function testSemanalSeRecuperaCuandoElTickSePierde(): void
    {
        $laSemanaPasada = $this->execution('2099-02-23 06:01');

        // Lunes 2 de marzo a las 06:00 era su momento; nadie la ejecutó.
        $this->assertTrue($this->schedule()->isDue(self::WEEKLY, $laSemanaPasada, $this->moment('2099-03-03 11:00')), 'El martes debe recuperarse.');
        $this->assertTrue($this->schedule()->isDue(self::WEEKLY, $laSemanaPasada, $this->moment('2099-03-05 20:00')), 'Y el jueves también.');
    }

    /**
     * Pero si ya corrió esta semana, no se repite en toda la semana.
     */
    public function testSemanalNoSeRepiteDentroDeLaMismaSemana(): void
    {
        $esteLunes = $this->execution('2099-03-02 06:03');

        $this->assertFalse($this->schedule()->isDue(self::WEEKLY, $esteLunes, $this->moment('2099-03-02 18:00')));
        $this->assertFalse($this->schedule()->isDue(self::WEEKLY, $esteLunes, $this->moment('2099-03-07 23:00')), 'El sábado sigue sin tocar.');
    }

    /**
     * El domingo, antes de que llegue el lunes, la ocurrencia vigente es la del
     * lunes ANTERIOR: si esa se cumplió, no toca.
     */
    public function testSemanalNoTocaElDiaAntesDeSuDia(): void
    {
        $esteLunes = $this->execution('2099-03-02 06:03');

        $this->assertFalse($this->schedule()->isDue(self::WEEKLY, $esteLunes, $this->moment('2099-03-08 23:59')));
        $this->assertTrue($this->schedule()->isDue(self::WEEKLY, $esteLunes, $this->moment('2099-03-09 06:00')), 'Y al llegar el lunes siguiente, sí.');
    }

    /**
     * Mensual: el día declarado del mes, con la misma regla de recuperación.
     */
    public function testMensualTocaTrasSuDiaYNoAntes(): void
    {
        $mensual = ['freq' => 'monthly', 'dom' => 1, 'hour' => 4];
        $elMesPasado = $this->execution('2099-02-01 04:00');

        $this->assertFalse($this->schedule()->isDue($mensual, $elMesPasado, $this->moment('2099-02-28 23:00')));
        $this->assertTrue($this->schedule()->isDue($mensual, $elMesPasado, $this->moment('2099-03-01 04:00')));
        $this->assertTrue($this->schedule()->isDue($mensual, $elMesPasado, $this->moment('2099-03-15 12:00')), 'A mitad de mes sigue debiéndose.');
    }

    /**
     * La cadencia por intervalo, que este proyecto no usa pero gestión de centro
     * sí: se mide desde la última ejecución, no contra el reloj de pared.
     */
    public function testIntervaloSeMideDesdeLaUltimaEjecucion(): void
    {
        $cada5 = ['freq' => 'interval', 'minutes' => 5];
        $haceCuatroMinutos = $this->execution('2099-03-04 09:56');

        $this->assertFalse($this->schedule()->isDue($cada5, $haceCuatroMinutos, $this->moment('2099-03-04 10:00')));
        $this->assertTrue($this->schedule()->isDue($cada5, $haceCuatroMinutos, $this->moment('2099-03-04 10:01')));
    }

    /**
     * Un intento fallido se reintenta en el siguiente tick: un SMTP caído cinco
     * minutos no puede dejar a nadie sin aviso hasta mañana.
     */
    public function testUnFalloSeReintentaEnElSiguienteTick(): void
    {
        $fallo = $this->execution('2099-03-04 09:00', CronRun::STATUS_FAILED);

        $this->assertTrue($this->schedule()->isDue(self::DAILY, $fallo, $this->moment('2099-03-04 09:30')));
    }

    /**
     * El reintento no tiene tope, y es deliberado: una tarea rota se reintenta
     * cada tick hasta que alguien la arregla, y en cuanto se arregla la causa se
     * recupera sola. Mientras tanto sale en rojo en la pantalla.
     *
     * (Un tope por tiempo sería inoperante: el manifiesto exige que el plazo de
     * retraso sea mayor que el período, así que cualquier ventana llegaría a la
     * ocurrencia siguiente, que reactiva la tarea igual.)
     */
    public function testElReintentoTrasFalloNoCaduca(): void
    {
        $fallo = $this->execution('2099-03-04 09:00', CronRun::STATUS_FAILED);

        $this->assertTrue($this->schedule()->isDue(self::DAILY, $fallo, $this->moment('2099-03-04 10:00')));
        $this->assertTrue($this->schedule()->isDue(self::DAILY, $fallo, $this->moment('2099-03-04 23:00')));
    }

    /**
     * Los otros dos resultados sanos NO se reintentan dentro de su ocurrencia:
     * "corrió sin encontrar trabajo" es un éxito, y "apagada por configuración"
     * es la tarea obedeciendo.
     */
    public function testNoSeReintentaLoQueNoFallo(): void
    {
        $schedule = $this->schedule();
        $ahora = $this->moment('2099-03-04 12:00');

        $this->assertFalse($schedule->isDue(self::DAILY, $this->execution('2099-03-04 09:00', CronRun::STATUS_DISABLED), $ahora));
        $this->assertFalse($schedule->isDue(self::DAILY, $this->execution('2099-03-04 09:00', CronRun::STATUS_NOTHING_TO_DO), $ahora));
        $this->assertFalse($schedule->isDue(self::DAILY, $this->execution('2099-03-04 09:00', CronRun::STATUS_DONE), $ahora));
    }

    /**
     * Las horas se entienden en la zona que declara el manifiesto, no en la del
     * servidor. Mismo instante exacto, dos manifiestos con zonas distintas, dos
     * respuestas: en la Península ya son las 09:30 y la tarea de las 09:00 toca;
     * en Canarias son las 08:30 y todavía no le ha llegado la hora.
     */
    public function testLaHoraSeEntiendeEnLaZonaDelManifiesto(): void
    {
        $instante = new \DateTimeImmutable('2099-03-04 08:30', new \DateTimeZone('UTC'));
        $anoche = $this->execution('2099-03-03 20:00');

        $this->assertTrue($this->schedule('Europe/Madrid')->isDue(self::DAILY, $anoche, $instante));
        $this->assertFalse($this->schedule('Atlantic/Canary')->isDue(self::DAILY, $anoche, $instante));
    }

    /**
     * Las cadencias se dicen en castellano, incluida la de intervalo.
     */
    public function testLasCadenciasSeDicenEnCastellano(): void
    {
        $schedule = $this->schedule();

        $this->assertSame('a diario a las 09:00', $schedule->describe(self::DAILY));
        $this->assertSame('los lunes a las 06:00', $schedule->describe(self::WEEKLY));
        $this->assertSame('el día 1 de cada mes a las 04:00', $schedule->describe(['freq' => 'monthly', 'dom' => 1, 'hour' => 4]));
        $this->assertSame('cada 5 minutos', $schedule->describe(['freq' => 'interval', 'minutes' => 5]));
        $this->assertSame('cada hora', $schedule->describe(['freq' => 'interval', 'minutes' => 60]));
        $this->assertSame('cada 2 horas', $schedule->describe(['freq' => 'interval', 'minutes' => 120]));
    }

    /**
     * Evaluador con un manifiesto que sólo aporta la zona horaria (es lo único
     * que esta pieza le pide).
     *
     * @param string $timezone Zona horaria declarada.
     */
    private function schedule(string $timezone = 'Europe/Madrid'): CronSchedule
    {
        $manifest = $this->createMock(CronManifest::class);
        $manifest->method('timezone')->willReturn($timezone);

        return new CronSchedule($manifest);
    }

    /**
     * Una ejecución registrada en un instante dado.
     *
     * (Ni `run()` ni `at()`: los dos existen ya en `TestCase` y redefinirlos
     * tumba la suite entera con un error fatal antes de ejecutar nada.)
     *
     * @param string $when   Momento "Y-m-d H:i" en hora peninsular.
     * @param string $status Estado con el que quedó registrada.
     */
    private function execution(string $when, string $status = CronRun::STATUS_DONE): CronRun
    {
        return (new CronRun())
            ->setStartedAt($this->moment($when))
            ->setStatus($status);
    }

    /**
     * Un instante en hora peninsular, que es en la que están escritos los casos.
     *
     * (No se llama `at()` porque PHPUnit ya tiene un `TestCase::at()` estático,
     * el viejo matcher de invocaciones, y redefinirlo aquí es un error fatal.)
     *
     * @param string $when Momento "Y-m-d H:i".
     */
    private function moment(string $when): \DateTimeImmutable
    {
        return new \DateTimeImmutable($when, new \DateTimeZone('Europe/Madrid'));
    }
}
