<?php

namespace App\Tests\Service\Volunteering;

use App\Entity\Partner;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Entity\VolunteerSignup;
use App\Service\Volunteering\ShiftCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Las reglas de pintado del calendario de turnos.
 *
 * Son las que deciden qué se ve y qué se calla: cuándo una falta de gente es un
 * aviso y cuándo un dato, qué turnos se pueden juntar en una línea sin esconder
 * trabajo, y en qué orden se lee un día. Un fallo aquí no rompe nada, sólo hace
 * que el calendario mienta en silencio, que es peor.
 */
class ShiftCalendarTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-04 12:00:00');
    }

    /**
     * Le faltan plazas y no es rutina: aviso. Y a menos de tres días, urgente.
     */
    public function testLaFaltaDeGenteEsAvisoSalvoEnLasRutinas(): void
    {
        $faena = $this->shift('2026-09-05 18:00', slots: 3, signups: 1);
        $rutina = $this->shift('2026-09-05 09:00', slots: 1, signups: 0, routine: true);

        $f = ShiftCalendar::describe($faena, $this->now);
        $r = ShiftCalendar::describe($rutina, $this->now);

        $this->assertSame(ShiftCalendar::STATE_UPCOMING, $f['state']);
        $this->assertSame(2, $f['missing']);
        $this->assertTrue($f['alarm']);
        $this->assertTrue($f['urgent'], 'Mañana y le falta gente: urgente.');

        $this->assertSame(1, $r['missing'], 'La cifra se conserva…');
        $this->assertFalse($r['alarm'], '…pero no es aviso.');
        $this->assertFalse($r['asks']);
    }

    /**
     * Con las plazas cubiertas es «cubierto», y ya no pide nada. Un turno pasado
     * con alguien sin responder es «sin cerrar» y pide pasar lista.
     */
    public function testCubiertoYSinCerrar(): void
    {
        $lleno = $this->shift('2026-09-20 18:00', slots: 2, signups: 2);
        $pasado = $this->shift('2026-09-01 18:00', slots: 2, signups: 1);

        $this->assertSame(ShiftCalendar::STATE_FULL, ShiftCalendar::stateOf($lleno, $this->now));
        $this->assertFalse(ShiftCalendar::describe($lleno, $this->now)['asks']);

        $p = ShiftCalendar::describe($pasado, $this->now);
        $this->assertSame(ShiftCalendar::STATE_TO_CLOSE, $p['state']);
        $this->assertTrue($p['asks']);
        $this->assertSame(0, $p['missing'], 'A un turno pasado ya no le falta nadie.');
    }

    /**
     * Dos turnos de la misma tarea, mismo día, mismo estado y sin pedir nada:
     * una sola línea con las plazas sumadas.
     */
    public function testLosRepetidosQueNoPidenNadaSeJuntan(): void
    {
        $offer = $this->offer(slots: 1, routine: true);
        $m = $this->shiftOf($offer, '2026-09-12 09:00', signups: 0);
        $t = $this->shiftOf($offer, '2026-09-12 20:00', signups: 0);

        $days = ShiftCalendar::days([$t, $m], $this->now);

        $this->assertCount(1, $days['2026-09-12']);
        $group = $days['2026-09-12'][0];
        $this->assertSame('group', $group['kind']);
        $this->assertSame(2, $group['missing']);
        $this->assertTrue($group['routine']);
        $this->assertSame('09:00', $group['shifts'][0]['shift']->getStartsAt()->format('H:i'), 'Dentro del grupo, por hora.');
    }

    /**
     * Si uno de los repetidos pide algo, ése sale entero y sólo se comprime el
     * resto; y lo que pide algo va delante aunque sea más tarde.
     */
    public function testLoQuePideAlgoSaleEnteroYPrimero(): void
    {
        $offer = $this->offer(slots: 2);
        $cubierto1 = $this->shiftOf($offer, '2026-09-12 09:00', signups: 2);
        $cubierto2 = $this->shiftOf($offer, '2026-09-12 11:00', signups: 2);
        $conHueco = $this->shiftOf($offer, '2026-09-12 20:00', signups: 1);
        $otra = $this->shift('2026-09-12 08:00', slots: null, signups: 0);

        $items = ShiftCalendar::days([$cubierto1, $conHueco, $otra, $cubierto2], $this->now)['2026-09-12'];

        $this->assertSame('shift', $items[0]['kind']);
        $this->assertSame($conHueco, $items[0]['shift'], 'El que pide gente, primero, aunque sea a las 20:00.');
        $this->assertSame('shift', $items[1]['kind']);
        $this->assertSame($otra, $items[1]['shift'], 'Luego por hora: la otra tarea de las 08:00.');
        $this->assertSame('group', $items[2]['kind']);
        $this->assertCount(2, $items[2]['shifts'], 'Y los dos cubiertos, juntos.');
    }

    /**
     * La barra de atención: primero lo que hay que cerrar, luego lo urgente,
     * topado a tres, y dice cuántos quedan fuera.
     */
    public function testLaBarraDeAtencionTopaYOrdena(): void
    {
        $sinCerrar = $this->shift('2026-09-02 18:00', slots: 2, signups: 1);
        $urg1 = $this->shift('2026-09-05 10:00', slots: 2, signups: 0);
        $urg2 = $this->shift('2026-09-06 10:00', slots: 2, signups: 0);
        $urg3 = $this->shift('2026-09-07 10:00', slots: 2, signups: 0);
        $lejos = $this->shift('2026-09-25 10:00', slots: 2, signups: 0);

        $bar = ShiftCalendar::attention([$lejos, $urg3, $urg1, $sinCerrar, $urg2], $this->now);

        $this->assertCount(3, $bar['items']);
        $this->assertSame($sinCerrar, $bar['items'][0]['shift']);
        $this->assertSame($urg1, $bar['items'][1]['shift']);
        $this->assertSame(1, $bar['rest']);
    }

    /**
     * La leyenda sólo lista los estados que hay en pantalla.
     */
    public function testLaLeyendaSoloConLoPresente(): void
    {
        $days = ShiftCalendar::days([
            $this->shift('2026-09-20 18:00', slots: 2, signups: 2),
            $this->shift('2026-09-21 18:00', slots: 2, signups: 0),
        ], $this->now);

        $this->assertEqualsCanonicalizing(
            [ShiftCalendar::STATE_FULL, ShiftCalendar::STATE_UPCOMING],
            ShiftCalendar::statesPresent($days)
        );
    }

    private function offer(?int $slots, bool $routine = false): VolunteerOffer
    {
        return (new VolunteerOffer())
            ->setTitle('Tarea')
            ->setSlots($slots)
            ->setRoutine($routine)
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED);
    }

    private function shift(string $startsAt, ?int $slots, int $signups, bool $routine = false): VolunteerShift
    {
        return $this->shiftOf($this->offer($slots, $routine), $startsAt, $signups);
    }

    private function shiftOf(VolunteerOffer $offer, string $startsAt, int $signups): VolunteerShift
    {
        $shift = (new VolunteerShift())->setStartsAt(new \DateTime($startsAt));
        $offer->addShift($shift);

        for ($i = 0; $i < $signups; ++$i) {
            $shift->getSignups()->add(
                (new VolunteerSignup())->setShift($shift)->setPartner((new Partner())->setName('P'.$i))
            );
        }

        return $shift;
    }
}
