<?php

namespace App\Tests\Entity;

use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\ConsumerGroupUnit;
use App\Entity\Producer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * Un pedido del grupo de consumo puede reunir varios productores. Lo que se protege
 * son las reglas que los unen al resto del pedido: los productos son de productores
 * que participan, los autogestionados no se mezclan y el mínimo automático no se
 * evalúa sobre compras de otro productor.
 */
class ConsumerGroupRoundProducersTest extends TestCase
{
    public function testSinProductorNoEsValido(): void
    {
        self::assertSame(['Elige al menos un productor.'], $this->producerViolations(new ConsumerGroupRound()));
    }

    public function testUnPedidoDeUnSoloProductorEsValidoAunqueSeaAutogestionado(): void
    {
        $round = (new ConsumerGroupRound())->addProducer($this->producer('Almazara')->setSelfManaged(true));

        self::assertSame([], $this->producerViolations($round));
        self::assertFalse($round->isMixed());
    }

    public function testUnAutogestionadoNoEntraEnUnPedidoConVarios(): void
    {
        $round = (new ConsumerGroupRound())
            ->addProducer($this->producer('Naranjero'))
            ->addProducer($this->producer('Almazara')->setSelfManaged(true));

        $violations = $this->producerViolations($round);

        self::assertCount(1, $violations);
        self::assertStringContainsString('Almazara', $violations[0]);
    }

    public function testElMinimoAutomaticoNoSePermiteEnUnPedidoConVarios(): void
    {
        $round = (new ConsumerGroupRound())
            ->addProducer($this->producer('Naranjero'))
            ->addProducer($this->producer('Almazara'))
            ->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_AMOUNT);

        self::assertSame(['El mínimo automático no está disponible en pedidos con varios productores.'], $this->producerViolations($round));
    }

    public function testElMinimoAManoSiSePermiteEnUnPedidoConVarios(): void
    {
        $round = (new ConsumerGroupRound())
            ->addProducer($this->producer('Naranjero'))
            ->addProducer($this->producer('Almazara'))
            ->setMinimumType(ConsumerGroupRound::MINIMUM_TYPE_MANUAL);

        self::assertSame([], $this->producerViolations($round));
    }

    public function testNoSeQuitaUnProductorQueYaTieneProductosEnElPedido(): void
    {
        $naranjero = $this->producer('Naranjero');
        $almazara = $this->producer('Almazara');
        $round = (new ConsumerGroupRound())->addProducer($naranjero)->addProducer($almazara);
        $aceite = (new ConsumerGroupProduct())->setName('Aceite')->setUnit((new ConsumerGroupUnit())->setName('L'));
        $almazara->addProduct($aceite);
        $round->addItem(new ConsumerGroupRoundItem($round, $aceite, '9.00'));

        self::assertSame([], $this->producerViolations($round));

        $round->removeProducer($almazara);

        $violations = $this->producerViolations($round);
        self::assertCount(1, $violations);
        self::assertStringContainsString('Almazara', $violations[0]);
    }

    public function testSePuedeQuitarUnProductorSinProductosEnElPedido(): void
    {
        $round = (new ConsumerGroupRound())->addProducer($this->producer('Naranjero'))->addProducer($almazara = $this->producer('Almazara'));

        $round->removeProducer($almazara);

        self::assertSame([], $this->producerViolations($round));
    }

    public function testLosNombresSalenEnOrdenAlfabeticoYElUnicoProductorSeDistingue(): void
    {
        $naranjero = $this->producer('Naranjero');
        $round = (new ConsumerGroupRound())->addProducer($naranjero);

        self::assertSame($naranjero, $round->getSoleProducer());

        $round->addProducer($this->producer('Almazara'))->addProducer($naranjero);

        self::assertNull($round->getSoleProducer(), 'Con varios no hay «el» productor');
        self::assertSame('Almazara, Naranjero', $round->getProducerNames());
        self::assertCount(2, $round->getProducers(), 'Añadir dos veces el mismo no lo duplica');
    }

    public function testUnItemEsAutogestionadoSegunElProductorDeSuProducto(): void
    {
        $propio = $this->producer('Almazara')->setSelfManaged(true);
        $ajeno = $this->producer('Naranjero');
        $round = new ConsumerGroupRound();
        $aceite = (new ConsumerGroupProduct())->setName('Aceite');
        $naranjas = (new ConsumerGroupProduct())->setName('Naranjas');
        $propio->addProduct($aceite);
        $ajeno->addProduct($naranjas);

        self::assertTrue((new ConsumerGroupRoundItem($round, $aceite, '9.00'))->isSelfManaged());
        self::assertFalse((new ConsumerGroupRoundItem($round, $naranjas, '2.00'))->isSelfManaged());
        self::assertFalse((new ConsumerGroupRoundItem($round, new ConsumerGroupProduct(), '1.00'))->isSelfManaged(), 'Un producto sin productor no lo es');
    }

    private function producer(string $name): Producer
    {
        return (new Producer())->setName($name);
    }

    /**
     * Mensajes de las violaciones que cuelgan del campo de productores.
     *
     * @return list<string>
     */
    private function producerViolations(ConsumerGroupRound $round): array
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $messages = [];
        foreach ($validator->validate($round) as $violation) {
            if ($violation->getPropertyPath() === 'producers') {
                $messages[] = (string) $violation->getMessage();
            }
        }

        return $messages;
    }
}
