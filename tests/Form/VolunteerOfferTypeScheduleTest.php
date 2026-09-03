<?php

namespace App\Tests\Form;

use App\Entity\VolunteerOffer;
use App\Form\VolunteerOfferType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormInterface;

/**
 * Las reglas del formulario de tarea que no están en la entidad: las horas de
 * los tramos entre sí y la cadencia por semanas.
 *
 * Se blindan porque hasta ahora no existían y se guardaba cualquier cosa: hay
 * una tarea real «de 11:11 a 10:12». Contra el FormFactory de verdad y no con
 * un TypeTestCase, porque el formulario lleva EntityType y un servicio
 * inyectado: montarlo a mano sería probar otro formulario.
 */
class VolunteerOfferTypeScheduleTest extends KernelTestCase
{
    public function testUnTramoQueAcabaAntesDeEmpezarNoPasa(): void
    {
        $form = $this->submit(['firstStart' => '11:11', 'firstEnd' => '10:12']);

        $this->assertFalse($form->isValid());
        $this->assertSame(
            'La hora de fin tiene que ser posterior a la de inicio.',
            (string) $form->get('firstEnd')->getErrors()[0]->getMessage()
        );
    }

    public function testUnTramoQueEmpiezaYAcabaALaMismaHoraTampoco(): void
    {
        $form = $this->submit(['firstStart' => '10:00', 'firstEnd' => '10:00']);

        $this->assertFalse($form->isValid());
        $this->assertCount(1, $form->get('firstEnd')->getErrors());
    }

    /**
     * Antes una hora de fin sin inicio se ignoraba sin decir nada: la tarea se
     * guardaba sin ese tramo y quien la creó no sabía por qué.
     */
    public function testUnaHoraDeFinSinHoraDeInicioSeSenala(): void
    {
        $form = $this->submit(['firstStart' => '', 'firstEnd' => '12:00']);

        $this->assertFalse($form->isValid());
        $this->assertStringContainsString(
            'no a qué hora empieza',
            (string) $form->get('firstStart')->getErrors()[0]->getMessage()
        );
    }

    public function testElSegundoTramoNoPuedePisarAlPrimero(): void
    {
        $form = $this->submit([
            'firstStart' => '09:00', 'firstEnd' => '11:00',
            'secondStart' => '10:30', 'secondEnd' => '12:00',
        ]);

        $this->assertFalse($form->isValid());
        $this->assertStringContainsString(
            'cuando haya acabado el primero',
            (string) $form->get('secondStart')->getErrors()[0]->getMessage()
        );
    }

    /**
     * Sin hora de fin en el primero, el segundo tiene que empezar después de
     * que EMPIECE el primero: es lo único que se sabe de él.
     */
    public function testSinFinEnElPrimeroElSegundoSeComparaConSuInicio(): void
    {
        $form = $this->submit(['firstStart' => '09:00', 'secondStart' => '09:00']);

        $this->assertFalse($form->isValid());
        $this->assertCount(1, $form->get('secondStart')->getErrors());
    }

    public function testUnSegundoTramoSinPrimeroSeSenala(): void
    {
        $form = $this->submit(['firstStart' => '', 'secondStart' => '17:00']);

        $this->assertFalse($form->isValid());
        $this->assertStringContainsString('pon antes las horas de arriba', (string) $form->get('secondStart')->getErrors()[0]->getMessage());
    }

    public function testDosTramosBienOrdenadosPasanYSeGuardan(): void
    {
        $form = $this->submit([
            'firstStart' => '09:00', 'firstEnd' => '11:00',
            'secondStart' => '17:00', 'secondEnd' => '19:00',
        ]);

        $this->assertTrue($form->isValid(), (string) $form->getErrors(true));
        $this->assertSame([['09:00', '11:00'], ['17:00', '19:00']], $form->getData()->getRepeatTimes());
    }

    /**
     * La cadencia sólo la lee la repetición por días fijos; en las demás se
     * deja en 1 aunque el campo —escondido en pantalla— viaje con otro valor.
     */
    public function testLaCadenciaVuelveAUnoSiNoEsPorDiasFijos(): void
    {
        $form = $this->submit([
            'repeatType' => VolunteerOffer::REPEAT_ONCE,
            'repeatEvery' => '2',
            'firstStart' => '10:00',
        ]);

        $this->assertTrue($form->isValid(), (string) $form->getErrors(true));
        $this->assertSame(1, $form->getData()->getRepeatEvery());
    }

    public function testLaCadenciaSeConservaEnDiasFijos(): void
    {
        $form = $this->submit([
            'repeatType' => VolunteerOffer::REPEAT_WEEKLY,
            'repeatWeekdays' => ['1'],
            'repeatEvery' => '2',
            'repeatUntil' => '2026-10-07',
            'firstStart' => '10:00',
        ]);

        $this->assertTrue($form->isValid(), (string) $form->getErrors(true));
        $this->assertSame(2, $form->getData()->getRepeatEvery());
    }

    /**
     * Una tarea que se repite sin «hasta» y sin la casilla de sin fin no pasa:
     * no se sabe si se olvidó la fecha o si se quiso para siempre.
     */
    public function testRepetirSinFechaFinalNiCasillaDeSinFinNoPasa(): void
    {
        $form = $this->submit([
            'repeatType' => VolunteerOffer::REPEAT_WEEKLY,
            'repeatWeekdays' => ['1'],
            'firstStart' => '10:00',
        ]);

        $this->assertFalse($form->isValid());
        $this->assertStringContainsString(
            'marca que no tiene fin definido',
            (string) $form->get('repeatUntil')->getErrors()[0]->getMessage()
        );
    }

    /**
     * Con la casilla marcada, sin fecha final es la respuesta y la tarea vale:
     * el generador abre turnos por delante mientras no se pare.
     */
    public function testConLaCasillaDeSinFinNoHaceFaltaFechaFinal(): void
    {
        $form = $this->submit([
            'repeatType' => VolunteerOffer::REPEAT_WEEKLY,
            'repeatWeekdays' => ['1'],
            'openEnded' => '1',
            'firstStart' => '10:00',
        ]);

        $this->assertTrue($form->isValid(), (string) $form->getErrors(true));
        $this->assertNull($form->getData()->getRepeatUntil());
    }

    /**
     * La casilla manda sobre una fecha que viniera puesta: el campo se esconde
     * al marcarla, pero escondido viaja igual.
     */
    public function testLaCasillaDeSinFinDescartaLaFechaFinalQueVenga(): void
    {
        $form = $this->submit([
            'repeatType' => VolunteerOffer::REPEAT_WEEKLY,
            'repeatWeekdays' => ['1'],
            'openEnded' => '1',
            'repeatUntil' => '2026-12-31',
            'firstStart' => '10:00',
        ]);

        $this->assertTrue($form->isValid(), (string) $form->getErrors(true));
        $this->assertNull($form->getData()->getRepeatUntil());
    }

    /**
     * Al abrir una tarea que se repite sin fecha final, la casilla sale
     * marcada: es lo que la tarea dice de sí misma.
     */
    public function testAlAbrirUnaTareaSinFinLaCasillaSaleMarcada(): void
    {
        self::bootKernel();
        $offer = (new VolunteerOffer())
            ->setTitle('Sin fin')
            ->setRepeatType(VolunteerOffer::REPEAT_WEEKLY)
            ->setRepeatWeekdays([1])
            ->setRepeatFrom(new \DateTimeImmutable('2026-09-07'))
            ->setRepeatUntil(null);

        $form = static::getContainer()->get('form.factory')->create(VolunteerOfferType::class, $offer, [
            'csrf_protection' => false,
        ]);

        $this->assertTrue($form->get('openEnded')->getData());
    }

    /**
     * Una tarea de una sola vez con título, día y hora. Lo que se pasa pisa
     * eso; así cada test dice sólo lo que cambia.
     *
     * @param array<string,mixed> $overrides campos del formulario a pisar
     *
     * @return FormInterface el formulario ya enviado
     */
    private function submit(array $overrides): FormInterface
    {
        self::bootKernel();

        // Sin CSRF: el token lo guarda la sesión y aquí no hay petición.
        $form = static::getContainer()->get('form.factory')->create(VolunteerOfferType::class, new VolunteerOffer(), [
            'csrf_protection' => false,
        ]);

        $form->submit(array_merge([
            'title' => 'Prueba de horas',
            'repeatType' => VolunteerOffer::REPEAT_ONCE,
            'repeatEvery' => '1',
            'repeatFrom' => '2026-09-07',
        ], $overrides));

        return $form;
    }
}
