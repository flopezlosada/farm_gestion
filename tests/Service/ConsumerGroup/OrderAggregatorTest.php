<?php

namespace App\Tests\Service\ConsumerGroup;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\Producer;
use App\Service\ConsumerGroup\OrderAggregator;
use PHPUnit\Framework\TestCase;

/**
 * Unit test de la agregación de pedidos de una ronda. Verifica el recuento de
 * participantes (ignorando pedidos vacíos), el total en euros y las cantidades y
 * subtotales por item de ronda (incluidos items sin pedidos).
 */
class OrderAggregatorTest extends TestCase
{
    private Producer $producer;

    protected function setUp(): void
    {
        $this->producer = new Producer();
    }

    private function product(string $name, string $unit): ConsumerGroupProduct
    {
        $p = (new ConsumerGroupProduct())->setName($name)->setUnit($unit);
        $this->producer->addProduct($p);
        return $p;
    }

    private function item(ConsumerGroupRound $round, ConsumerGroupProduct $product, string $price): ConsumerGroupRoundItem
    {
        $item = new ConsumerGroupRoundItem($round, $product, $price);
        $round->addItem($item);
        return $item;
    }

    private function order(ConsumerGroupRound $round): ConsumerGroupOrder
    {
        $order = new ConsumerGroupOrder($round);
        $round->addOrder($order);
        return $order;
    }

    public function testAgregaCantidadesTotalesYParticipantes(): void
    {
        $round = new ConsumerGroupRound();
        $round->setProducer($this->producer);
        $fruta = $this->item($round, $this->product('Naranjas', 'kg'), '2.50');
        $aceite = $this->item($round, $this->product('Aceite', 'L'), '8.00');

        // Socia A: 3 kg naranjas + 2 L aceite = 7,50 + 16,00 = 23,50
        $a = $this->order($round);
        $a->addLine(new ConsumerGroupOrderLine($a, $fruta, '3'));
        $a->addLine(new ConsumerGroupOrderLine($a, $aceite, '2'));

        // Socia B: 1,5 kg naranjas = 3,75
        $b = $this->order($round);
        $b->addLine(new ConsumerGroupOrderLine($b, $fruta, '1.5'));

        // Socia C: pedido vacío (línea a 0) → NO cuenta como participante
        $c = $this->order($round);
        $c->addLine(new ConsumerGroupOrderLine($c, $fruta, '0'));

        $result = (new OrderAggregator())->aggregate($round);

        self::assertSame(2, $result['participantCount'], 'El pedido vacío no cuenta');
        self::assertEqualsWithDelta(27.25, $result['total'], 0.001);

        // byItem sigue el orden del catálogo de la ronda: naranjas, aceite.
        self::assertCount(2, $result['byItem']);

        [$naranjasAgg, $aceiteAgg] = $result['byItem'];
        self::assertSame($fruta, $naranjasAgg['item']);
        self::assertEqualsWithDelta(4.5, $naranjasAgg['quantity'], 0.001);
        self::assertEqualsWithDelta(11.25, $naranjasAgg['subtotal'], 0.001);

        self::assertSame($aceite, $aceiteAgg['item']);
        self::assertEqualsWithDelta(2.0, $aceiteAgg['quantity'], 0.001);
        self::assertEqualsWithDelta(16.0, $aceiteAgg['subtotal'], 0.001);
    }

    public function testLoQueSePideParaElLocalSumaAlPedidoDelProductor(): void
    {
        $round = new ConsumerGroupRound();
        $round->setProducer($this->producer);
        $aceite = $this->item($round, $this->product('Aceite', 'garrafa de 5 L'), '40.00');

        // Una socia pide 2 garrafas = 80,00
        $a = $this->order($round);
        $a->addLine(new ConsumerGroupOrderLine($a, $aceite, '2'));

        // La comisión encarga 3 más para el local = 120,00
        $aceite->setAssociationQuantity('3');

        $result = (new OrderAggregator())->aggregate($round);

        [$linea] = $result['byItem'];
        self::assertEqualsWithDelta(2.0, $linea['quantity'], 0.001, 'Lo de las socias se ve aparte');
        self::assertEqualsWithDelta(3.0, $linea['associationQuantity'], 0.001);
        self::assertEqualsWithDelta(5.0, $linea['totalQuantity'], 0.001, 'Al productor se le piden las cinco');
        self::assertEqualsWithDelta(200.0, $linea['subtotal'], 0.001);
        self::assertEqualsWithDelta(200.0, $result['total'], 0.001);

        // Lo del local no es de nadie: no añade participantes.
        self::assertSame(1, $result['participantCount']);
    }

    public function testUnPedidoSoloParaElLocalNoTieneParticipantes(): void
    {
        // El caso de completar el mínimo del productor cuando no se ha apuntado
        // nadie: hay pedido que hacer, pero no hay ninguna socia detrás.
        $round = new ConsumerGroupRound();
        $round->setProducer($this->producer);
        $this->item($round, $this->product('Naranjas', 'caja de 10 kg'), '12.00')
            ->setAssociationQuantity('2');

        $result = (new OrderAggregator())->aggregate($round);

        self::assertSame(0, $result['participantCount']);
        self::assertEqualsWithDelta(24.0, $result['total'], 0.001);
        self::assertEqualsWithDelta(0.0, $result['byItem'][0]['quantity'], 0.001);
        self::assertEqualsWithDelta(2.0, $result['byItem'][0]['totalQuantity'], 0.001);
    }

    public function testItemSinPedidosApareceConCero(): void
    {
        $round = new ConsumerGroupRound();
        $round->setProducer($this->producer);
        $this->item($round, $this->product('Producto sin demanda', 'ud'), '5.00');

        $result = (new OrderAggregator())->aggregate($round);

        self::assertSame(0, $result['participantCount']);
        self::assertSame(0.0, $result['total']);
        self::assertCount(1, $result['byItem']);
        self::assertEqualsWithDelta(0.0, $result['byItem'][0]['quantity'], 0.001);
    }

    public function testRondaSinPedidosNiItems(): void
    {
        $result = (new OrderAggregator())->aggregate(new ConsumerGroupRound());

        self::assertSame(0, $result['participantCount']);
        self::assertSame(0.0, $result['total']);
        self::assertSame([], $result['byItem']);
    }
}
