<?php

namespace App\Tests\Form;

use App\Entity\VolunteerOffer;
use App\Form\VolunteerOfferType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormInterface;

/**
 * Las reglas del formulario de tarea que no están en la entidad: las franjas
 * horarias entre sí, la cadencia por semanas, los días y el «sin fin».
 *
 * Se blindan porque hasta ahora no existían y se guardaba cualquier cosa: hay
 * una tarea real «de 11:11 a 10:12». Contra el FormFactory de verdad y no con
 * un TypeTestCase, porque el formulario lleva EntityType y un servicio
 * inyectado: montarlo a mano sería probar otro formulario.
 */
class VolunteerOfferTypeScheduleTest extends KernelTestCase
{
    public function testUnaFranjaQueAcabaAntesDeEmpezarNoPasa(): void
    {
        $form = $this->submit(['repeatTimes' => [$this->slot('11:11', '10:12')]]);

        $this->assertFalse($form->isValid());
        $this->assertSame(
            'La franja 1 acaba antes de empezar: la hora de fin tiene que ser posterior a la de inicio.',
            $this->firstError($form)
        );
    }

    public function testUnaFranjaQueEmpiezaYAcabaALaMismaHoraTampoco(): void
    {
        $form = $this->submit(['repeatTimes' => [$this->slot('10:00', '10:00')]]);

        $this->assertFalse($form->isValid());
        $this->assertCount(1, $form->get('repeatTimes')->getErrors());
    }

    /**
     * Antes una hora de fin sin inicio se ignoraba sin decir nada: la tarea se
     * guardaba sin esa franja y quien la creó no sabía por qué.
     */
    public function testUnaHoraDeFinSinHoraDeInicioSeSenala(): void
    {
        $form = $this->submit(['repeatTimes' => [$this->slot(null, '12:00')]]);

        $this->assertFalse($form->isValid());
        $this->assertSame('La franja 1 tiene hora de fin pero no de inicio.', $this->firstError($form));
    }

    public function testDosFranjasQueSePisanNoPasan(): void
    {
        $form = $this->submit(['repeatTimes' => [$this->slot('09:00', '11:00'), $this->slot('10:30', '12:00')]]);

        $this->assertFalse($form->isValid());
        $this->assertSame('La franja 2 empieza antes de que acabe la anterior.', $this->firstError($form));
    }

    /**
     * Sin hora de fin en la primera, la segunda tiene que empezar después de
     * que EMPIECE la primera: es lo único que se sabe de ella.
     */
    public function testSinFinEnLaPrimeraLaSegundaSeComparaConSuInicio(): void
    {
        $form = $this->submit(['repeatTimes' => [$this->slot('09:00', null), $this->slot('09:00', null)]]);

        $this->assertFalse($form->isValid());
        $this->assertCount(1, $form->get('repeatTimes')->getErrors());
    }

    /**
     * El orden en que se teclearon no significa nada: se guardan ordenadas por
     * hora de inicio, y así «se pisan» se mira entre vecinas de verdad.
     */
    public function testLasFranjasSeGuardanOrdenadasPorHoraDeInicio(): void
    {
        $form = $this->submit(['repeatTimes' => [$this->slot('17:00', '19:00'), $this->slot('09:00', '11:00')]]);

        $this->assertTrue($form->isValid(), (string) $form->getErrors(true));
        $this->assertSame([['09:00', '11:00'], ['17:00', '19:00']], $form->getData()->getRepeatTimes());
    }

    /**
     * Tres franjas: antes eran dos y fijas, y «vete a saber» cuántas hacen
     * falta. Y una sin hora de fin en medio vale.
     */
    public function testTresFranjasBienOrdenadasPasanYSeGuardan(): void
    {
        $form = $this->submit(['repeatTimes' => [
            $this->slot('08:00', '09:00'), $this->slot('13:00', null), $this->slot('17:00', '19:00'),
        ]]);

        $this->assertTrue($form->isValid(), (string) $form->getErrors(true));
        $this->assertSame([['08:00', '09:00'], ['13:00', null], ['17:00', '19:00']], $form->getData()->getRepeatTimes());
    }

    /**
     * Ninguna hora es obligatoria: hay trabajo sin franja («antes del día
     * 20»). Una franja con las dos horas vacías se descarta y no queda nada.
     */
    public function testSinNingunaHoraLaTareaValeYNoTieneFranjas(): void
    {
        $form = $this->submit(['repeatTimes' => [$this->slot(null, null)]]);

        $this->assertTrue($form->isValid(), (string) $form->getErrors(true));
        $this->assertSame([], $form->getData()->getRepeatTimes());
    }

    /**
     * La cadencia sólo la lee la repetición por días fijos; en las demás se
     * deja en 1 aunque el campo —escondido en pantalla— viaje con otro valor.
     */
    public function testLaCadenciaVuelveAUnoSiNoEsPorDiasFijos(): void
    {
        $form = $this->submit(['repeatType' => VolunteerOffer::REPEAT_ONCE, 'repeatEvery' => '2']);

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
        ]);

        $this->assertTrue($form->isValid(), (string) $form->getErrors(true));
        $this->assertSame(2, $form->getData()->getRepeatEvery());
    }

    /**
     * Los días son obligatorios también en la mensual: «el segundo martes»
     * sale de haber marcado el martes, no de la fecha del «desde el».
     */
    public function testLaMensualSinDiasMarcadosNoPasa(): void
    {
        $form = $this->submit([
            'repeatType' => VolunteerOffer::REPEAT_MONTHLY,
            'repeatUntil' => '2027-03-01',
        ]);

        $this->assertFalse($form->isValid());
        $this->assertSame('Marca al menos un día de la semana.', (string) $form->get('repeatWeekdays')->getErrors()[0]->getMessage());
    }

    /**
     * Una tarea que se repite sin «hasta» y sin la casilla de sin fin no pasa:
     * no se sabe si se olvidó la fecha o si se quiso para siempre.
     */
    public function testRepetirSinFechaFinalNiCasillaDeSinFinNoPasa(): void
    {
        $form = $this->submit(['repeatType' => VolunteerOffer::REPEAT_WEEKLY, 'repeatWeekdays' => ['1']]);

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
        $form = $this->submit(['repeatType' => VolunteerOffer::REPEAT_WEEKLY, 'repeatWeekdays' => ['1'], 'openEnded' => '1']);

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
        $form = $this->open($this->weeklyOffer()->setRepeatUntil(null));

        $this->assertTrue($form->get('openEnded')->getData());
    }

    public function testAlAbrirUnaTareaConFechaFinalLaCasillaSaleSinMarcar(): void
    {
        $form = $this->open($this->weeklyOffer()->setRepeatUntil(new \DateTimeImmutable('2026-12-31')));

        $this->assertNotTrue($form->get('openEnded')->getData());
    }

    /**
     * Al abrir una tarea, las franjas guardadas están en sus campos, una fila
     * por franja. Los rellenos de campos no mapeados van en POST_SET_DATA
     * porque en PRE Symfony los pisa al repartir los datos; con la casilla de
     * sin fin pasó.
     */
    public function testAlAbrirUnaTareaLasFranjasGuardadasEstanEnSusFilas(): void
    {
        $form = $this->open($this->weeklyOffer()->setRepeatTimes([['09:00', '10:00'], ['20:00', null]]));

        $rows = $form->get('repeatTimes');
        $this->assertCount(2, $rows);
        $this->assertSame('09:00:00', $rows->get('0')->get('start')->getData());
        $this->assertSame('10:00:00', $rows->get('0')->get('end')->getData());
        $this->assertSame('20:00:00', $rows->get('1')->get('start')->getData());
        $this->assertNull($rows->get('1')->get('end')->getData());
    }

    /**
     * Una tarea sin franjas se abre con UNA fila vacía, para que se vea dónde
     * va la hora sin pulsar «añadir» —y para que sin JavaScript haya una.
     */
    public function testUnaTareaSinFranjasSeAbreConUnaFilaVacia(): void
    {
        $form = $this->open($this->weeklyOffer());

        $this->assertCount(1, $form->get('repeatTimes'));
        $this->assertNull($form->get('repeatTimes')->get('0')->get('start')->getData());
    }

    /**
     * Una franja como la envía el navegador.
     *
     * @param string|null $start "HH:MM" o null
     * @param string|null $end   "HH:MM" o null
     *
     * @return array{start: string, end: string} los dos campos
     */
    private function slot(?string $start, ?string $end): array
    {
        return ['start' => (string) $start, 'end' => (string) $end];
    }

    private function firstError(FormInterface $form): string
    {
        return (string) $form->get('repeatTimes')->getErrors()[0]->getMessage();
    }

    /**
     * El formulario abierto sobre una tarea existente, sin enviar.
     *
     * @param VolunteerOffer $offer la tarea a editar
     *
     * @return FormInterface el formulario con los datos cargados
     */
    private function open(VolunteerOffer $offer): FormInterface
    {
        self::bootKernel();

        return static::getContainer()->get('form.factory')->create(VolunteerOfferType::class, $offer, [
            'csrf_protection' => false,
        ]);
    }

    /**
     * Una tarea semanal de los lunes, con fecha final: lo que cambia cada test
     * lo pone encima.
     *
     * @return VolunteerOffer la tarea, sin persistir
     */
    private function weeklyOffer(): VolunteerOffer
    {
        return (new VolunteerOffer())
            ->setTitle('Semanal')
            ->setRepeatType(VolunteerOffer::REPEAT_WEEKLY)
            ->setRepeatWeekdays([1])
            ->setRepeatFrom(new \DateTimeImmutable('2026-09-07'))
            ->setRepeatUntil(new \DateTimeImmutable('2026-12-31'));
    }

    /**
     * Una tarea de una sola vez con título y día. Lo que se pasa pisa eso; así
     * cada test dice sólo lo que cambia.
     *
     * @param array<string,mixed> $overrides campos del formulario a pisar
     *
     * @return FormInterface el formulario ya enviado
     */
    private function submit(array $overrides): FormInterface
    {
        $form = $this->open(new VolunteerOffer());

        $form->submit(array_merge([
            'title' => 'Prueba de horas',
            'repeatType' => VolunteerOffer::REPEAT_ONCE,
            'repeatEvery' => '1',
            'repeatFrom' => '2026-09-07',
        ], $overrides));

        return $form;
    }
}
