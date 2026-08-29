<?php

namespace App\Tests\Form;

use App\Entity\VolunteerOffer;
use App\Form\VolunteerOfferType;
use App\Service\Volunteering\OfferRepeatDates;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * Los campos de repetición del alta de una tarea de voluntariado.
 *
 * Son dos campos SIN MAPEAR que sólo existen al dar de alta, y su coherencia no
 * la puede vigilar ninguna restricción de la entidad porque no son datos de la
 * entidad. Sin esta validación, elegir «los días que haya reparto» en una tarea
 * sin punto de recogida crearía la tarea y ninguna copia, sin decir por qué:
 * exactamente el fallo silencioso que hay que evitar.
 *
 * Va contra el kernel porque el formulario tiene EntityType (nodo, categorías) y
 * necesita el gestor de entidades para construirse, pero no crea ni toca datos.
 */
class VolunteerOfferRepeatFieldsTest extends KernelTestCase
{
    /**
     * La cadencia del calendario de reparto se ofrece siempre —el punto de
     * recogida se elige en este mismo formulario, así que al construirlo aún no
     * se sabe—, pero sin nodo no hay calendario al que preguntar y hay que
     * decirlo.
     */
    public function testLaCadenciaDeRepartoSinPuntoDeRecogidaSeRechaza(): void
    {
        $form = $this->submit([
            'repeatCadence' => OfferRepeatDates::CADENCE_DELIVERY,
            'repeatUntil' => '2026-12-31',
            'node' => '',
        ]);

        $this->assertFalse($form->isValid());
        $this->assertCount(1, $form->get('repeatCadence')->getErrors());
    }

    /**
     * Repetir sin decir hasta cuándo no tiene respuesta posible: ni cero copias
     * ni infinitas son lo que quería quien lo dejó a medias.
     */
    public function testRepetirSinFechaFinalSeRechaza(): void
    {
        $form = $this->submit([
            'repeatCadence' => OfferRepeatDates::CADENCE_WEEKLY,
            'repeatUntil' => '',
        ]);

        $this->assertFalse($form->isValid());
        $this->assertCount(1, $form->get('repeatUntil')->getErrors());
    }

    /**
     * Y el caso normal: la inmensa mayoría de las tareas no se repiten, así que
     * dejar los dos campos vacíos tiene que pasar sin más.
     */
    public function testUnaTareaQueNoSeRepiteEsValida(): void
    {
        $form = $this->submit(['repeatCadence' => '', 'repeatUntil' => '']);

        // Con el motivo, no con un "false is true" a secas: los otros dos casos
        // cuentan errores de UN campo, así que un error en cualquier otro sólo
        // se ve aquí — y sin el mensaje no hay forma de saber en cuál.
        $this->assertTrue($form->isValid(), $this->errorsOf($form));
    }

    /**
     * Todos los errores del formulario, campo a campo, en una línea legible.
     *
     * @param FormInterface $form el formulario enviado
     *
     * @return string los errores, o un aviso de que no hay ninguno
     */
    private function errorsOf(FormInterface $form): string
    {
        $messages = [];
        foreach ($form->getErrors(true) as $error) {
            $origin = $error->getOrigin();
            $messages[] = sprintf('%s: %s', $origin?->getName() ?? '(raíz)', $error->getMessage());
        }

        return [] === $messages
            ? 'El formulario no es válido pero no declara ningún error.'
            : implode(' | ', $messages);
    }

    /**
     * En la edición los campos ni aparecen: repetir una tarea que ya existe se
     * hace desde su ficha, donde se ve lo que ya tiene apuntado.
     */
    public function testAlEditarNoHayCamposDeRepeticion(): void
    {
        $form = $this->factory()->create(VolunteerOfferType::class, new VolunteerOffer());

        $this->assertFalse($form->has('repeatCadence'));
        $this->assertFalse($form->has('repeatUntil'));
    }

    /**
     * Envía el formulario de alta con lo mínimo para que sea válido, más lo que
     * pida cada caso.
     *
     * @param array<string, string> $repeat los campos que cambia el caso de prueba
     */
    private function submit(array $repeat): FormInterface
    {
        $form = $this->factory()->create(
            VolunteerOfferType::class,
            (new VolunteerOffer()),
            ['with_repeat' => true]
        );

        $form->submit(array_merge([
            'title' => 'Descargar el reparto',
            'startsAt' => '2026-09-04T17:00',
            'status' => VolunteerOffer::STATUS_DRAFT,
        ], $repeat));

        return $form;
    }

    private function factory(): FormFactoryInterface
    {
        self::bootKernel();

        return static::getContainer()->get(FormFactoryInterface::class);
    }
}
