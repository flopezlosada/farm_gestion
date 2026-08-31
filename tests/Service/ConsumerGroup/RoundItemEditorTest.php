<?php

namespace App\Tests\Service\ConsumerGroup;

use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\Producer;
use App\Service\ConsumerGroup\RoundItemEditor;
use PHPUnit\Framework\TestCase;

/**
 * Unit test de la gestión de productos de una ronda: siembra desde el catálogo
 * (solo activos, a precio de referencia) y reconciliación de la selección.
 */
class RoundItemEditorTest extends TestCase
{
    private Producer $producer;
    private ConsumerGroupProduct $fruta;
    private ConsumerGroupProduct $aceite;
    private ConsumerGroupProduct $retirado;
    private RoundItemEditor $editor;

    protected function setUp(): void
    {
        $this->producer = new Producer();
        $this->fruta = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit('kg')->setReferencePrice('2.50');
        $this->aceite = (new ConsumerGroupProduct())->setName('Aceite')->setUnit('L')->setReferencePrice('8.00');
        $this->retirado = (new ConsumerGroupProduct())->setName('Descatalogado')->setUnit('ud')->setActive(false);
        $this->producer->addProduct($this->fruta);
        $this->producer->addProduct($this->aceite);
        $this->producer->addProduct($this->retirado);
        $this->editor = new RoundItemEditor();
    }

    private function round(): ConsumerGroupRound
    {
        $round = new ConsumerGroupRound();
        $round->setProducer($this->producer);
        return $round;
    }

    public function testSiembraSoloActivosAlPrecioDeReferencia(): void
    {
        $round = $this->round();

        $this->editor->seedFromCatalog($round);

        // El descatalogado (inactivo) no entra.
        self::assertCount(2, $round->getItems());
        $first = $round->getItems()->first();
        self::assertSame($this->fruta, $first->getProduct());
        self::assertSame('2.50', $first->getPrice());
    }

    public function testApplyAnadeQuitaYActualizaPrecio(): void
    {
        $round = $this->round();
        $this->editor->seedFromCatalog($round); // fruta + aceite

        // Reconciliar: fruta sube a 3.00, aceite se quita, retirado se añade a 5.00.
        $this->editor->apply($round, [
            ['product' => $this->fruta, 'included' => true, 'price' => '3.00'],
            ['product' => $this->aceite, 'included' => false, 'price' => '8.00'],
            ['product' => $this->retirado, 'included' => true, 'price' => '5.00'],
        ]);

        $productos = [];
        foreach ($round->getItems() as $item) {
            $productos[$item->getProduct()->getName()] = $item->getPrice();
        }

        self::assertArrayHasKey('Naranjas', $productos);
        self::assertSame('3.00', $productos['Naranjas']);
        self::assertArrayNotHasKey('Aceite', $productos, 'El aceite se quitó');
        self::assertArrayHasKey('Descatalogado', $productos);
        self::assertSame('5.00', $productos['Descatalogado']);
    }
}
