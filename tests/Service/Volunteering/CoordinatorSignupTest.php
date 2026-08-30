<?php

namespace App\Tests\Service\Volunteering;

use App\Entity\Partner;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerSignup;
use App\Service\Volunteering\CoordinatorSignup;
use PHPUnit\Framework\TestCase;

/**
 * Quien monta una tarea consta en ella, y sus horas cuentan.
 *
 * Antes esto dependía de que alguien se acordara de anotarlo a mano DESPUÉS, y
 * no se acordaba nadie: la gente que más sostiene el voluntariado —quien cuadra
 * el reparto todos los viernes— salía con el contador a cero. Ahora se dice al
 * crear la tarea y la inscripción aparece sola.
 *
 * Lo que más se prueba aquí es qué pasa al CAMBIAR de coordinador, que es donde
 * se puede hacer daño de verdad: quitarle a alguien unas horas que ya constaba
 * que había hecho.
 */
class CoordinatorSignupTest extends TestCase
{
    public function testQuienCoordinaConstaSinTenerQueApuntarse(): void
    {
        $marisa = new Partner();
        $offer = $this->offer()->setCoordinator($marisa);

        (new CoordinatorSignup())->sync($offer);

        $signups = $offer->getSignups();
        $this->assertCount(1, $signups);
        $this->assertSame($marisa, $signups[0]->getPartner());
        $this->assertTrue($signups[0]->isCoordination());
    }

    /**
     * Nace SIN responder, como cualquier otra inscripción. Darla por hecha al
     * crear la tarea sería computar horas por un trabajo que aún no ha ocurrido
     * —y dejarlas puestas en una tarea que luego se anula.
     */
    public function testNaceSinResponderYNoComputaHorasTodavia(): void
    {
        $offer = $this->offer()->setCoordinator(new Partner());

        (new CoordinatorSignup())->sync($offer);

        $this->assertFalse($offer->getSignups()[0]->isSettled());
        $this->assertSame(0, $offer->getCreditedMinutesTotal());
    }

    /**
     * Y NO ocupa plaza: organizar el reparto no es estar allí descargando cajas.
     * Si contara, una tarea de dos plazas se daría por llena con una sola
     * persona trabajando.
     */
    public function testCoordinarNoConsumeLasPlazasQueHacenFalta(): void
    {
        $offer = $this->offer()->setSlots(2)->setCoordinator(new Partner());

        (new CoordinatorSignup())->sync($offer);

        $this->assertSame(0, $offer->getFilledSlots());
        $this->assertSame(2, $offer->getRemainingSlots());
    }

    /**
     * Llamarlo dos veces no duplica nada: el UNIQUE (offer, partner) no admite
     * dos inscripciones de la misma persona, así que guardar la tarea otra vez
     * reventaría al llegar a la base de datos.
     */
    public function testGuardarDosVecesNoDuplicaLaInscripcion(): void
    {
        $offer = $this->offer()->setCoordinator(new Partner());
        $service = new CoordinatorSignup();

        $service->sync($offer);
        $service->sync($offer);

        $this->assertCount(1, $offer->getSignups());
    }

    /**
     * Quien ya se había apuntado a trabajar y acaba coordinando NO genera una
     * segunda fila: es la misma inscripción cambiando de papel. Lo que hubiera
     * dicho se conserva.
     */
    public function testQuienYaEstabaApuntadoPasaACoordinarSinPerderLoDicho(): void
    {
        $ana = new Partner();
        $offer = $this->offer();
        $suya = $this->signupOn($offer, $ana);
        $suya->setNotes('Llevo la furgoneta');

        $offer->setCoordinator($ana);
        (new CoordinatorSignup())->sync($offer);

        $this->assertCount(1, $offer->getSignups());
        $this->assertTrue($suya->isCoordination());
        $this->assertSame('Llevo la furgoneta', $suya->getNotes());
    }

    /**
     * Cambiar de coordinador retira a quien ya no lo es, si todavía no había
     * dicho nada: no tiene sentido dejarle una inscripción a alguien que no
     * pinta nada en esa tarea.
     */
    public function testCambiarDeCoordinadorRetiraAlAnteriorSiNoHabiaRespondido(): void
    {
        $marisa = new Partner();
        $jorge = new Partner();
        $offer = $this->offer()->setCoordinator($marisa);
        $service = new CoordinatorSignup();

        $service->sync($offer);
        $offer->setCoordinator($jorge);
        $service->sync($offer);

        $vivas = array_filter($offer->getSignups()->toArray(), fn (VolunteerSignup $s): bool => !$s->isCancelled());

        $this->assertCount(1, $vivas);
        $this->assertSame($jorge, reset($vivas)->getPartner());
    }

    /**
     * PERO si ya constaba que hizo el trabajo, sus horas son suyas. Un cambio
     * administrativo posterior no puede borrarle una aportación real — y eso es
     * exactamente lo que pasaría si esto se limitara a limpiar y volver a crear.
     */
    public function testCambiarDeCoordinadorNoLeQuitaLasHorasAQuienYaLasHizo(): void
    {
        $marisa = new Partner();
        $offer = $this->offer()->setCoordinator($marisa);
        $service = new CoordinatorSignup();

        $service->sync($offer);
        $offer->getSignups()[0]->confirmAttendance(VolunteerSignup::SOURCE_MANAGER, 120);

        $offer->setCoordinator(new Partner());
        $service->sync($offer);

        $suya = $offer->getSignups()[0];
        $this->assertSame($marisa, $suya->getPartner());
        $this->assertFalse($suya->isCancelled(), 'Lo que ya consta hecho no se retira.');
        $this->assertSame(120, $suya->getCreditedMinutes());
    }

    /**
     * Quitar el coordinador sin poner otro deja la tarea sin nadie al mando, que
     * es un estado legítimo: una llamada abierta a plantar puede no tenerlo.
     */
    public function testQuitarElCoordinadorDejaLaTareaSinNadieAlMando(): void
    {
        $offer = $this->offer()->setCoordinator(new Partner());
        $service = new CoordinatorSignup();

        $service->sync($offer);
        $offer->setCoordinator(null);
        $service->sync($offer);

        $vivas = array_filter($offer->getSignups()->toArray(), fn (VolunteerSignup $s): bool => !$s->isCancelled());

        $this->assertSame([], $vivas);
    }

    /**
     * Una tarea sin coordinador y sin nadie apuntado no gana filas por pasar por
     * aquí.
     */
    public function testSinCoordinadorNoPasaNada(): void
    {
        $offer = $this->offer();

        (new CoordinatorSignup())->sync($offer);

        $this->assertCount(0, $offer->getSignups());
    }

    private function offer(): VolunteerOffer
    {
        return (new VolunteerOffer())
            ->setTitle('Descargar el reparto')
            ->setStartsAt(new \DateTime('2099-03-15 17:00'))
            ->setStatus(VolunteerOffer::STATUS_PUBLISHED)
            ->setCreditedMinutes(30);
    }

    private function signupOn(VolunteerOffer $offer, Partner $partner): VolunteerSignup
    {
        $signup = (new VolunteerSignup())->setPartner($partner);
        $offer->addSignup($signup);

        return $signup;
    }
}
