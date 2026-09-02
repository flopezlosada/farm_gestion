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
