<?php

namespace App\Tests\Form;

use App\Entity\Node;
use App\Form\NodeType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * El bloque del montaje de cestas en el formulario del punto de recogida.
 *
 * Lo que se blinda aquí es que el bloque APAREZCA Y DESAPAREZCA con el módulo
 * de voluntariado. Si apareciera con el módulo apagado, quien marcara la
 * casilla se quedaría esperando a una gente que el sistema nunca convocó; y si
 * los campos existieran pero la plantilla no los pintara, saldrían en crudo al
 * final de la página, que ya pasó una vez en el alta de tareas.
 *
 * Va contra el kernel porque el formulario se construye con el contenedor, pero
 * no crea ni toca datos.
 */
class NodeDeliveryPrepFieldsTest extends KernelTestCase
{
    /**
     * Con el módulo de voluntariado apagado no se pregunta por el montaje.
     */
    public function testConElModuloApagadoNoHayBloqueDeMontaje(): void
    {
        $form = $this->factory()->create(NodeType::class, new Node());

        $this->assertFalse($form->has('deliveryPrep'));
        $this->assertFalse($form->has('deliveryPrepTime'));
        $this->assertFalse($form->has('deliveryPrepSlots'));
        $this->assertFalse($form->has('deliveryPrepMinutes'));
        $this->assertFalse($form->has('deliveryPrepDayOffset'));
    }

    /**
     * Y con el módulo encendido están los cinco campos. Se comprueban todos
     * porque la plantilla los pinta uno a uno: al que falte lo saca `form_rest`
     * en crudo al final de la página.
     */
    public function testConElModuloEncendidoEstanLosCincoCampos(): void
    {
        $form = $this->factory()->create(NodeType::class, new Node(), ['with_delivery_prep' => true]);

        foreach (['deliveryPrep', 'deliveryPrepTime', 'deliveryPrepSlots', 'deliveryPrepMinutes', 'deliveryPrepDayOffset'] as $field) {
            $this->assertTrue($form->has($field), sprintf('Falta el campo "%s".', $field));
        }
    }

    /**
     * La configuración de Torremocha, entrada tal cual desde la pantalla: se
     * guarda y llega entera a la entidad.
     */
    public function testGuardarLaConfiguracionDeTorremocha(): void
    {
        $node = new Node();
        $form = $this->submit($node, [
            'deliveryPrep' => '1',
            'deliveryPrepDayOffset' => '-1',
            'deliveryPrepTime' => '18:30',
            'deliveryPrepSlots' => '4',
            'deliveryPrepMinutes' => '90',
        ]);

        $this->assertTrue($form->isValid(), $this->errorsOf($form));
        $this->assertTrue($node->isDeliveryPrep());
        $this->assertSame(-1, $node->getDeliveryPrepDayOffset());
        $this->assertSame('18:30', $node->getDeliveryPrepTime()?->format('H:i'));
        $this->assertSame(4, $node->getDeliveryPrepSlots());
        $this->assertSame(90, $node->getDeliveryPrepMinutes());
    }

    /**
     * Marcar la casilla sin decir la hora se rechaza en el formulario, que es
     * donde alguien lo va a hacer.
     */
    public function testMarcarElMontajeSinHoraSeRechaza(): void
    {
        $form = $this->submit(new Node(), ['deliveryPrep' => '1', 'deliveryPrepTime' => '']);

        $this->assertFalse($form->isValid());
        $this->assertCount(1, $form->get('deliveryPrepTime')->getErrors());
    }

    /**
     * El caso de todos los demás puntos: la casilla sin marcar y el resto
     * vacío pasa sin protestar.
     */
    public function testUnPuntoQueNoMontaConVoluntariadoEsValido(): void
    {
        $node = new Node();
        $form = $this->submit($node, ['deliveryPrep' => null]);

        $this->assertTrue($form->isValid(), $this->errorsOf($form));
        $this->assertFalse($node->isDeliveryPrep());
    }

    /**
     * Envía el formulario con lo mínimo para que un punto sea válido, más lo
     * que cambie cada caso.
     *
     * @param Node $node el punto que se rellena
     * @param array<string, string|null> $prep los campos del montaje del caso
     */
    private function submit(Node $node, array $prep): FormInterface
    {
        // Sin CSRF por lo mismo que en VolunteerOfferRepeatFieldsTest: aquí se
        // prueba la validación del formulario, no la del token, y el error del
        // token caería en la raíz sin que lo acusara ningún caso.
        $form = $this->factory()->create(
            NodeType::class,
            $node,
            ['with_delivery_prep' => true, 'csrf_protection' => false]
        );

        $form->submit(array_merge([
            'name' => 'Torremocha',
            'deliveryWeekday' => '5',
            'cadence' => Node::CADENCE_WEEKLY,
            'deliveryPrepDayOffset' => '0',
        ], $prep));

        return $form;
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

    private function factory(): FormFactoryInterface
    {
        self::bootKernel();

        return static::getContainer()->get(FormFactoryInterface::class);
    }
}
