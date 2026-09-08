<?php

namespace App\Tests\Entity;

use App\Entity\Partner;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Entity\VolunteerSignup;
use PHPUnit\Framework\TestCase;

/**
 * Cuándo un turno corre prisa, y cuándo sólo está cerca.
 *
 * SON DOS PREGUNTAS DISTINTAS y el motivo de que estén separadas es un fallo
 * real: al agrupar la pantalla de voluntariado con una sola regla, "escribir el
 * boletín" —pasado mañana, sin número de plazas— caía en «próximas semanas»,
 * que es donde no lo iba a ver nadie. En qué montón cae un turno lo decide
 * CUÁNDO es (isImminent); si además se pinta como alarma lo decide cuánta gente
 * le falta (isUrgent).
 *
 * Sin base de datos: es aritmética de fechas y una condición, y meterle un
 * kernel por medio sólo lo haría más lento y más frágil.
 */
class VolunteerShiftUrgencyTest extends TestCase
{
    /** Un momento fijo, para que los tests no dependan de cuándo se ejecuten. */
    private const NOW = '2026-09-08 12:00';

    /**
     * Dentro de la ventana es inminente; justo fuera, no. Los dos lados del
     * umbral, porque un `<` en vez de un `<=` no lo caza ningún test que sólo
     * mire el centro.
     */
    public function testLaVentanaDeInminenciaSonSieteDias(): void
    {
        $this->assertTrue($this->shift('2026-09-09 10:00')->isImminent($this->now()), 'Mañana es inminente.');
        $this->assertTrue($this->shift('2026-09-15 10:00')->isImminent($this->now()), 'El séptimo día entra.');
        $this->assertFalse($this->shift('2026-09-16 10:00')->isImminent($this->now()), 'El octavo ya es agenda.');
    }

    /**
     * La razón de existir de isImminent(): un turno de pasado mañana cuya tarea
     * no tiene tope de plazas —el boletín, llamar a las socias que no han
     * renovado— sigue siendo de esta semana, aunque nunca vaya a pintarse como
     * alarma porque no le "faltan" un número concreto de personas.
     */
    public function testSinTopeDePlazasSigueSiendoDeEstaSemana(): void
    {
        $shift = $this->shift('2026-09-10 18:00', slots: null);

        $this->assertTrue($shift->isImminent($this->now()), 'Pasado mañana es pasado mañana, con tope o sin él.');
        $this->assertFalse($shift->isUrgent($this->now()), 'Sin plazas que contar no hay alarma que dar.');
    }

    /**
     * Un turno lleno no corre prisa aunque sea mañana: pintarlo de alarma gasta
     * lo único que no se recupera, que es que la alarma signifique algo.
     */
    public function testUnTurnoLlenoNoCorrePrisa(): void
    {
        $shift = $this->shift('2026-09-09 10:00', slots: 1);
        $this->signupOn($shift);

        $this->assertTrue($shift->isImminent($this->now()));
        $this->assertFalse($shift->isUrgent($this->now()));
    }

    /**
     * Con la fecha encima y gente por cubrir, sí.
     */
    public function testConPlazasLibresYLaFechaEncimaCorrePrisa(): void
    {
        $this->assertTrue($this->shift('2026-09-09 10:00', slots: 4)->isUrgent($this->now()));
    }

    /**
     * Destacar la tarea la hace urgente aunque quede lejos: es lo que le queda a
     * quien coordina para lo que el calendario no sabe —una helada, una avería—.
     * Pero NO la mete en el montón de esta semana: seguiría siendo del mes que
     * viene, y decir lo contrario en el encabezado sería mentir sobre la fecha.
     */
    public function testDestacarCorrePrisaPeroNoAdelantaLaFecha(): void
    {
        $shift = $this->shift('2026-10-15 10:00', slots: 4);
        $shift->getOffer()->setFeatured(true);

        $this->assertTrue($shift->isUrgent($this->now()));
        $this->assertFalse($shift->isImminent($this->now()), 'Destacar no cambia el día en que es.');
    }

    private function now(): \DateTime
    {
        return new \DateTime(self::NOW);
    }

    private function shift(string $startsAt, ?int $slots = 3): VolunteerShift
    {
        $offer = (new VolunteerOffer())
            ->setTitle('Escardar y recolectar')
            ->setSlots($slots)
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED);

        $shift = (new VolunteerShift())->setStartsAt(new \DateTime($startsAt));
        $offer->addShift($shift);

        return $shift;
    }

    private function signupOn(VolunteerShift $shift): VolunteerSignup
    {
        $signup = (new VolunteerSignup())->setPartner(new Partner());
        $shift->addSignup($signup);

        return $signup;
    }
}
