<?php

namespace App\Tests\Service\Volunteering;

use App\Service\Volunteering\VolunteerContribution;
use PHPUnit\Framework\TestCase;

/**
 * A quién se le enseña la referencia del colectivo.
 *
 * Es una condición de tres términos que cabe en una línea, y por eso hay que
 * defenderla: no protege un cálculo, protege una decisión de trato. Enseñarle la
 * mediana a quien va sobrado le enseña que hace el doble de lo normal y lo
 * invita a relajarse —el efecto bumerán de Schultz y Cialdini—, y quien la
 * "simplifique" un martes no va a ver ningún test rojo si no está éste.
 *
 * Sin BBDD: la regla es aritmética pura y merece un test que corra en
 * milisegundos, no uno que necesite levantar el kernel.
 */
class VolunteerContributionTest extends TestCase
{
    /**
     * El caso que justifica la regla entera: a quien ya hace más que la mayoría
     * no se le da una referencia por debajo de la que está.
     */
    public function testAQuienVaSobradoNoSeLeEnsenaLaMediana(): void
    {
        $sobrada = new VolunteerContribution(minutes: 720, medianMinutes: 360);

        $this->assertFalse($sobrada->showMedian());
        $this->assertTrue($sobrada->hasStarted());
    }

    /**
     * Ir justo en la mediana tampoco la enseña: "vas exactamente en la media" no
     * moviliza a nadie y sí invita a quedarse ahí.
     */
    public function testIrJustoEnLaMedianaTampocoLaEnsena(): void
    {
        $this->assertFalse((new VolunteerContribution(360, 360))->showMedian());
    }

    /**
     * Quien va por debajo es el único a quien la referencia le sirve: le dice
     * cuánto es "lo normal" cuando todavía puede alcanzarlo.
     */
    public function testAQuienVaPorDebajoSiSeLeEnsena(): void
    {
        $this->assertTrue((new VolunteerContribution(120, 360))->showMedian());
    }

    /**
     * Sin nadie que haya participado no hay mediana que enseñar, y un "lo normal
     * son 0 h" sería a la vez cierto y desastroso.
     */
    public function testSinMedianaNoHayReferencia(): void
    {
        $this->assertFalse((new VolunteerContribution(0, 0))->showMedian());
        $this->assertFalse((new VolunteerContribution(120, 0))->showMedian());
    }

    /**
     * Estar a cero NO apaga la referencia por sí solo: la pantalla de
     * voluntariado se la enseña —allí el bloque va al final y se lee como un
     * dato—, y es la home la que decide callársela con hasStarted(). Meter esa
     * decisión aquí dentro cambiaría la otra pantalla en silencio.
     */
    public function testEstarACeroLoDecideCadaPantalla(): void
    {
        $sinEmpezar = new VolunteerContribution(minutes: 0, medianMinutes: 360);

        $this->assertTrue($sinEmpezar->showMedian(), 'La regla de la mediana no mira si ha empezado.');
        $this->assertFalse($sinEmpezar->hasStarted(), 'Y la home distingue ese caso con esto.');
    }

    /**
     * El caso que hace falta proteger: quien lleva media temporada a cero SÍ ve
     * su cero. Callárselo le confirma que no hace falta que haga nada.
     */
    public function testAQuienLlevaTiempoACeroSiSeLeDice(): void
    {
        $veterana = new VolunteerContribution(minutes: 0, medianMinutes: 360, isNewcomer: false);

        $this->assertFalse($veterana->hasHadNoChance());
        $this->assertTrue($veterana->showMedian(), 'Y con la referencia al lado, que es lo que le dice cuánto es lo normal.');
    }

    /**
     * Y el que lo limita: a quien acaba de entrar, bienvenida. Su cero no habla
     * de esa persona, habla de que aún no ha pasado nada.
     */
    public function testAQuienAcabaDeLlegarNoSeLeEnsenaElCero(): void
    {
        $recienLlegada = new VolunteerContribution(minutes: 0, medianMinutes: 360, isNewcomer: true);

        $this->assertTrue($recienLlegada->hasHadNoChance());
    }

    /**
     * Acabar de llegar deja de importar en cuanto ha hecho algo: entonces hay un
     * dato suyo que enseñar, y lo que toca es enseñarlo.
     */
    public function testAcabarDeLlegarNoTapaLoQueYaHaHecho(): void
    {
        $estrenada = new VolunteerContribution(minutes: 120, medianMinutes: 360, isNewcomer: true);

        $this->assertFalse($estrenada->hasHadNoChance());
        $this->assertTrue($estrenada->showMedian());
    }

    /**
     * El defecto del constructor es el prudente. Quien añada una tercera
     * pantalla y no piense en esto no va a reñir a nadie por descuido.
     */
    public function testPorDefectoNoSeRineACualquiera(): void
    {
        $this->assertTrue((new VolunteerContribution(0, 360))->hasHadNoChance());
    }
}
