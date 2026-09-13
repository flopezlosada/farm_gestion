<?php

namespace App\Tests\Service\ConsumerGroup;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\Producer;
use App\Repository\ConsumerGroupOrderRepository;
use App\Service\AppSettings;
use App\Service\ConsumerGroup\NodeConsumerGroupDeliveries;
use PHPUnit\Framework\TestCase;

/**
 * Unit test de los pedidos que van en la hoja de un nodo.
 *
 * Lo que se protege: que el rango sea la SEMANA del día en que ese nodo reparte
 * —un nodo que reparte el jueves no puede perderse un pedido fechado el viernes,
 * porque ese día no tiene reparto y el pedido no aparecería en ninguna hoja— y
 * que con el módulo apagado no se consulte nada.
 */
class NodeConsumerGroupDeliveriesTest extends TestCase
{
    public function testConElModuloApagadoNoSeConsultaNada(): void
    {
        $orders = $this->createMock(ConsumerGroupOrderRepository::class);
        $orders->expects(self::never())->method('findDeliverableForNodeBetween');

        $service = new NodeConsumerGroupDeliveries($orders, $this->settings(false));

        self::assertSame([], $service->forNodeAndDate(new Node(), new \DateTime('2026-09-25')));
    }

    public function testSinFechaDeRepartoNoHayNadaQueEntregar(): void
    {
        $orders = $this->createMock(ConsumerGroupOrderRepository::class);
        $orders->expects(self::never())->method('findDeliverableForNodeBetween');

        $service = new NodeConsumerGroupDeliveries($orders, $this->settings(true));

        self::assertSame([], $service->forNodeAndDate(new Node(), null));
    }

    /**
     * El rango es la semana natural del día de reparto del nodo, de lunes a
     * domingo, y no el día suelto.
     */
    public function testElRangoEsLaSemanaDelDiaDeRepartoDelNodo(): void
    {
        $seen = [];
        $orders = $this->createMock(ConsumerGroupOrderRepository::class);
        $orders->method('findDeliverableForNodeBetween')->willReturnCallback(
            function (Node $node, \DateTimeInterface $from, \DateTimeInterface $to) use (&$seen): array {
                $seen = [$from->format('Y-m-d'), $to->format('Y-m-d')];

                return [];
            }
        );

        // Jueves 24 de septiembre de 2026.
        (new NodeConsumerGroupDeliveries($orders, $this->settings(true)))
            ->forNodeAndDate(new Node(), new \DateTime('2026-09-24'));

        self::assertSame(['2026-09-21', '2026-09-27'], $seen);
    }

    public function testDevuelveSociaYLineasConCantidad(): void
    {
        $order = $this->orderWith(['2.00', '0']);

        $orders = $this->createMock(ConsumerGroupOrderRepository::class);
        $orders->method('findDeliverableForNodeBetween')->willReturn([$order]);

        $result = (new NodeConsumerGroupDeliveries($orders, $this->settings(true)))
            ->forNodeAndDate(new Node(), new \DateTime('2026-09-25'));

        self::assertCount(1, $result);
        self::assertCount(1, $result[0]['lines'], 'La línea a cero no se entrega.');
        self::assertSame('Aceite', $result[0]['lines'][0]['name']);
    }

    public function testUnPedidoSinCantidadesNoOcupaSitioEnLaHoja(): void
    {
        $orders = $this->createMock(ConsumerGroupOrderRepository::class);
        $orders->method('findDeliverableForNodeBetween')->willReturn([$this->orderWith(['0', '0'])]);

        $result = (new NodeConsumerGroupDeliveries($orders, $this->settings(true)))
            ->forNodeAndDate(new Node(), new \DateTime('2026-09-25'));

        self::assertSame([], $result);
    }

    private function settings(bool $enabled): AppSettings
    {
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getBool')->willReturn($enabled);

        return $settings;
    }

    /**
     * Un pedido con dos líneas, con las cantidades dadas.
     *
     * @param list<string> $quantities
     */
    private function orderWith(array $quantities): ConsumerGroupOrder
    {
        $producer = new Producer();
        $round = new ConsumerGroupRound();
        $round->setProducer($producer);

        $order = new ConsumerGroupOrder($round, (new Partner())->setName('Socia'));

        foreach (['Aceite', 'Aceitunas'] as $i => $name) {
            $product = (new ConsumerGroupProduct())->setName($name)->setUnit('ud');
            $producer->addProduct($product);
            $item = new ConsumerGroupRoundItem($round, $product, '10.00');
            $round->addItem($item);
            $order->addLine(new ConsumerGroupOrderLine($order, $item, $quantities[$i]));
        }

        return $order;
    }
}
