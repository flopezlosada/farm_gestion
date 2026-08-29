<?php

namespace App\Tests\Entity;

use App\Entity\Partner;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerSignup;
use PHPUnit\Framework\TestCase;

/**
 * En qué momento de su vida está una tarea, que es lo mismo que decir qué
 * trabajo toca hacer con ella.
 *
 * La ficha de gestión se organiza entera alrededor de esto, así que una fase mal
 * derivada no es un detalle estético: pondría el formulario de imputar horas
 * delante de una tarea que aún no ha pasado, o daría por cerrada una a la que
 * todavía le falta gente por contestar.
 *
 * Lo que más se comprueba aquí es el ORDEN de las decisiones, porque es donde
 * están los errores que no se ven: `isSettled()` es true de forma vacía cuando
 * no hay nadie apuntado, y una tarea anulada que ya pasó no está "por cerrar".
 */
class VolunteerOfferPhaseTest extends TestCase
{
    /** Un momento fijo, para que los tests no dependan de cuándo se ejecuten. */
    private const NOW = '2026-08-31 12:00';

    /**
     * Un borrador no tiene trabajo pendiente aunque su fecha ya haya pasado: no
     * lo ha visto nadie, así que no hay a quién perseguir ni horas que imputar.
     */
    public function testUnBorradorEsBorradorAunqueSuFechaHayaPasado(): void
    {
        $offer = $this->offer('2026-08-20 17:00')->setStatus(VolunteerOffer::STATUS_DRAFT);

        $this->assertSame(VolunteerOffer::PHASE_DRAFT, $this->phaseOf($offer));
    }

    /**
     * Y una anulada tampoco. Es el caso que el rediseño original se dejaba
     * fuera: contemplaba cuatro fases y ninguna servía para una tarea que se
     * canceló la semana pasada.
     */
    public function testUnaAnuladaNoEstaPorCerrar(): void
    {
        $offer = $this->offer('2026-08-20 17:00')->setStatus(VolunteerOffer::STATUS_CANCELLED);
        $this->signupOn($offer);

        $this->assertSame(VolunteerOffer::PHASE_CANCELLED, $this->phaseOf($offer));
    }

    /**
     * Publicada y aún por llegar: el trabajo es llenar plazas.
     */
    public function testUnaTareaFuturaEstaAbierta(): void
    {
        $offer = $this->offer('2026-09-07 17:00');

        $this->assertSame(VolunteerOffer::PHASE_OPEN, $this->phaseOf($offer));
    }

    /**
     * ÉSTE es el orden que importa. Una tarea de la semana que viene a la que no
     * se ha apuntado nadie tiene `isSettled() === true` —no hay a quién
     * preguntar— y si la fase mirara eso antes que la fecha saldría "cerrada"
     * una tarea que ni siquiera ha ocurrido.
     */
    public function testUnaTareaFuturaSinNadieApuntadoNoSaleCerrada(): void
    {
        $offer = $this->offer('2026-09-07 17:00');

        $this->assertTrue($offer->isSettled(), 'Sin nadie apuntado no hay nada que responder.');
        $this->assertSame(VolunteerOffer::PHASE_OPEN, $this->phaseOf($offer));
    }

    /**
     * El día de la tarea, una vez empezada, el trabajo es pasar lista.
     */
    public function testElDiaDeLaTareaTocaPasarLista(): void
    {
        $offer = $this->offer('2026-08-31 09:00');
        $this->signupOn($offer);

        $this->assertSame(VolunteerOffer::PHASE_TODAY, $this->phaseOf($offer));
    }

    /**
     * Pero sólo una vez ha empezado. A las nueve de la mañana de un reparto que
     * es a las seis de la tarde todavía se puede pedir gente, y decir "pasa
     * lista" nueve horas antes es pedir que se marque a quien aún no ha venido.
     */
    public function testAntesDeSuHoraSigueAbiertaAunqueSeaHoy(): void
    {
        $offer = $this->offer('2026-08-31 18:00');
        $this->signupOn($offer);

        $this->assertSame(VolunteerOffer::PHASE_OPEN, $this->phaseOf($offer));
    }

    /**
     * Si todo el mundo confirmó desde su panel esa misma tarde, no queda lista
     * que pasar aunque siga siendo hoy.
     */
    public function testSiYaEstaTodoRespondidoSeCierraAunqueSeaHoy(): void
    {
        $offer = $this->offer('2026-08-31 09:00');
        $this->signupOn($offer)->confirmAttendance(VolunteerSignup::SOURCE_SELF);

        $this->assertSame(VolunteerOffer::PHASE_CLOSED, $this->phaseOf($offer));
    }

    /**
     * Pasada y con gente sin contestar: hay que cerrarla. Es el estado que
     * justifica todo el formulario de imputación.
     */
    public function testPasadaConGenteSinContestarEstaPorCerrar(): void
    {
        $offer = $this->offer('2026-08-24 17:00');
        $this->signupOn($offer)->confirmAttendance(VolunteerSignup::SOURCE_SELF);
        $this->signupOn($offer);

        $this->assertSame(VolunteerOffer::PHASE_TO_CLOSE, $this->phaseOf($offer));
    }

    /**
     * Quien se dio de baja no deja la tarea colgada: avisó de que no iba, y no
     * hay nada que preguntarle.
     */
    public function testQuienSeDioDeBajaNoDejaLaTareaPorCerrar(): void
    {
        $offer = $this->offer('2026-08-24 17:00');
        $this->signupOn($offer)->confirmAttendance(VolunteerSignup::SOURCE_SELF);
        $this->signupOn($offer)->cancel();

        $this->assertSame(VolunteerOffer::PHASE_CLOSED, $this->phaseOf($offer));
    }

    /**
     * Una tarea vieja a la que nadie contestó nunca sigue "por cerrar" y no se
     * cierra sola con el tiempo. Que siga dando la lata es el punto: es trabajo
     * pendiente de verdad, y esconderlo dejaría a la gente sin sus horas.
     */
    public function testUnaTareaViejaSinCerrarSigueReclamando(): void
    {
        $offer = $this->offer('2026-01-15 17:00');
        $this->signupOn($offer);

        $this->assertSame(VolunteerOffer::PHASE_TO_CLOSE, $this->phaseOf($offer));
    }

    /**
     * El total de horas sale de lo congelado en cada inscripción, no de
     * multiplicar plazas por lo que vale la tarea: hay quien se queda media hora
     * menos y quien la organizó y computa otra cosa.
     */
    public function testElTotalSumaLoReconocidoACadaCual(): void
    {
        $offer = $this->offer('2026-08-24 17:00');
        $this->signupOn($offer)->confirmAttendance(VolunteerSignup::SOURCE_SELF, 90);
        $this->signupOn($offer)->confirmAttendance(VolunteerSignup::SOURCE_MANAGER, 30);

        $this->assertSame(120, $offer->getCreditedMinutesTotal());
    }

    /**
     * Los acompañantes NO multiplican. Las horas cuelgan de un socix con ficha, y
     * quien viene con su criatura no ha trabajado el doble — aunque sí ocupe dos
     * plazas, que es otra cosa y se cuenta aparte.
     */
    public function testLosAcompanantesNoMultiplicanLasHoras(): void
    {
        $offer = $this->offer('2026-08-24 17:00');
        $this->signupOn($offer)
            ->setCompanions(1)
            ->confirmAttendance(VolunteerSignup::SOURCE_SELF, 30);

        $this->assertSame(30, $offer->getCreditedMinutesTotal(), 'Una persona, unas horas.');
        $this->assertSame(2, $offer->getFilledSlots(), 'Pero dos plazas ocupadas.');
    }

    /**
     * Quien no fue no suma, y quien se dio de baja tampoco. Si sumaran, cerrar
     * una tarea inflaría la aportación de gente que no apareció.
     */
    public function testNoSumaQuienNoFueNiQuienSeDioDeBaja(): void
    {
        $offer = $this->offer('2026-08-24 17:00');
        $this->signupOn($offer)->confirmAttendance(VolunteerSignup::SOURCE_SELF, 60);
        $this->signupOn($offer)->markAbsent(VolunteerSignup::SOURCE_SELF);
        $this->signupOn($offer)->cancel();

        $this->assertSame(60, $offer->getCreditedMinutesTotal());
    }

    /**
     * Cuánta gente falta por contestar es el tamaño exacto del trabajo
     * pendiente, y lo que decide si merece la pena perseguirlo o cerrar a mano.
     */
    public function testCuentaCuantaGenteFaltaPorContestar(): void
    {
        $offer = $this->offer('2026-08-24 17:00');
        $this->signupOn($offer)->confirmAttendance(VolunteerSignup::SOURCE_SELF);
        $this->signupOn($offer);
        $this->signupOn($offer);
        $this->signupOn($offer)->cancel();

        $this->assertSame(2, $offer->getPendingConfirmationCount());
    }

    /**
     * @param string $startsAt cuándo empieza, en formato de \DateTime
     */
    private function offer(string $startsAt): VolunteerOffer
    {
        return (new VolunteerOffer())
            ->setTitle('Descargar el reparto')
            ->setStartsAt(new \DateTime($startsAt))
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED)
            ->setCreditedMinutes(30);
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

    /**
     * La fase en el momento fijo del test, para no depender de la hora a la que
     * se ejecute la suite.
     *
     * @param VolunteerOffer $offer la tarea
     */
    private function phaseOf(VolunteerOffer $offer): string
    {
        return $offer->getPhase(new \DateTime(self::NOW));
    }
}
