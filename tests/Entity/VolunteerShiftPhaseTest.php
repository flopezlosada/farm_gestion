<?php

namespace App\Tests\Entity;

use App\Entity\Partner;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Entity\VolunteerSignup;
use PHPUnit\Framework\TestCase;

/**
 * En qué momento de su vida está un TURNO, que es lo mismo que decir qué trabajo
 * toca hacer con él.
 *
 * La ficha de gestión se organiza entera alrededor de esto, así que una fase mal
 * derivada no es un detalle estético: pondría el formulario de imputar horas
 * delante de un turno que aún no ha pasado, o daría por cerrado uno al que
 * todavía le falta gente por contestar.
 *
 * Lo que más se comprueba aquí es el ORDEN de las decisiones, porque es donde
 * están los errores que no se ven: `isSettled()` es true de forma vacía cuando
 * no hay nadie apuntado, y un turno de una tarea anulada que ya pasó no está
 * "por cerrar".
 */
class VolunteerShiftPhaseTest extends TestCase
{
    /** Un momento fijo, para que los tests no dependan de cuándo se ejecuten. */
    private const NOW = '2026-08-31 12:00';

    /**
     * Un borrador no tiene trabajo pendiente aunque su fecha ya haya pasado: no
     * lo ha visto nadie, así que no hay a quién perseguir ni horas que imputar.
     */
    public function testUnBorradorEsBorradorAunqueSuFechaHayaPasado(): void
    {
        $shift = $this->shift('2026-08-20 17:00');
        $shift->getOffer()->setStatus(VolunteerOffer::STATUS_DRAFT);

        $this->assertSame(VolunteerShift::PHASE_DRAFT, $this->phaseOf($shift));
    }

    /**
     * Y uno de una tarea anulada tampoco. Es el caso que el rediseño original se
     * dejaba fuera: contemplaba cuatro fases y ninguna servía para algo que se
     * canceló la semana pasada.
     */
    public function testUnaTareaAnuladaNoDejaTurnosPorCerrar(): void
    {
        $shift = $this->shift('2026-08-20 17:00');
        $shift->getOffer()->setStatus(VolunteerOffer::STATUS_CANCELLED);
        $this->signupOn($shift);

        $this->assertSame(VolunteerShift::PHASE_CANCELLED, $this->phaseOf($shift));
    }

    /**
     * Anular UN turno —el festivo— basta para que no pida nada, sin tocar la
     * tarea. Es el gesto que hace posible que la receta viva en la tarea.
     */
    public function testUnTurnoAnuladoNoPideNada(): void
    {
        $shift = $this->shift('2026-09-07 17:00')->cancel('festivo');

        $this->assertSame(VolunteerShift::PHASE_CANCELLED, $this->phaseOf($shift));
        $this->assertFalse($shift->isOpen(new \DateTime(self::NOW)));
        $this->assertSame('festivo', $shift->getCancelledReason());
    }

    /**
     * Y se puede volver a poner: reactivar es un hecho con nombre, no un setter
     * a null.
     */
    public function testUnTurnoAnuladoSePuedeReactivar(): void
    {
        $shift = $this->shift('2026-09-07 17:00')->cancel('llovía');
        $shift->reopen();

        $this->assertSame(VolunteerShift::PHASE_OPEN, $this->phaseOf($shift));
        $this->assertNull($shift->getCancelledReason());
    }

    /**
     * PAUSAR SÓLO TAPA EL FUTURO. Un turno pasado sigue pidiendo que se le pase
     * lista aunque la tarea se pare después: el trabajo se hizo, y las horas de
     * quien fue no se pierden porque alguien pare la tarea en septiembre.
     */
    public function testLaPausaTapaLoFuturoYNoLoPasado(): void
    {
        $futuro = $this->shift('2026-09-07 17:00');
        $futuro->getOffer()->setStatus(VolunteerOffer::STATUS_PAUSED);

        $pasado = new VolunteerShift();
        $pasado->setStartsAt(new \DateTime('2026-08-24 17:00'));
        $futuro->getOffer()->addShift($pasado);
        $this->signupOn($pasado);

        $this->assertSame(VolunteerShift::PHASE_PAUSED, $this->phaseOf($futuro));
        $this->assertSame(VolunteerShift::PHASE_TO_CLOSE, $this->phaseOf($pasado));
    }

    /**
     * Publicado y aún por llegar: el trabajo es llenar plazas.
     */
    public function testUnTurnoFuturoEstaAbierto(): void
    {
        $this->assertSame(VolunteerShift::PHASE_OPEN, $this->phaseOf($this->shift('2026-09-07 17:00')));
    }

    /**
     * ÉSTE es el orden que importa. Un turno de la semana que viene al que no se
     * ha apuntado nadie tiene `isSettled() === true` —no hay a quién preguntar—
     * y si la fase mirara eso antes que la fecha saldría "cerrado" algo que ni
     * siquiera ha ocurrido.
     */
    public function testUnTurnoFuturoSinNadieApuntadoNoSaleCerrado(): void
    {
        $shift = $this->shift('2026-09-07 17:00');

        $this->assertTrue($shift->isSettled(), 'Sin nadie apuntado no hay nada que responder.');
        $this->assertSame(VolunteerShift::PHASE_OPEN, $this->phaseOf($shift));
    }

    /**
     * El día del turno, una vez empezado, el trabajo es pasar lista.
     */
    public function testElDiaDelTurnoTocaPasarLista(): void
    {
        $shift = $this->shift('2026-08-31 09:00');
        $this->signupOn($shift);

        $this->assertSame(VolunteerShift::PHASE_TODAY, $this->phaseOf($shift));
    }

    /**
     * Pero sólo una vez ha empezado. A las nueve de la mañana de un reparto que
     * es a las seis de la tarde todavía se puede pedir gente, y decir "pasa
     * lista" nueve horas antes es pedir que se marque a quien aún no ha venido.
     */
    public function testAntesDeSuHoraSigueAbiertoAunqueSeaHoy(): void
    {
        $shift = $this->shift('2026-08-31 18:00');
        $this->signupOn($shift);

        $this->assertSame(VolunteerShift::PHASE_OPEN, $this->phaseOf($shift));
    }

    /**
     * Si todo el mundo confirmó desde su panel esa misma tarde, no queda lista
     * que pasar aunque siga siendo hoy.
     */
    public function testSiYaEstaTodoRespondidoSeCierraAunqueSeaHoy(): void
    {
        $shift = $this->shift('2026-08-31 09:00');
        $this->signupOn($shift)->confirmAttendance(VolunteerSignup::SOURCE_SELF);

        $this->assertSame(VolunteerShift::PHASE_CLOSED, $this->phaseOf($shift));
    }

    /**
     * Pasado y con gente sin contestar: hay que cerrarlo. Es el estado que
     * justifica todo el formulario de imputación.
     */
    public function testPasadoConGenteSinContestarEstaPorCerrar(): void
    {
        $shift = $this->shift('2026-08-24 17:00');
        $this->signupOn($shift)->confirmAttendance(VolunteerSignup::SOURCE_SELF);
        $this->signupOn($shift);

        $this->assertSame(VolunteerShift::PHASE_TO_CLOSE, $this->phaseOf($shift));
    }

    /**
     * Quien se dio de baja no deja el turno colgado: avisó de que no iba, y no
     * hay nada que preguntarle.
     */
    public function testQuienSeDioDeBajaNoDejaElTurnoPorCerrar(): void
    {
        $shift = $this->shift('2026-08-24 17:00');
        $this->signupOn($shift)->confirmAttendance(VolunteerSignup::SOURCE_SELF);
        $this->signupOn($shift)->cancel();

        $this->assertSame(VolunteerShift::PHASE_CLOSED, $this->phaseOf($shift));
    }

    /**
     * Un turno viejo sin cerrar sigue "por cerrar" y no se cierra solo con el
     * tiempo. Que siga dando la lata es el punto: es trabajo pendiente de
     * verdad, y esconderlo dejaría a la gente sin sus horas.
     */
    public function testUnTurnoViejoSinCerrarSigueReclamando(): void
    {
        $shift = $this->shift('2026-01-15 17:00');
        $this->signupOn($shift);

        $this->assertSame(VolunteerShift::PHASE_TO_CLOSE, $this->phaseOf($shift));
    }

    /**
     * El total de horas sale de lo congelado en cada inscripción, no de
     * multiplicar plazas por lo que vale la tarea: hay quien se queda media hora
     * menos y quien la organizó y computa otra cosa.
     */
    public function testElTotalSumaLoReconocidoACadaCual(): void
    {
        $shift = $this->shift('2026-08-24 17:00');
        $this->signupOn($shift)->confirmAttendance(VolunteerSignup::SOURCE_SELF, 90);
        $this->signupOn($shift)->confirmAttendance(VolunteerSignup::SOURCE_MANAGER, 30);

        $this->assertSame(120, $shift->getCreditedMinutesTotal());
    }

    /**
     * Y la tarea suma lo de TODOS sus turnos: es lo que aporta ese trabajo a la
     * cuenta de horas de la asociación.
     */
    public function testLaTareaSumaLoDeTodosSusTurnos(): void
    {
        $primero = $this->shift('2026-08-17 17:00');
        $offer = $primero->getOffer();

        $segundo = (new VolunteerShift())->setStartsAt(new \DateTime('2026-08-24 17:00'));
        $offer->addShift($segundo);

        $this->signupOn($primero)->confirmAttendance(VolunteerSignup::SOURCE_SELF, 60);
        $this->signupOn($segundo)->confirmAttendance(VolunteerSignup::SOURCE_SELF, 30);

        $this->assertSame(90, $offer->getCreditedMinutesTotal());
    }

    /**
     * Los acompañantes NO multiplican. Las horas cuelgan de un socix con ficha, y
     * quien viene con su criatura no ha trabajado el doble — aunque sí ocupe dos
     * plazas, que es otra cosa y se cuenta aparte.
     */
    public function testLosAcompanantesNoMultiplicanLasHoras(): void
    {
        $shift = $this->shift('2026-08-24 17:00');
        $this->signupOn($shift)
            ->setCompanions(1)
            ->confirmAttendance(VolunteerSignup::SOURCE_SELF, 30);

        $this->assertSame(30, $shift->getCreditedMinutesTotal(), 'Una persona, unas horas.');
        $this->assertSame(2, $shift->getFilledSlots(), 'Pero dos plazas ocupadas.');
    }

    /**
     * Quien no fue no suma, y quien se dio de baja tampoco. Si sumaran, cerrar
     * un turno inflaría la aportación de gente que no apareció.
     */
    public function testNoSumaQuienNoFueNiQuienSeDioDeBaja(): void
    {
        $shift = $this->shift('2026-08-24 17:00');
        $this->signupOn($shift)->confirmAttendance(VolunteerSignup::SOURCE_SELF, 60);
        $this->signupOn($shift)->markAbsent(VolunteerSignup::SOURCE_SELF);
        $this->signupOn($shift)->cancel();

        $this->assertSame(60, $shift->getCreditedMinutesTotal());
    }

    /**
     * Cuánta gente falta por contestar es el tamaño exacto del trabajo
     * pendiente, y lo que decide si merece la pena perseguirlo o cerrar a mano.
     */
    public function testCuentaCuantaGenteFaltaPorContestar(): void
    {
        $shift = $this->shift('2026-08-24 17:00');
        $this->signupOn($shift)->confirmAttendance(VolunteerSignup::SOURCE_SELF);
        $this->signupOn($shift);
        $this->signupOn($shift);
        $this->signupOn($shift)->cancel();

        $this->assertSame(2, $shift->getPendingConfirmationCount());
    }

    /**
     * LA HERENCIA DE PLAZAS Y MINUTOS. Lo normal es que las mande la tarea; el
     * turno sólo las lleva cuando difieren. Sin esto, el reparto que pide cuatro
     * personas el sábado y dos el domingo serían dos tareas distintas.
     */
    public function testElTurnoHeredaPlazasYMinutosDeLaTarea(): void
    {
        $shift = $this->shift('2026-09-07 17:00');
        $shift->getOffer()->setSlots(3)->setCreditedMinutes(30);

        $this->assertSame(3, $shift->getSlots());
        $this->assertSame(30, $shift->getCreditedMinutes());
        $this->assertNull($shift->getOwnSlots(), 'Lo crudo sigue vacío: es lo que edita el formulario.');
    }

    /**
     * Y lo propio del turno le gana a lo de la tarea.
     */
    public function testLoPropioDelTurnoGanaALoDeLaTarea(): void
    {
        $shift = $this->shift('2026-09-07 17:00');
        $shift->getOffer()->setSlots(3)->setCreditedMinutes(30);
        $shift->setOwnSlots(6)->setOwnCreditedMinutes(90);

        $this->assertSame(6, $shift->getSlots());
        $this->assertSame(90, $shift->getCreditedMinutes());
    }

    /**
     * Las horas se congelan de lo que valía EL TURNO, resolviendo su herencia. Si
     * se leyeran de la tarea al vuelo, corregir después lo que vale ese trabajo
     * reescribiría el histórico de todo el mundo.
     */
    public function testLasHorasSeCongelanDeLoQueValiaElTurno(): void
    {
        $shift = $this->shift('2026-08-24 17:00');
        $shift->getOffer()->setCreditedMinutes(30);
        $shift->setOwnCreditedMinutes(90);

        $signup = $this->signupOn($shift);
        $signup->confirmAttendance(VolunteerSignup::SOURCE_SELF);

        $this->assertSame(90, $signup->getCreditedMinutes());

        // Y cambiar la tarea después no le toca las horas ya reconocidas.
        $shift->getOffer()->setCreditedMinutes(15);
        $this->assertSame(90, $signup->getCreditedMinutes());
    }

    /**
     * La gente de fuera ocupa plaza pero no tiene horas: son brazos sin ficha en
     * el sistema. Si no ocuparan, el turno seguiría pidiendo gente que ya está
     * cubierta.
     */
    public function testLaGenteDeFueraOcupaPlazaYNoComputa(): void
    {
        $shift = $this->shift('2026-08-24 17:00');
        $shift->getOffer()->setSlots(6);
        $shift->setGuests(3)->setGuestsNote('3 estudiantes del IES');

        $this->assertSame(3, $shift->getFilledSlots());
        $this->assertSame(3, $shift->getRemainingSlots());
        $this->assertSame(0, $shift->getCreditedMinutesTotal());
    }

    /**
     * Un turno, con su tarea publicada detrás.
     *
     * @param string $startsAt cuándo empieza, en formato de \DateTime
     */
    private function shift(string $startsAt): VolunteerShift
    {
        $offer = (new VolunteerOffer())
            ->setTitle('Descargar el reparto')
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED)
            ->setCreditedMinutes(30);

        $shift = (new VolunteerShift())->setStartsAt(new \DateTime($startsAt));
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

    /**
     * La fase en el momento fijo del test, para no depender de la hora a la que
     * se ejecute la suite.
     *
     * @param VolunteerShift $shift el turno
     */
    private function phaseOf(VolunteerShift $shift): string
    {
        return $shift->getPhase(new \DateTime(self::NOW));
    }
}
