<?php

namespace App\Tests\Service\ConsumerGroup;

use App\Entity\ConsumerGroupOrder;
use App\Entity\ConsumerGroupOrderLine;
use App\Entity\ConsumerGroupProduct;
use App\Entity\ConsumerGroupRound;
use App\Entity\ConsumerGroupRoundItem;
use App\Entity\Partner;
use App\Entity\Producer;
use App\Repository\ConsumerGroupOrderRepository;
use App\Service\ConsumerGroup\RepeatLastOrder;
use PHPUnit\Framework\TestCase;

/**
 * Unit test de «lo mismo que la vez pasada».
 *
 * Lo que se protege es el traslado entre pedidos DISTINTOS: las líneas viejas
 * apuntan a items de la ronda vieja, con otro precio y otro id, así que casar
 * por línea daría cantidades vacías (o, peor, cruzadas) sin que nadie lo note.
 */
class RepeatLastOrderTest extends TestCase
{
    private Producer $producer;
    private ConsumerGroupProduct $aceite;
    private ConsumerGroupProduct $aceitunas;

    protected function setUp(): void
    {
        $this->producer = new Producer();
        $this->aceite = $this->product(1, 'Aceite', 'garrafa de 5 L');
        $this->aceitunas = $this->product(2, 'Aceitunas', 'bote de 1 kg');
    }

    public function testTrasladaLasCantidadesAlItemDeEsteAnioAunqueCambieElPrecio(): void
    {
        $anterior = $this->round(10, ['aceite' => '38.00', 'aceitunas' => '7.00']);
        $actual = $this->round(11, ['aceite' => '42.00', 'aceitunas' => '7.50']);

        $order = $this->orderWith($anterior, ['aceite' => '2', 'aceitunas' => '1']);

        $quantities = $this->service($order)->quantitiesFor($actual, new Partner());

        // Las claves son los items del pedido ACTUAL, no los del anterior.
        self::assertSame(
            [$this->itemIdFor($actual, $this->aceite) => '2', $this->itemIdFor($actual, $this->aceitunas) => '1'],
            $quantities
        );
    }

    public function testNoProponeUnProductoQueYaNoEstaEsteAnio(): void
    {
        $anterior = $this->round(10, ['aceite' => '38.00', 'aceitunas' => '7.00']);
        $actual = $this->round(11, ['aceite' => '42.00']); // este año no hay aceitunas

        $order = $this->orderWith($anterior, ['aceite' => '2', 'aceitunas' => '3']);

        $quantities = $this->service($order)->quantitiesFor($actual, new Partner());

        self::assertSame([$this->itemIdFor($actual, $this->aceite) => '2'], $quantities);
    }

    public function testLasLineasACeroNoSeProponen(): void
    {
        $anterior = $this->round(10, ['aceite' => '38.00', 'aceitunas' => '7.00']);
        $actual = $this->round(11, ['aceite' => '42.00', 'aceitunas' => '7.50']);

        $order = $this->orderWith($anterior, ['aceite' => '2', 'aceitunas' => '0']);

        $quantities = $this->service($order)->quantitiesFor($actual, new Partner());

        self::assertSame([$this->itemIdFor($actual, $this->aceite) => '2'], $quantities);
    }

    public function testSinPedidoAnteriorNoHayNadaQueRepetir(): void
    {
        $actual = $this->round(11, ['aceite' => '42.00']);

        self::assertSame([], $this->service(null)->quantitiesFor($actual, new Partner()));
    }

    public function testUnPedidoSinProductorNoRompe(): void
    {
        $huerfano = new ConsumerGroupRound();

        self::assertSame([], $this->service(null)->quantitiesFor($huerfano, new Partner()));
    }

    /**
     * El servicio con un repositorio que devuelve el pedido anterior dado.
     */
    private function service(?ConsumerGroupOrder $previous): RepeatLastOrder
    {
        $orders = $this->createMock(ConsumerGroupOrderRepository::class);
        $orders->method('findLastForPartnerAndProducer')->willReturn($previous);

        return new RepeatLastOrder($orders);
    }

    /**
     * Producto del catálogo con id fijado (en producción lo pone Doctrine).
     */
    private function product(int $id, string $name, string $unit): ConsumerGroupProduct
    {
        $product = (new ConsumerGroupProduct())->setName($name)->setUnit($unit);
        $this->producer->addProduct($product);
        $this->setId($product, $id);

        return $product;
    }

    /**
     * Pedido colectivo con sus items, cada uno con id propio: los del pedido
     * anterior y los del actual son objetos distintos aunque el producto sea el
     * mismo, que es justo lo que este servicio tiene que resolver.
     *
     * @param array<string, string> $prices clave lógica => precio de esa ronda
     */
    private function round(int $id, array $prices): ConsumerGroupRound
    {
        $round = new ConsumerGroupRound();
        $round->setProducer($this->producer);
        $this->setId($round, $id);

        $itemId = $id * 100;
        foreach ($prices as $key => $price) {
            $product = 'aceite' === $key ? $this->aceite : $this->aceitunas;
            $item = new ConsumerGroupRoundItem($round, $product, $price);
            $this->setId($item, ++$itemId);
            $round->addItem($item);
        }

        return $round;
    }

    /**
     * Pedido de la socia sobre los items de esa ronda.
     *
     * @param array<string, string> $quantities clave lógica => cantidad pedida
     */
    private function orderWith(ConsumerGroupRound $round, array $quantities): ConsumerGroupOrder
    {
        $order = new ConsumerGroupOrder($round, new Partner());
        foreach ($round->getItems() as $item) {
            $key = 'Aceite' === $item->getProduct()?->getName() ? 'aceite' : 'aceitunas';
            if (isset($quantities[$key])) {
                $order->addLine(new ConsumerGroupOrderLine($order, $item, $quantities[$key]));
            }
        }

        return $order;
    }

    /**
     * Id del item que corresponde a ese producto en esa ronda.
     */
    private function itemIdFor(ConsumerGroupRound $round, ConsumerGroupProduct $product): int
    {
        foreach ($round->getItems() as $item) {
            if ($item->getProduct() === $product) {
                return (int) $item->getId();
            }
        }

        self::fail('El producto no está en ese pedido.');
    }

    /**
     * Fija el id de una entidad sin persistirla.
     */
    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity::class, 'id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
