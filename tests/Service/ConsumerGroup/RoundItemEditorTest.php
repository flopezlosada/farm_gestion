<?php

namespace App\Tests\Service\ConsumerGroup;

use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupUnit;
use App\Entity\ConsumerGroupRound;
use App\Entity\MarkupType;
use App\Entity\Producer;
use App\Repository\ConsumerGroupRoundItemRepository;
use App\Service\ConsumerGroup\RoundItemEditor;
use App\Service\ConsumerGroup\RoundPricing;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test de la gestión de productos de una ronda: siembra desde el catálogo
 * (solo activos, al precio de la última ronda que los llevó, o 0 si es la
 * primera vez) y reconciliación de la selección.
 */
class RoundItemEditorTest extends TestCase
{
    private Producer $producer;
    private ConsumerGroupProduct $fruta;
    private ConsumerGroupProduct $aceite;
    private ConsumerGroupProduct $retirado;
    private RoundItemEditor $editor;
    private ConsumerGroupRoundItemRepository&MockObject $roundItems;

    protected function setUp(): void
    {
        $this->producer = new Producer();
        $this->fruta = (new ConsumerGroupProduct())->setName('Naranjas')->setUnit((new ConsumerGroupUnit())->setName('kg'));
        $this->aceite = (new ConsumerGroupProduct())->setName('Aceite')->setUnit((new ConsumerGroupUnit())->setName('L'));
        $this->retirado = (new ConsumerGroupProduct())->setName('Descatalogado')->setUnit((new ConsumerGroupUnit())->setName('ud'))->setActive(false);
        $this->producer->addProduct($this->fruta);
        $this->producer->addProduct($this->aceite);
        $this->producer->addProduct($this->retirado);

        $this->roundItems = $this->createMock(ConsumerGroupRoundItemRepository::class);
        $this->editor = new RoundItemEditor($this->roundItems, new RoundPricing());
    }

    private function round(): ConsumerGroupRound
    {
        $round = new ConsumerGroupRound();
        $round->addProducer($this->producer);
        return $round;
    }

    public function testSiembraSoloActivosAlPrecioDeLaUltimaRondaOCero(): void
    {
        $round = $this->round();

        // La fruta ya se pidió antes (2.50 la última vez); el aceite es nuevo
        // en el catálogo, sin ninguna ronda anterior que lo llevara.
        $this->roundItems->method('findLastPriceForProduct')
            ->willReturnCallback(fn (ConsumerGroupProduct $p): ?string => $p === $this->fruta ? '2.50' : null);

        $this->editor->seedFromCatalog($round);

        // El descatalogado (inactivo) no entra.
        self::assertCount(2, $round->getItems());
        $precios = [];
        foreach ($round->getItems() as $item) {
            $precios[$item->getProduct()->getName()] = $item->getPrice();
        }
        self::assertSame('2.50', $precios['Naranjas'], 'Arranca con el precio de su última ronda');
        self::assertSame('0', $precios['Aceite'], 'Sin ronda anterior, arranca a 0');
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

    public function testProductoGestionadoSeFijaPorPrecioDeProductorMasIncrementoDelPedido(): void
    {
        $round = $this->round()->setMarkup(MarkupType::Percent, '10.00');

        $this->editor->apply($round, [[
            'product'       => $this->fruta,
            'included'      => true,
            'price'         => '0',
            'mode'          => 'managed',
            'producerPrice' => '2.00',
            'markupType'    => null,
            'markupValue'   => null,
        ]]);

        $item = $round->getItems()->first();
        self::assertSame('2.00', $item->getProducerPrice());
        self::assertSame('2.20', $item->getPrice());
    }

    public function testProductoAutogestionadoGuardaSuPrecioComoPrecioDeProductor(): void
    {
        $this->producer->setSelfManaged(true);
        $round = $this->round();

        $this->editor->apply($round, [
            ['product' => $this->fruta, 'included' => true, 'price' => '3.00'],
        ]);

        $item = $round->getItems()->first();
        self::assertSame('3.00', $item->getPrice());
        self::assertSame('3.00', $item->getProducerPrice(), 'Sin incremento: el precio final es el del productor');
    }

    public function testElModoSinDesglosarNoCambiaElPrecioFinal(): void
    {
        $round = $this->round();
        $this->editor->apply($round, [['product' => $this->fruta, 'included' => true, 'price' => '2.50']]);
        $item = $round->getItems()->first();

        $this->editor->apply($round, [[
            'product'       => $this->fruta,
            'included'      => true,
            'price'         => '2.50',
            'mode'          => 'breakdown',
            'producerPrice' => '2.00',
        ]]);

        self::assertSame('2.50', $item->getPrice());
        self::assertSame('2.00', $item->getProducerPrice());
    }

    public function testElModoSinDesglosarSinPrecioDeProductorNoTocaNada(): void
    {
        $round = $this->round();
        $this->editor->apply($round, [['product' => $this->fruta, 'included' => true, 'price' => '2.50']]);
        $item = $round->getItems()->first();

        $this->editor->apply($round, [[
            'product'       => $this->fruta,
            'included'      => true,
            'price'         => '2.50',
            'mode'          => 'breakdown',
            'producerPrice' => null,
        ]]);

        self::assertSame('2.50', $item->getPrice());
        self::assertNull($item->getProducerPrice());
    }

    public function testSiembraConElUltimoPrecioDeProductorYSinIncrementoElFinalEsElMismo(): void
    {
        $this->roundItems->method('findLastPriceForProduct')->willReturn('2.50');
        $this->roundItems->method('findLastProducerPriceForProduct')->willReturn('2.00');
        $round = $this->round();

        $this->editor->seedFromCatalog($round);

        $item = $round->getItems()->first();
        self::assertSame('2.00', $item->getProducerPrice());
        self::assertSame('2.00', $item->getPrice(), 'Con el pedido sin incremento, el final es el del productor');
    }

    public function testLoDelLocalSeAjustaAlSaltoDelProducto(): void
    {
        $this->aceite->setHalfUnits(false);   // garrafa: entera o nada
        $this->fruta->setHalfUnits(true);     // al peso: de medio en medio
        $round = $this->round();
        $this->editor->seedFromCatalog($round);
        [$naranjas, $aceite] = array_values($round->getItems()->toArray());

        $this->editor->applyAssociationQuantities([
            ['item' => $naranjas, 'quantity' => '1,3'],
            ['item' => $aceite, 'quantity' => '2,4'],
        ]);

        self::assertSame('1.5', $naranjas->getAssociationQuantity(), 'Al peso se redondea al medio');
        self::assertSame('2', $aceite->getAssociationQuantity(), 'Una garrafa no se parte');
    }

    public function testLoDelLocalSeVaciaConCero(): void
    {
        $round = $this->round();
        $this->editor->seedFromCatalog($round);
        [$naranjas] = array_values($round->getItems()->toArray());
        $naranjas->setAssociationQuantity('4');

        // El campo en blanco es «ya no pido nada de esto», no «déjalo como estaba»:
        // si no, no habría forma de quitar lo que se encargó de más.
        $this->editor->applyAssociationQuantities([
            ['item' => $naranjas, 'quantity' => ''],
        ]);

        self::assertSame('0', $naranjas->getAssociationQuantity());
    }

    public function testSiembraLosProductosDeTodosLosProductoresDelPedido(): void
    {
        $otro = new Producer();
        $miel = (new ConsumerGroupProduct())->setName('Miel')->setUnit((new ConsumerGroupUnit())->setName('bote'));
        $otro->addProduct($miel);
        $round = $this->round();
        $round->addProducer($otro);

        $this->editor->seedFromCatalog($round);

        $names = array_map(static fn (ConsumerGroupRoundItem $i): string => $i->getName(), $round->getItems()->toArray());
        sort($names);
        self::assertSame(['Aceite', 'Miel', 'Naranjas'], $names);
    }

    public function testNoAdmiteUnProductoDeUnProductorQueNoParticipa(): void
    {
        $ajeno = (new Producer());
        $miel = (new ConsumerGroupProduct())->setName('Miel')->setUnit((new ConsumerGroupUnit())->setName('bote'));
        $ajeno->addProduct($miel);

        $this->expectException(\InvalidArgumentException::class);

        $this->editor->apply($this->round(), [['product' => $miel, 'included' => true, 'price' => '5.00']]);
    }

    public function testUnProductoAjenoQueNoSeIncluyeNoMolesta(): void
    {
        $ajeno = new Producer();
        $miel = (new ConsumerGroupProduct())->setName('Miel')->setUnit((new ConsumerGroupUnit())->setName('bote'));
        $ajeno->addProduct($miel);
        $round = $this->round();

        $this->editor->apply($round, [['product' => $miel, 'included' => false, 'price' => '5.00']]);

        self::assertCount(0, $round->getItems());
    }
}
