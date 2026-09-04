<?php

namespace App\Tests\Entity;

use App\Entity\Partner;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Entity\VolunteerSignup;
use PHPUnit\Framework\TestCase;

/**
 * Cómo se confirma que una tarea se hizo y cuándo empiezan a contar las horas.
 *
 * La regla de fondo: las horas sólo existen cuando alguien ha dicho que fue. Un
 * "no lo sabemos" no computa, y por eso olvidarse de cerrar una tarea no infla
 * el contador de nadie — pero tampoco lo rellena solo, que es la razón de que
 * pueda confirmarlo quien fue y no sólo administración.
 */
class VolunteerAttendanceTest extends TestCase
{
    /**
     * Confirmar asistencia congela lo que valía la tarea EN ESE MOMENTO. Si se
     * leyera siempre de la oferta, cambiar más tarde lo que vale ese trabajo
     * reescribiría el histórico de todo el mundo.
     */
    public function testConfirmarCongelaLosMinutosDeLaOferta(): void
    {
        $shift = $this->shift(90);
        $signup = $this->signupOn($shift);

        $signup->confirmAttendance(VolunteerSignup::SOURCE_SELF);
        $shift->getOffer()->setCreditedMinutes(30);

        $this->assertSame(90, $signup->getCreditedMinutes(), 'Cambiar la tarea después no debe tocar lo ya reconocido.');
    }

    /**
     * Quien fue puede confirmarlo por su cuenta, y queda registrado que fue él o
     * ella. Es la vía normal: si el contador dependiera de que administración
     * cierre cada tarea a mano, se quedaría a cero en cuanto se olvidaran.
     */
    public function testQuienFueLoConfirmaYQuedaRegistrado(): void
    {
        $signup = $this->signupOn($this->shift(60));

        $signup->confirmAttendance(VolunteerSignup::SOURCE_SELF);

        $this->assertTrue($signup->getAttended());
        $this->assertTrue($signup->isSelfConfirmed());
        $this->assertTrue($signup->isSettled());
    }

    /**
     * Y cuando lo cierra gestión, se distingue. Sirve para ver de un vistazo qué
     * tareas se cerraron solas y cuáles hubo que perseguir.
     */
    public function testLoQueCierraGestionSeDistingue(): void
    {
        $signup = $this->signupOn($this->shift(60));

        $signup->confirmAttendance(VolunteerSignup::SOURCE_MANAGER);

        $this->assertTrue($signup->getAttended());
        $this->assertFalse($signup->isSelfConfirmed());
    }

    /**
     * Corregir un "sí fue" puesto por error tiene que quitar también las horas.
     * Si no, el contador se queda inflado sin que se vea por ninguna parte.
     */
    public function testMarcarAusenciaBorraLasHorasYaReconocidas(): void
    {
        $signup = $this->signupOn($this->shift(60));
        $signup->confirmAttendance(VolunteerSignup::SOURCE_SELF);

        $signup->markAbsent(VolunteerSignup::SOURCE_MANAGER);

        $this->assertFalse($signup->getAttended());
        $this->assertNull($signup->getCreditedMinutes());
    }

    /**
     * A quien avisó de que no iba no se le computan horas. Que sea una excepción
     * y no un `if` en el sitio que llame evita que alguien se lo salte.
     */
    public function testNoSePuedenComputarHorasDeQuienSeDioDeBaja(): void
    {
        $signup = $this->signupOn($this->shift(60))->cancel();

        $this->expectException(\LogicException::class);

        $signup->confirmAttendance(VolunteerSignup::SOURCE_SELF);
    }

    /**
     * Una tarea pasada con alguien que confirmó está hecha. Es lo que se pinta
     * como "tarea hecha", y se DERIVA en vez de guardarse: un campo aparte
     * habría que mantenerlo en sincronía y mentiría en cuanto se desincronizara.
     */
    public function testUnaTareaConAlguienQueConfirmoEstaHecha(): void
    {
        $shift = $this->shift(60);
        $this->signupOn($shift)->confirmAttendance(VolunteerSignup::SOURCE_SELF);

        $this->assertTrue($shift->isDone(new \DateTime('2099-03-16 10:00')));
        $this->assertFalse($shift->wasMissed(new \DateTime('2099-03-16 10:00')));
        $this->assertSame(1, $shift->getAttendedCount());
    }

    /**
     * Y una tarea pasada en la que todo el mundo dijo que no fue es una tarea
     * que nadie cubrió. Es el dato incómodo, y el que de verdad dice cómo va el
     * voluntariado.
     */
    public function testUnaTareaALaQueNoFueNadieSeMarcaComoTal(): void
    {
        $shift = $this->shift(60);
        $this->signupOn($shift)->markAbsent(VolunteerSignup::SOURCE_SELF);

        $this->assertTrue($shift->wasMissed(new \DateTime('2099-03-16 10:00')));
        $this->assertFalse($shift->isDone(new \DateTime('2099-03-16 10:00')));
    }

    /**
     * Mientras alguien no haya contestado, la tarea sigue pendiente: ni hecha ni
     * fallida. Es lo que la mantiene en la lista de "¿pudiste ir?" del panel y
     * en la de pendientes de gestión.
     */
    public function testMientrasFalteAlguienPorContestarLaTareaSigueAbierta(): void
    {
        $shift = $this->shift(60);
        $this->signupOn($shift)->confirmAttendance(VolunteerSignup::SOURCE_SELF);
        $this->signupOn($shift);

        $this->assertFalse($shift->isSettled());
        $this->assertFalse($shift->wasMissed(new \DateTime('2099-03-16 10:00')));
        $this->assertTrue($shift->isDone(new \DateTime('2099-03-16 10:00')), 'Ya fue alguien, así que hecha sí está.');
    }

    /**
     * Quien se dio de baja no bloquea el cierre: avisó, y no hay nada que
     * preguntarle.
     */
    public function testQuienSeDioDeBajaNoBloqueaElCierre(): void
    {
        $shift = $this->shift(60);
        $this->signupOn($shift)->confirmAttendance(VolunteerSignup::SOURCE_SELF);
        $this->signupOn($shift)->cancel();

        $this->assertTrue($shift->isSettled());
    }

    /**
     * Coordinar computa horas como cualquier otra aportación. Sin esto, quien
     * monta el reparto todos los viernes —que no se apunta a las tareas, las
     * organiza— salía con el contador a cero.
     */
    public function testCoordinarComputaHoras(): void
    {
        $shift = $this->shift(60);
        $signup = $this->signupOn($shift)->setRole(VolunteerSignup::ROLE_COORDINATOR);

        $signup->confirmAttendance(VolunteerSignup::SOURCE_MANAGER, 45);

        $this->assertSame(45, $signup->getCreditedMinutes());
        $this->assertTrue($signup->isCoordination());
    }

    /**
     * Pero NO ocupa plaza: organizar el reparto no es estar allí descargando
     * cajas, y contarlo como brazo daría por llena una tarea de dos plazas con
     * una sola persona trabajando.
     */
    public function testCoordinarNoOcupaPlaza(): void
    {
        $shift = $this->shift(60);
        $shift->getOffer()->setSlots(2);
        $this->signupOn($shift)->setRole(VolunteerSignup::ROLE_COORDINATOR);

        $this->assertSame(0, $shift->getFilledSlots());
        $this->assertSame(2, $shift->getRemainingSlots());
    }

    /**
     * Y una tarea en la que sólo consta quien la organizó NO está hecha: nadie
     * fue a hacerla. Darla por hecha escondería justo el dato que hay que ver.
     */
    public function testUnaTareaConSoloCoordinacionNoEstaHecha(): void
    {
        $shift = $this->shift(60);
        $this->signupOn($shift)
            ->setRole(VolunteerSignup::ROLE_COORDINATOR)
            ->confirmAttendance(VolunteerSignup::SOURCE_MANAGER);

        $this->assertFalse($shift->isDone(new \DateTime('2099-03-16 10:00')));
        $this->assertSame(0, $shift->getAttendedCount());
    }

    /**
     * Reapuntarse tras una baja reabre la inscripción. Método propio y no un
     * setter a null: el `setCancelledAt()` que no existía reventaba este camino
     * y el de anotar a alguien a mano.
     */
    public function testReabrirUnaInscripcionCancelada(): void
    {
        $signup = $this->signupOn($this->shift(60))->cancel();

        $signup->reopen();

        $this->assertFalse($signup->isCancelled());
    }

    /**
     * Un turno con su tarea detrás. Se devuelve el TURNO porque es de quien
     * cuelgan las inscripciones y quien resuelve lo que computa cada trabajo.
     *
     * @param int $creditedMinutes minutos que computa la tarea
     */
    private function shift(int $creditedMinutes): VolunteerShift
    {
        $offer = (new VolunteerOffer())
            ->setTitle('Descargar el reparto')
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED)
            ->setCreditedMinutes($creditedMinutes);

        $shift = (new VolunteerShift())->setStartsAt(new \DateTime('2099-03-15 17:00'));
        $offer->addShift($shift);

        return $shift;
    }

    /**
     * Una inscripción viva, ya enganchada al turno.
     *
     * @param VolunteerShift $shift el turno
     */
    private function signupOn(VolunteerShift $shift): VolunteerSignup
    {
        $signup = (new VolunteerSignup())->setPartner(new Partner());
        $shift->addSignup($signup);

        return $signup;
    }
}
