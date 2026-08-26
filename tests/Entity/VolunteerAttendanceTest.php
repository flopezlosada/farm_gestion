<?php

namespace App\Tests\Entity;

use App\Entity\Partner;
use App\Entity\VolunteerOffer;
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
        $offer = $this->offer(90);
        $signup = $this->signupOn($offer);

        $signup->confirmAttendance(VolunteerSignup::SOURCE_SELF);
        $offer->setCreditedMinutes(30);

        $this->assertSame(90, $signup->getCreditedMinutes(), 'Cambiar la oferta después no debe tocar lo ya reconocido.');
    }

    /**
     * Quien fue puede confirmarlo por su cuenta, y queda registrado que fue él o
     * ella. Es la vía normal: si el contador dependiera de que administración
     * cierre cada tarea a mano, se quedaría a cero en cuanto se olvidaran.
     */
    public function testQuienFueLoConfirmaYQuedaRegistrado(): void
    {
        $signup = $this->signupOn($this->offer(60));

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
        $signup = $this->signupOn($this->offer(60));

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
        $signup = $this->signupOn($this->offer(60));
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
        $signup = $this->signupOn($this->offer(60))->cancel();

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
        $offer = $this->offer(60);
        $this->signupOn($offer)->confirmAttendance(VolunteerSignup::SOURCE_SELF);

        $this->assertTrue($offer->isDone(new \DateTime('2099-03-16 10:00')));
        $this->assertFalse($offer->wasMissed(new \DateTime('2099-03-16 10:00')));
        $this->assertSame(1, $offer->getAttendedCount());
    }

    /**
     * Y una tarea pasada en la que todo el mundo dijo que no fue es una tarea
     * que nadie cubrió. Es el dato incómodo, y el que de verdad dice cómo va el
     * voluntariado.
     */
    public function testUnaTareaALaQueNoFueNadieSeMarcaComoTal(): void
    {
        $offer = $this->offer(60);
        $this->signupOn($offer)->markAbsent(VolunteerSignup::SOURCE_SELF);

        $this->assertTrue($offer->wasMissed(new \DateTime('2099-03-16 10:00')));
        $this->assertFalse($offer->isDone(new \DateTime('2099-03-16 10:00')));
    }

    /**
     * Mientras alguien no haya contestado, la tarea sigue pendiente: ni hecha ni
     * fallida. Es lo que la mantiene en la lista de "¿pudiste ir?" del panel y
     * en la de pendientes de gestión.
     */
    public function testMientrasFalteAlguienPorContestarLaTareaSigueAbierta(): void
    {
        $offer = $this->offer(60);
        $this->signupOn($offer)->confirmAttendance(VolunteerSignup::SOURCE_SELF);
        $this->signupOn($offer);

        $this->assertFalse($offer->isSettled());
        $this->assertFalse($offer->wasMissed(new \DateTime('2099-03-16 10:00')));
        $this->assertTrue($offer->isDone(new \DateTime('2099-03-16 10:00')), 'Ya fue alguien, así que hecha sí está.');
    }

    /**
     * Quien se dio de baja no bloquea el cierre: avisó, y no hay nada que
     * preguntarle.
     */
    public function testQuienSeDioDeBajaNoBloqueaElCierre(): void
    {
        $offer = $this->offer(60);
        $this->signupOn($offer)->confirmAttendance(VolunteerSignup::SOURCE_SELF);
        $this->signupOn($offer)->cancel();

        $this->assertTrue($offer->isSettled());
    }

    /**
     * @param int $creditedMinutes minutos que computa la tarea
     */
    private function offer(int $creditedMinutes): VolunteerOffer
    {
        return (new VolunteerOffer())
            ->setTitle('Descargar el reparto')
            ->setStartsAt(new \DateTime('2099-03-15 17:00'))
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED)
            ->setCreditedMinutes($creditedMinutes);
    }

    /**
     * Una inscripción viva, ya enganchada a la oferta.
     *
     * @param VolunteerOffer $offer la tarea
     */
    private function signupOn(VolunteerOffer $offer): VolunteerSignup
    {
        $signup = (new VolunteerSignup())->setPartner(new Partner());
        $offer->addSignup($signup);

        return $signup;
    }
}
