<?php

namespace App\Tests\Service\Volunteering;

use App\Entity\Partner;
use App\Entity\VolunteerCall;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Entity\VolunteerSignup;
use App\Repository\VolunteerCallRepository;
use App\Service\AppSettings;
use App\Service\Volunteering\VolunteerCallEscalator;
use PHPUnit\Framework\TestCase;

/**
 * El escalado de avisos: la pieza que decide a cuánta gente se le pide una
 * tarea, y cuándo se amplía.
 *
 * Se cuida tanto como el planificador y por la misma razón: un error aquí no da
 * un fallo visible, da un aviso de más. Y un aviso de más no se puede retirar —
 * quien apaga las notificaciones del navegador no vuelve, porque el permiso
 * denegado ya no se puede volver a pedir. Por eso los casos que más se prueban
 * son los de NO avisar.
 */
class VolunteerCallEscalatorTest extends TestCase
{
    /**
     * Un turno publicado, futuro y con plazas libres del que aún no se ha
     * avisado a nadie: el primer paso sale ya, sin esperas.
     */
    public function testPrimerAvisoSaleAlSocixQueLoHaPedido(): void
    {
        $shift = $this->shift(categorised: true);

        $this->assertSame(
            VolunteerCall::SCOPE_MATCHING,
            $this->escalator([])->nextScope($shift, $this->moment('2099-03-01 10:00'))
        );
    }

    /**
     * Ya se avisó a quien lo había pedido y ha pasado el margen: se amplía a
     * quien no ha declarado preferencias.
     */
    public function testTrasElMargenSeAmpliaAQuienNoHaDichoNada(): void
    {
        $shift = $this->shift(openToAnyone: true);
        $escalator = $this->escalator(
            [VolunteerCall::SCOPE_MATCHING],
            $this->call('2099-03-01 10:00')
        );

        $this->assertSame(
            VolunteerCall::SCOPE_UNSPECIFIED,
            $escalator->nextScope($shift, $this->moment('2099-03-02 10:00'))
        );
    }

    /**
     * El caso que justifica el margen: sin él, los dos pasos saldrían en el
     * mismo tick del planificador y el escalado no habría escalado nada.
     */
    public function testAntesDelMargenNoSeAmplia(): void
    {
        $shift = $this->shift(openToAnyone: true);
        $escalator = $this->escalator(
            [VolunteerCall::SCOPE_MATCHING],
            $this->call('2099-03-01 10:00')
        );

        $this->assertNull($escalator->nextScope($shift, $this->moment('2099-03-01 23:59')));
    }

    /**
     * Desbrozar no es para cualquiera: una tarea sin `openToAnyone` se queda en
     * el primer paso para siempre. Ampliarla mandaría gente a algo que no puede
     * hacer.
     */
    public function testUnaTareaQueNoEsParaCualquieraNoSeAmpliaNunca(): void
    {
        $shift = $this->shift(openToAnyone: false);
        $escalator = $this->escalator(
            [VolunteerCall::SCOPE_MATCHING],
            $this->call('2099-03-01 10:00')
        );

        // Aunque haya pasado una semana entera. Y tiene que devolver null, no
        // el alcance: proponerlo para que el resolver devuelva cero
        // destinatarios registraría una llamada vacía que gasta el UNIQUE
        // (shift, scope) y mata la escalada en silencio.
        $this->assertNull($escalator->nextScope($shift, $this->moment('2099-03-08 10:00')));
    }

    /**
     * Una tarea SIN categorías y apta para cualquiera salta directamente al
     * segundo paso.
     *
     * Encalló de verdad antes de este caso: nadie puede haber marcado "avísame
     * de esto" si la tarea no tiene ningún "esto", así que el paso 1 no
     * encontraba destinatarios, no se registraba, y el paso 2 no llegaba nunca a
     * proponerse. La tarea no avisaba a nadie jamás.
     */
    public function testUnaTareaSinCategoriasSaltaAlSegundoPaso(): void
    {
        $shift = $this->shift(openToAnyone: true);

        $this->assertSame(
            VolunteerCall::SCOPE_UNSPECIFIED,
            $this->escalator([])->nextScope($shift, $this->moment('2099-03-01 10:00'))
        );
    }

    /**
     * Y si además no es para cualquiera, no hay a quién avisar por ninguna vía:
     * ni categorías que cruzar ni permiso para ampliar. Esa tarea sólo se cubre
     * a mano.
     */
    public function testUnaTareaSinCategoriasNiAperturaNoAvisaANadie(): void
    {
        $shift = $this->shift(openToAnyone: false);

        $this->assertNull($this->escalator([])->nextScope($shift, $this->moment('2099-03-01 10:00')));
    }

    /**
     * El automatismo no llega nunca a "todo el mundo": ese alcance lo lanza una
     * persona que ha decidido que la cosa es seria.
     */
    public function testElAutomatismoNoLlegaATodoElMundo(): void
    {
        $shift = $this->shift(openToAnyone: true);
        $escalator = $this->escalator(
            [VolunteerCall::SCOPE_MATCHING, VolunteerCall::SCOPE_UNSPECIFIED],
            $this->call('2099-03-01 10:00')
        );

        $this->assertNull($escalator->nextScope($shift, $this->moment('2099-03-10 10:00')));
    }

    /**
     * Si alguien ya avisó a todo el mundo a mano, no queda a quién ampliar: la
     * escalada se cierra aunque falten pasos intermedios por dar.
     */
    public function testElAvisoGeneralCierraLaEscalada(): void
    {
        $shift = $this->shift(openToAnyone: true);
        $escalator = $this->escalator(
            [VolunteerCall::SCOPE_EVERYONE],
            $this->call('2099-03-01 10:00')
        );

        $this->assertNull($escalator->nextScope($shift, $this->moment('2099-03-05 10:00')));
    }

    /**
     * Un turno ya cubierto no pide gente por muchos pasos que le queden. La
     * regla vive en la entidad; aquí se comprueba que el escalador la respeta.
     */
    public function testUnTurnoLlenoNoPideGente(): void
    {
        $shift = $this->shift(slots: 1, categorised: true);
        $shift->addSignup((new VolunteerSignup())->setPartner(new Partner()));

        $this->assertNull($this->escalator([])->nextScope($shift, $this->moment('2099-03-01 10:00')));
    }

    /**
     * Un turno que ya ha empezado tampoco: avisar de algo que está pasando no
     * trae a nadie y gasta el canal igual.
     */
    public function testUnTurnoPasadoNoPideGente(): void
    {
        $shift = $this->shift(categorised: true);

        $this->assertNull($this->escalator([])->nextScope($shift, $this->moment('2099-03-20 10:00')));
    }

    /**
     * Un borrador no avisa a nadie: si avisara, publicar dejaría de ser una
     * decisión.
     */
    public function testUnBorradorNoPideGente(): void
    {
        $shift = $this->shift(categorised: true);
        $shift->getOffer()->setStatus(VolunteerOffer::STATUS_DRAFT);

        $this->assertNull($this->escalator([])->nextScope($shift, $this->moment('2099-03-01 10:00')));
    }

    /**
     * El aviso general se ofrece cuando el automatismo ya ha hecho lo suyo, el
     * turno sigue sin cubrirse y la fecha está encima.
     */
    public function testElAvisoGeneralSeOfreceCuandoYaNoQuedaOtra(): void
    {
        $shift = $this->shift(openToAnyone: true, categorised: true);
        $escalator = $this->escalator(
            [VolunteerCall::SCOPE_MATCHING, VolunteerCall::SCOPE_UNSPECIFIED],
            $this->call('2099-03-13 10:00')
        );

        $this->assertTrue($escalator->shouldOfferEveryone($shift, $this->moment('2099-03-14 10:00')));
    }

    /**
     * A dos semanas del turno no se ofrece: todavía se cubre solo, y ofrecerlo
     * convierte en rutina el gesto que más caro se paga.
     */
    public function testElAvisoGeneralNoSeOfreceSiFaltaMucho(): void
    {
        $shift = $this->shift(openToAnyone: true, categorised: true);
        $escalator = $this->escalator(
            [VolunteerCall::SCOPE_MATCHING, VolunteerCall::SCOPE_UNSPECIFIED],
            $this->call('2099-02-28 10:00')
        );

        $this->assertFalse($escalator->shouldOfferEveryone($shift, $this->moment('2099-03-01 10:00')));
    }

    /**
     * Y no se ofrece mientras quede un paso automático por dar, aunque la fecha
     * apriete: para eso está el escalado.
     */
    public function testElAvisoGeneralNoSeOfreceSiQuedaUnPasoAutomatico(): void
    {
        $shift = $this->shift(openToAnyone: true, categorised: true);
        $escalator = $this->escalator([VolunteerCall::SCOPE_MATCHING]);

        $this->assertFalse($escalator->shouldOfferEveryone($shift, $this->moment('2099-03-14 10:00')));
    }

    /**
     * EL CASO QUE JUSTIFICA MIRAR EL RELOJ ADELANTADO. En pleno margen de espera,
     * «qué toca ahora» es null porque el siguiente paso aún no puede salir — pero
     * va a salir. Preguntándolo con el reloj de verdad, la pantalla concluiría
     * que ya no queda automatismo y ofrecería el aviso general un día antes de
     * tiempo, gastando el canal de 246 personas por adelantado.
     */
    public function testEnPlenoMargenDeEsperaTodaviaNoSeOfrece(): void
    {
        $shift = $this->shift(openToAnyone: true, categorised: true);
        $escalator = $this->escalator(
            [VolunteerCall::SCOPE_MATCHING],
            $this->call('2099-03-14 09:00')
        );

        $this->assertFalse($escalator->shouldOfferEveryone($shift, $this->moment('2099-03-14 10:00')));
    }

    /**
     * SI LA ESPERA CAE DESPUÉS DEL TURNO, EL PASO SIGUIENTE YA NO EXISTE. Es el
     * caso normal de una tarea urgente: primer aviso al publicar, con menos de
     * 24 horas de margen, así que el segundo tocaría cuando el turno ya ha
     * empezado y no llega a tiempo.
     *
     * Aquí `nextScope()` con el reloj adelantado devuelve null no porque se hayan
     * dado todos los pasos, sino porque en ese momento el turno ya no está
     * abierto — y por eso el aviso general SÍ se ofrece: no queda automatismo del
     * que esperar nada. La pantalla tiene que contar eso mismo y no «sale después
     * del anterior», que era lo que decía.
     */
    public function testSiLaEsperaCaeDespuesDelTurnoSeOfreceElGeneral(): void
    {
        // Turno el 15 a las 17:00; primer aviso el 14 a las 20:00, así que la
        // ampliación tocaría el 15 a las 20:00: tres horas tarde.
        $shift = $this->shift(openToAnyone: true, categorised: true);
        $escalator = $this->escalator(
            [VolunteerCall::SCOPE_MATCHING],
            $this->call('2099-03-14 20:00')
        );

        $now = $this->moment('2099-03-14 21:00');

        $this->assertNull(
            $escalator->nextScope($shift, $this->moment('2099-03-15 20:00')),
            'A esa hora el turno ya ha empezado: no hay paso siguiente que dar.'
        );
        $this->assertTrue(
            $escalator->shouldOfferEveryone($shift, $now),
            'Sin automatismo que espere y con el turno encima, el aviso a mano es la única salida.'
        );
    }

    /**
     * Una tarea que no es para cualquiera sólo tiene un paso automático: dado
     * ése, ya no queda nada que ampliar solo, así que el aviso general es la
     * única salida que le queda a quien coordina.
     */
    public function testUnaTareaCerradaOfreceElGeneralTrasSuUnicoPaso(): void
    {
        $shift = $this->shift(openToAnyone: false, categorised: true);
        $escalator = $this->escalator(
            [VolunteerCall::SCOPE_MATCHING],
            $this->call('2099-03-13 10:00')
        );

        $this->assertTrue($escalator->shouldOfferEveryone($shift, $this->moment('2099-03-14 10:00')));
    }

    /**
     * Sólo se puede una vez por turno: mandado, el botón desaparece.
     */
    public function testElAvisoGeneralNoSeOfreceDosVeces(): void
    {
        $shift = $this->shift(openToAnyone: true, categorised: true);
        $escalator = $this->escalator(
            [VolunteerCall::SCOPE_MATCHING, VolunteerCall::SCOPE_UNSPECIFIED, VolunteerCall::SCOPE_EVERYONE],
            $this->call('2099-03-14 09:00')
        );

        $this->assertFalse($escalator->shouldOfferEveryone($shift, $this->moment('2099-03-14 10:00')));
    }

    /**
     * Un turno lleno no pide gente por ninguna vía, tampoco la de a mano.
     */
    public function testUnTurnoLlenoNoOfreceElAvisoGeneral(): void
    {
        $shift = $this->shift(slots: 1, categorised: true);
        $shift->addSignup((new VolunteerSignup())->setPartner(new Partner()));
        $escalator = $this->escalator(
            [VolunteerCall::SCOPE_MATCHING, VolunteerCall::SCOPE_UNSPECIFIED],
            $this->call('2099-03-13 10:00')
        );

        $this->assertFalse($escalator->shouldOfferEveryone($shift, $this->moment('2099-03-14 10:00')));
    }

    /**
     * Ni uno que ya empezó: pedir gente para algo que está pasando no trae a
     * nadie y gasta el canal igual.
     */
    public function testUnTurnoPasadoNoOfreceElAvisoGeneral(): void
    {
        $shift = $this->shift(openToAnyone: true, categorised: true);
        $escalator = $this->escalator(
            [VolunteerCall::SCOPE_MATCHING, VolunteerCall::SCOPE_UNSPECIFIED],
            $this->call('2099-03-14 10:00')
        );

        $this->assertFalse($escalator->shouldOfferEveryone($shift, $this->moment('2099-03-20 10:00')));
    }

    /**
     * Un turno publicado, futuro, con plazas y sin acompañantes.
     *
     * SIN categorías por defecto, a propósito: es el caso que encalla si el
     * escalador no lo contempla, así que conviene que sea el que hay que pedir
     * explícitamente para NO tenerlo.
     *
     * @param bool     $openToAnyone si se puede ampliar el aviso a quien no ha dicho nada
     * @param int|null $slots        plazas; null para sin tope
     * @param bool     $categorised  si lleva alguna categoría marcada
     */
    private function shift(bool $openToAnyone = true, ?int $slots = null, bool $categorised = false): VolunteerShift
    {
        $offer = (new VolunteerOffer())
            ->setTitle('Descargar el reparto en La Cabrera')
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED)
            ->setOpenToAnyone($openToAnyone)
            ->setSlots($slots);

        if ($categorised) {
            $offer->addCategory((new VolunteerCategory())->setName('Reparto'));
        }

        $shift = (new VolunteerShift())->setStartsAt(new \DateTime('2099-03-15 17:00'));
        $offer->addShift($shift);

        return $shift;
    }

    /**
     * @param list<string>       $sent los alcances ya enviados
     * @param VolunteerCall|null $last la última llamada enviada
     */
    private function escalator(array $sent, ?VolunteerCall $last = null): VolunteerCallEscalator
    {
        $calls = $this->createMock(VolunteerCallRepository::class);
        $calls->method('sentScopes')->willReturn($sent);
        $calls->method('findLast')->willReturn($last);

        $settings = $this->createMock(AppSettings::class);
        $settings->method('getInt')
            ->with(AppSettings::VOLUNTEERING_ESCALATION_HOURS)
            ->willReturn(24);

        return new VolunteerCallEscalator($calls, $settings);
    }

    /**
     * @param string $at momento del envío, en formato legible
     */
    private function call(string $at): VolunteerCall
    {
        $call = new VolunteerCall();
        $reflection = new \ReflectionProperty(VolunteerCall::class, 'sentAt');
        $reflection->setAccessible(true);
        $reflection->setValue($call, new \DateTime($at));

        return $call;
    }

    /**
     * @param string $at el momento, en formato legible
     */
    private function moment(string $at): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at);
    }
}
