<?php

namespace App\Tests\Service\ConsumerGroup;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\Producer;
use App\Service\ConsumerGroup\OrderEditor;
use PHPUnit\Framework\TestCase;

/**
 * Unit test de la sincronización de líneas de un pedido: alta, actualización y
 * baja de líneas según las cantidades enviadas desde el panel (por item de ronda).
 */
class OrderEditorTest extends TestCase
{
    private ConsumerGroupRoundItem $fruta;
    private ConsumerGroupRoundItem $aceite;
    private ConsumerGroupOrder $order;
    private OrderEditor $editor;

    protected function setUp(): void
    {
        $producer = new Producer();
        $round = new ConsumerGroupRound();
        $round->setProducer($producer);

        $frutaProduct = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit('kg');
        $aceiteProduct = (new ConsumerGroupProduct())->setName('Aceite')->setUnit('L');
        $producer->addProduct($frutaProduct);
        $producer->addProduct($aceiteProduct);

        $this->fruta = new ConsumerGroupRoundItem($round, $frutaProduct, '2.50');
        $this->aceite = new ConsumerGroupRoundItem($round, $aceiteProduct, '8.00');
        $round->addItem($this->fruta);
        $round->addItem($this->aceite);

        $this->order = new ConsumerGroupOrder($round);
        $this->editor = new OrderEditor();
    }

    /**
     * @param array<array{item: ConsumerGroupRoundItem, quantity: string}> $desired
     */
    private function apply(array $desired): void
    {
        $this->editor->apply($this->order, $desired);
    }

    public function testCreaLineasParaCantidadesPositivas(): void
    {
        $this->apply([
            ['item' => $this->fruta, 'quantity' => '3'],
            ['item' => $this->aceite, 'quantity' => '0'],
        ]);

        self::assertCount(1, $this->order->getLines());
        self::assertSame($this->fruta, $this->order->getLines()->first()->getRoundItem());
        self::assertSame('3', $this->order->getLines()->first()->getQuantity());
    }

    public function testActualizaLineaExistente(): void
    {
        $this->order->addLine(new ConsumerGroupOrderLine($this->order, $this->fruta, '3'));

        $this->apply([['item' => $this->fruta, 'quantity' => '5']]);

        self::assertCount(1, $this->order->getLines());
        self::assertSame('5', $this->order->getLines()->first()->getQuantity());
    }

    public function testQuitaLineaAlPonerCantidadCero(): void
    {
        $this->order->addLine(new ConsumerGroupOrderLine($this->order, $this->fruta, '3'));

        $this->apply([['item' => $this->fruta, 'quantity' => '0']]);

        self::assertCount(0, $this->order->getLines());
        self::assertTrue($this->order->isEmpty());
    }

    public function testNoCreaLineasParaTodoCero(): void
    {
        $this->apply([
            ['item' => $this->fruta, 'quantity' => '0'],
            ['item' => $this->aceite, 'quantity' => '0'],
        ]);

        self::assertCount(0, $this->order->getLines());
        self::assertTrue($this->order->isEmpty());
    }
}
