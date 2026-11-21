<?php

namespace App\Tests\Service\ConsumerGroup;

use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\MarkupType;
use App\Entity\Producer;
use App\Service\ConsumerGroup\PriceLocked;
use App\Service\ConsumerGroup\RoundPricing;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del precio desglosado: precio del productor + incremento de gestión, con
 * herencia del pedido, productos con incremento propio pendientes de revisar,
 * productores autogestionados, rondas antiguas sin desglosar y precio bloqueado.
 */
class RoundPricingTest extends TestCase
{
    private RoundPricing $pricing;
    private ConsumerGroupRound $round;

    protected function setUp(): void
    {
        $this->pricing = new RoundPricing();
        $this->round = (new ConsumerGroupRound())->setProducer(new Producer());
    }

    private function item(string $name, string $price = '0', ?string $producerPrice = null): ConsumerGroupRoundItem
    {
        $item = new ConsumerGroupRoundItem($this->round, (new ConsumerGroupProduct())->setName($name), $price);
        $item->setProducerPrice($producerPrice);
        $this->round->addItem($item);

        return $item;
    }

    public function testElProductoHeredaElIncrementoDelPedido(): void
    {
        $item = $this->item('Naranjas', '0', '2.00');
        $this->round->setMarkup(MarkupType::Percent, '10.00');

        $this->pricing->reprice($item);

        self::assertSame('2.20', $item->getPrice());
    }

    public function testElIncrementoPropioSustituyeAlDelPedido(): void
    {
        $item = $this->item('Naranjas', '0', '2.00');
        $this->round->setMarkup(MarkupType::Percent, '10.00');

        $this->pricing->setBreakdown($item, '2.00', MarkupType::Amount, '0.50', true);

        self::assertSame('2.50', $item->getPrice());
    }

    public function testSinIncrementoElPrecioFinalEsElDelProductor(): void
    {
        $item = $this->item('Naranjas', '0', '2.00');

        $this->pricing->reprice($item);

        self::assertSame('2.00', $item->getPrice());
    }

    public function testUnProductorAutogestionadoNoLlevaIncremento(): void
    {
        $this->round->getProducer()->setSelfManaged(true);
        $this->round->setMarkup(MarkupType::Percent, '10.00');
        $item = $this->item('Naranjas', '0', '2.00');

        $this->pricing->reprice($item);

        self::assertSame('2.00', $item->getPrice());
    }

    public function testUnaRondaSinDesglosarNoSeRecalcula(): void
    {
        $item = $this->item('Naranjas', '3.40', null);
        $this->round->setMarkup(MarkupType::Percent, '10.00');

        $this->pricing->reprice($item);

        self::assertSame('3.40', $item->getPrice(), 'Sin precio de productor, el final es el dato');
    }

    public function testCambiarElIncrementoDelPedidoReprecioaLosQueHeredan(): void
    {
        $hereda = $this->item('Naranjas', '2.20', '2.00');
        $this->round->setMarkup(MarkupType::Percent, '10.00');

        $this->pricing->changeRoundMarkup($this->round, MarkupType::Percent, '20.00');

        self::assertSame('2.40', $hereda->getPrice());
        self::assertFalse($hereda->isMarkupPendingReview());
        self::assertSame('20.00', $this->round->getMarkupValue());
    }

    public function testCambiarElIncrementoDejaPendientesLosQueTienenElSuyoSinTocarlosElPrecio(): void
    {
        $propio = $this->item('Aceite', '8.50', '8.00');
        $propio->setOwnMarkup(MarkupType::Amount, '0.50');
        $this->round->setMarkup(MarkupType::Percent, '10.00');

        $this->pricing->changeRoundMarkup($this->round, MarkupType::Percent, '20.00');

        self::assertSame('8.50', $propio->getPrice());
        self::assertTrue($propio->isMarkupPendingReview());
        self::assertSame([$propio], $this->pricing->pendingReview($this->round));
    }

    public function testNoCambiarElIncrementoNoMarcaNada(): void
    {
        $propio = $this->item('Aceite', '8.50', '8.00');
        $propio->setOwnMarkup(MarkupType::Amount, '0.50');
        $this->round->setMarkup(MarkupType::Percent, '10.00');

        $this->pricing->changeRoundMarkup($this->round, MarkupType::Percent, '10');

        self::assertFalse($propio->isMarkupPendingReview(), 'Guardar sin cambiar el incremento no obliga a revalidar');
    }

    public function testValidarUnPendienteLoQuitaDeLaLista(): void
    {
        $propio = $this->item('Aceite', '8.50', '8.00');
        $propio->setOwnMarkup(MarkupType::Amount, '0.50');
        $propio->setMarkupPendingReview(true);

        $this->pricing->setBreakdown($propio, '8.00', MarkupType::Amount, '0.80', true);

        self::assertFalse($propio->isMarkupPendingReview());
        self::assertSame('8.80', $propio->getPrice());
        self::assertSame([], $this->pricing->pendingReview($this->round));
    }

    public function testSinMarcarRevisadoSigueSiendoPendiente(): void
    {
        $propio = $this->item('Aceite', '8.50', '8.00');
        $propio->setMarkupPendingReview(true);

        $this->pricing->setBreakdown($propio, '8.00', MarkupType::Amount, '0.50', false);

        self::assertTrue($propio->isMarkupPendingReview());
    }

    public function testDesglosarUnaRondaAntiguaNoCambiaElPrecioFinal(): void
    {
        $item = $this->item('Naranjas', '2.50', null);

        $this->pricing->breakDown($item, '2.00');

        self::assertSame('2.50', $item->getPrice());
        self::assertSame('2.00', $item->getProducerPrice());
        self::assertSame(MarkupType::Amount, $item->getMarkupType());
        self::assertSame('0.50', $item->getMarkupValue());
        self::assertFalse($item->isMarkupPendingReview());
    }

    /**
     * @dataProvider desglosesInvalidos
     */
    public function testNoSeDesglosaUnPrecioDeProductorFueraDeRango(string $producerPrice): void
    {
        $item = $this->item('Naranjas', '2.50', null);

        $this->expectException(\InvalidArgumentException::class);
        $this->pricing->breakDown($item, $producerPrice);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function desglosesInvalidos(): iterable
    {
        yield 'cero' => ['0'];
        yield 'mayor que el precio final' => ['2.51'];
    }

    public function testConSociasApuntadasElPrecioFinalNoCambia(): void
    {
        $item = $this->item('Naranjas', '2.20', '2.00');
        $this->round->setMarkup(MarkupType::Percent, '10.00');
        $item->getLines()->add(new ConsumerGroupOrderLine(null, $item, '2'));

        $this->expectException(PriceLocked::class);
        $this->pricing->changeRoundMarkup($this->round, MarkupType::Percent, '20.00');
    }

    public function testSiElCambioBloqueadoFallaElPedidoConservaSuIncremento(): void
    {
        $item = $this->item('Naranjas', '2.20', '2.00');
        $this->round->setMarkup(MarkupType::Percent, '10.00');
        $item->getLines()->add(new ConsumerGroupOrderLine(null, $item, '2'));

        try {
            $this->pricing->changeRoundMarkup($this->round, MarkupType::Percent, '20.00');
            self::fail('Debía bloquearse');
        } catch (PriceLocked) {
        }

        self::assertSame('10.00', $this->round->getMarkupValue());
        self::assertSame('2.20', $item->getPrice());
    }

    public function testUnaLineaACeroNoBloqueaElPrecio(): void
    {
        $item = $this->item('Naranjas', '2.20', '2.00');
        $this->round->setMarkup(MarkupType::Percent, '10.00');
        $item->getLines()->add(new ConsumerGroupOrderLine(null, $item, '0'));

        $this->pricing->changeRoundMarkup($this->round, MarkupType::Percent, '20.00');

        self::assertSame('2.40', $item->getPrice());
    }

    public function testConSociasApuntadasSePuedeDesglosarSiElPrecioNoCambia(): void
    {
        $item = $this->item('Naranjas', '2.20', null);
        $item->getLines()->add(new ConsumerGroupOrderLine(null, $item, '2'));

        $this->pricing->breakDown($item, '2.00');

        self::assertSame('2.20', $item->getPrice());
        self::assertSame('2.00', $item->getProducerPrice());
    }

    public function testUnPrecioDeProductorQueRedondeaACeroSeRechaza(): void
    {
        $item = $this->item('Naranjas', '2.50', '2.00');

        $this->expectException(\InvalidArgumentException::class);
        $this->pricing->setBreakdown($item, '0.004', null, null, false);
    }

    public function testUnIncrementoQueDesbordaElPrecioFinalSeRechazaYElPedidoNoCambia(): void
    {
        $item = $this->item('Naranjas', '2.20', '2.00');
        $this->round->setMarkup(MarkupType::Percent, '10.00');

        try {
            $this->pricing->changeRoundMarkup($this->round, MarkupType::Amount, '999999.99');
            self::fail('Debía rechazarse: 2,00 + 999.999,99 no cabe en el precio');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame('10.00', $this->round->getMarkupValue(), 'El incremento anterior se conserva');
        self::assertSame('2.20', $item->getPrice());
    }

    public function testQuitarElIncrementoPropioDejaDeEstarPendiente(): void
    {
        $propio = $this->item('Aceite', '8.50', '8.00');
        $propio->setOwnMarkup(MarkupType::Amount, '0.50');
        $propio->setMarkupPendingReview(true);
        $this->round->setMarkup(MarkupType::Percent, '10.00');

        $this->pricing->setBreakdown($propio, '8.00', null, null, false);

        self::assertFalse($propio->isMarkupPendingReview(), 'Hereda el del pedido: no queda nada propio que revisar');
        self::assertSame('8.80', $propio->getPrice());
    }

    public function testSePuedeCorregirUnDesgloseYaHechoSinCambiarElPrecioFinal(): void
    {
        $item = $this->item('Naranjas', '26.00', null);
        $item->getLines()->add(new ConsumerGroupOrderLine(null, $item, '2'));
        $this->pricing->breakDown($item, '25.00');

        $this->pricing->breakDown($item, '20.00');

        self::assertSame('26.00', $item->getPrice(), 'Con socias apuntadas el final no se toca');
        self::assertSame('20.00', $item->getProducerPrice());
        self::assertSame('6.00', $item->getMarkupValue(), 'El incremento es la diferencia');
    }
}
