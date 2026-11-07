<?php

namespace App\Tests\Form;

use App\Entity\ConsumerGroupRound;
use App\Form\ConsumerGroupRoundType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormInterface;

/**
 * El mínimo del productor se pregunta por pasos (¿hay mínimo? / ¿se confirma
 * solo al llegar? / importe o socias y cuánto) y el formulario lo traduce a un
 * solo tipo en la ronda.
 *
 * Se blinda la traducción y, sobre todo, la limpieza: los campos escondidos en
 * pantalla viajan igual en el envío, y un umbral o una nota de un mínimo que ya
 * no existe acabaría guardado. Contra el FormFactory de verdad, como el resto
 * de tests de formularios.
 *
 * Sólo se miran los errores de los campos del mínimo, no la validez del
 * formulario entero: el productor (EntityType) pide una fila de BBDD que aquí
 * no aporta nada.
 */
class ConsumerGroupRoundTypeMinimumTest extends KernelTestCase
{
    public function testEnUnPedidoNuevoNoSaleNingunaRespuestaMarcada(): void
    {
        $form = $this->open(new ConsumerGroupRound());

        self::assertNull($form->get('hasMinimum')->getData(), 'Un «no» por defecto confirmaría el pedido al abrirlo sin que nadie lo decida.');
        self::assertNull($form->get('autoConfirm')->getData());
        self::assertNull($form->get('minimumKind')->getData());
    }

    public function testSinResponderSiHayMinimoNoPasa(): void
    {
        $form = $this->submit(new ConsumerGroupRound(), []);

        self::assertCount(1, $form->get('hasMinimum')->getErrors(true));
    }

    public function testSinMinimoLimpiaLoQueQuedabaEscondido(): void
    {
        $round = new ConsumerGroupRound();
        $this->submit($round, ['hasMinimum' => 'no', 'autoConfirm' => 'yes', 'minimumKind' => '1', 'minimumValue' => '150', 'minimumCondition' => '50 kg']);

        self::assertSame(ConsumerGroupRound::MINIMUM_TYPE_NONE, $round->getMinimumType());
        self::assertNull($round->getMinimumValue());
        self::assertNull($round->getMinimumCondition());
    }

    public function testConMinimoHayQueDecirSiSeConfirmaSolo(): void
    {
        $form = $this->submit(new ConsumerGroupRound(), ['hasMinimum' => 'yes']);

        self::assertCount(1, $form->get('autoConfirm')->getErrors());
    }

    public function testSiSeConfirmaSoloHayQueDecirSiEsImporteOSocias(): void
    {
        $form = $this->submit(new ConsumerGroupRound(), ['hasMinimum' => 'yes', 'autoConfirm' => 'yes']);

        self::assertCount(1, $form->get('minimumKind')->getErrors());
    }

    public function testMinimoEnEurosSeConfirmaraSolo(): void
    {
        $round = new ConsumerGroupRound();
        $this->submit($round, ['hasMinimum' => 'yes', 'autoConfirm' => 'yes', 'minimumKind' => '1', 'minimumValue' => '150', 'minimumCondition' => 'sobra']);

        self::assertSame(ConsumerGroupRound::MINIMUM_TYPE_AMOUNT, $round->getMinimumType());
        self::assertTrue($round->hasAutomaticMinimum());
        self::assertEquals(150, (float) $round->getMinimumValue());
        self::assertNull($round->getMinimumCondition(), 'La nota sólo explica los mínimos que se confirman a mano.');
    }

    public function testConfirmarAManoExigeExplicarElMinimo(): void
    {
        $form = $this->submit(new ConsumerGroupRound(), ['hasMinimum' => 'yes', 'autoConfirm' => 'no', 'minimumCondition' => '  ']);

        self::assertCount(1, $form->get('minimumCondition')->getErrors());
    }

    public function testConfirmarAManoGuardaLaNotaYNoElUmbral(): void
    {
        $round = new ConsumerGroupRound();
        $form = $this->submit($round, ['hasMinimum' => 'yes', 'autoConfirm' => 'no', 'minimumKind' => '1', 'minimumValue' => '150', 'minimumCondition' => '50 kg de aceitunas']);

        self::assertCount(0, $form->get('minimumCondition')->getErrors());
        self::assertSame(ConsumerGroupRound::MINIMUM_TYPE_MANUAL, $round->getMinimumType());
        self::assertNull($round->getMinimumValue());
        self::assertSame('50 kg de aceitunas', $round->getMinimumCondition());
    }

    public function testAlEditarSaleMarcadoLoQueYaTenia(): void
    {
        $round = (new ConsumerGroupRound())->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_PARTICIPANTS)->setMinimumValue('10');
        $this->setId($round, 7);

        $form = $this->open($round);

        self::assertSame('yes', $form->get('hasMinimum')->getData());
        self::assertSame('yes', $form->get('autoConfirm')->getData());
        self::assertSame(ConsumerGroupRound::MINIMUM_TYPE_PARTICIPANTS, $form->get('minimumKind')->getData());
    }

    public function testAlEditarUnMinimoAManoSaleMarcadoQueNoSeConfirmaSolo(): void
    {
        $round = (new ConsumerGroupRound())->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_MANUAL);
        $this->setId($round, 7);

        $form = $this->open($round);

        self::assertSame('no', $form->get('autoConfirm')->getData());
        self::assertNull($form->get('minimumKind')->getData());
    }

    public function testAlEditarUnPedidoSinMinimoSaleMarcadoNo(): void
    {
        $round = new ConsumerGroupRound();
        $this->setId($round, 7);

        self::assertSame('no', $this->open($round)->get('hasMinimum')->getData());
    }

    /**
     * Envía el formulario con los datos mínimos de cabecera más $minimum.
     *
     * @param array<string, string> $minimum campos del mínimo
     */
    private function submit(ConsumerGroupRound $round, array $minimum): FormInterface
    {
        $form = $this->open($round);
        $form->submit(array_merge([
            'title' => 'Aceite',
            'ordersCloseAt' => (new \DateTime('+5 days'))->format('Y-m-d'),
        ], $minimum));

        return $form;
    }

    /**
     * Monta el formulario de verdad, sin CSRF.
     */
    private function open(ConsumerGroupRound $round): FormInterface
    {
        self::bootKernel();

        return static::getContainer()->get('form.factory')->create(ConsumerGroupRoundType::class, $round, [
            'csrf_protection' => false,
        ]);
    }

    /**
     * Le pone id a una ronda sin pasar por la BBDD: es lo que distingue
     * editar de crear.
     */
    private function setId(ConsumerGroupRound $round, int $id): void
    {
        $property = new \ReflectionProperty(ConsumerGroupRound::class, 'id');
        $property->setValue($round, $id);
    }
}
