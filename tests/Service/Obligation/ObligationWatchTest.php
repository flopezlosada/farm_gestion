<?php

namespace App\Tests\Service\Obligation;

use App\Repository\ObligationRepository;
use App\Service\Obligation\ObligationWatch;
use PHPUnit\Framework\TestCase;

/**
 * El criterio de urgencia, que comparten la pantalla y la tarea que avisa.
 *
 * Se prueba con el repositorio mockeado porque lo que se juzga aquí es la
 * decisión —qué escalón toca— y no la consulta.
 */
class ObligationWatchTest extends TestCase
{
    private ObligationWatch $watch;

    protected function setUp(): void
    {
        $this->watch = new ObligationWatch($this->createMock(ObligationRepository::class));
    }

    /**
     * Lo que aún no ha entrado en el radar no genera escalón.
     */
    public function testLoQueFaltaMasDelEscalonMasLejanoNoAvisa(): void
    {
        $this->assertNull($this->watch->thresholdFor(120));
        $this->assertNull($this->watch->thresholdFor(91));
    }

    /**
     * Se avisa del escalón MÁS URGENTE ya alcanzado, no de todos los cruzados:
     * algo que vence en 45 días ha pasado el de 90 y el de 60, y lo que toca
     * decir es 60. Si se devolvieran los dos, saldrían dos correos del mismo
     * vencimiento el mismo día.
     */
    public function testSeDevuelveElEscalonMasUrgenteAlcanzado(): void
    {
        $this->assertSame(90, $this->watch->thresholdFor(90));
        $this->assertSame(60, $this->watch->thresholdFor(45));
        $this->assertSame(30, $this->watch->thresholdFor(30));
        $this->assertSame(7, $this->watch->thresholdFor(3));
    }

    /**
     * El día del vencimiento y lo ya caducado caen en el escalón 0: hay un
     * aviso, y uno solo. A partir de ahí el sitio donde se ve es la pantalla,
     * no un correo diario que nadie leería.
     */
    public function testLoCaducadoCaeEnElEscalonCero(): void
    {
        $this->assertSame(0, $this->watch->thresholdFor(0));
        $this->assertSame(0, $this->watch->thresholdFor(-1));
        $this->assertSame(0, $this->watch->thresholdFor(-400));
    }

    /**
     * La antelación que exige el documento entra como un escalón más, y eso es
     * lo que salva a los contratos de tierra: los dos arrendamientos rústicos
     * obligan a comunicar la no renovación con un año, así que con sólo los
     * escalones generales el aviso llegaría cuando ya no se puede comunicar.
     */
    public function testLaAntelacionDelDocumentoAnadeUnEscalonPropio(): void
    {
        // A 200 días no hay escalón general que valga (el mayor son 90), pero
        // con un año de preaviso ya hay que ponerse.
        $this->assertNull($this->watch->thresholdFor(200));
        $this->assertSame(365, $this->watch->thresholdFor(200, 365));
    }

    /**
     * Y NO sustituye a los generales: pasado el escalón propio, los de 90, 60,
     * 30 y 7 siguen saliendo. El primero avisa de que hay que decidir; los
     * otros, de que hay que ejecutar lo decidido.
     */
    public function testLaAntelacionDelDocumentoNoAnulaLosEscalonesGenerales(): void
    {
        $this->assertSame(90, $this->watch->thresholdFor(85, 365));
        $this->assertSame(30, $this->watch->thresholdFor(20, 365));
        $this->assertSame(0, $this->watch->thresholdFor(-3, 365));
    }

    /**
     * Una antelación más corta que un escalón general no quita nada: a 60 días
     * de algo con un mes de preaviso, el que manda sigue siendo el de 60.
     */
    public function testUnaAntelacionCortaNoDesplazaALosGenerales(): void
    {
        $this->assertSame(60, $this->watch->thresholdFor(45, 30));
        $this->assertSame(30, $this->watch->thresholdFor(28, 30));
    }

    /**
     * El texto del asunto: es lo único que mucha gente va a leer.
     */
    public function testLaFraseDeUrgenciaSeLeeComoLaDiriaUnaPersona(): void
    {
        $this->assertSame('caduca en 45 días', $this->watch->urgencyLabel(45));
        $this->assertSame('caduca mañana', $this->watch->urgencyLabel(1));
        $this->assertSame('caduca hoy', $this->watch->urgencyLabel(0));
        $this->assertSame('caducó ayer', $this->watch->urgencyLabel(-1));
        $this->assertSame('caducó hace 30 días', $this->watch->urgencyLabel(-30));
    }
}
