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

        // La fruta se vende al peso y admite medios kilos; el aceite viene en
        // garrafa y no se parte. El salto lo dice el producto.
        $frutaProduct = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit('kg')->setHalfUnits(true);
        $aceiteProduct = (new ConsumerGroupProduct())->setName('Aceite')->setUnit('garrafa de 5 L');
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

    /**
     * Lo que viene en formato cerrado se pide entero: una garrafa, un saco, una
     * caja.
     *
     * El caso que motivó esto: el campo tenía paso de céntimo, así que las
     * flechas llevaban a pedir «0,03 garrafas de 5 L» — y eso llegaba tal cual
     * al pedido que se le pasa al productor.
     */
    public function testLoQueNoSePartaSeGuardaEnUnidadesEnteras(): void
    {
        $this->apply([['item' => $this->aceite, 'quantity' => '2,6']]);

        self::assertSame('3', $this->order->getLines()->first()->getQuantity());
    }

    /**
     * Lo que se vende al peso sí admite medios, porque así se pide: medio kilo
     * de queso, kilo y medio de naranjas.
     */
    public function testLoQueSeVendeAlPesoAdmiteMedios(): void
    {
        $this->apply([['item' => $this->fruta, 'quantity' => '1,5']]);

        self::assertSame('1.5', $this->order->getLines()->first()->getQuantity());
    }

    /**
     * Y sólo medios: con decimales libres vuelve a colarse el 0,03 de antes.
     */
    public function testAlPesoSeAjustaAlMedioMasCercano(): void
    {
        $this->apply([['item' => $this->fruta, 'quantity' => '1,3']]);

        self::assertSame('1.5', $this->order->getLines()->first()->getQuantity());
    }

    public function testUnaFraccionMinusculaEsNoPedirNada(): void
    {
        $this->apply([
            ['item' => $this->aceite, 'quantity' => '0,03'],
            ['item' => $this->fruta, 'quantity' => '0,03'],
        ]);

        self::assertCount(0, $this->order->getLines());
        self::assertTrue($this->order->isEmpty());
    }

    public function testLoQueNoEsUnaCantidadValeCero(): void
    {
        $this->apply([
            ['item' => $this->fruta, 'quantity' => 'dos garrafas'],
            ['item' => $this->aceite, 'quantity' => '-4'],
        ]);

        self::assertCount(0, $this->order->getLines());
    }

    public function testUnValorQueNiSiquieraEsUnEscalarNoRompe(): void
    {
        // El formulario lo manda quien quiera: quantity[3][] llega como array.
        $this->apply([['item' => $this->fruta, 'quantity' => ['3']]]);

        self::assertCount(0, $this->order->getLines());
    }
}
