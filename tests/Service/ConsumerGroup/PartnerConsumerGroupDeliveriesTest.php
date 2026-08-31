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
use App\Service\ConsumerGroup\PartnerConsumerGroupDeliveries;
use App\Service\Delivery\PartnerMonthProjection;
use PHPUnit\Framework\TestCase;

/**
 * Unit test de las dos banderas que el calendario usa para pintar un pedido del grupo
 * de consumo: `withBasket` (ese día le toca cesta no saltada → texto "junto a la cesta")
 * y `hasSlot` (ese día es una celda seleccionable del calendario → se ve en el panel; si
 * no, en el aviso de arriba). Ambas se resuelven contra la PROYECCIÓN del calendario, no
 * contra filas materializadas, porque las semanas futuras se dibujan del patrón.
 */
class PartnerConsumerGroupDeliveriesTest extends TestCase
{
    private Producer $producer;

    protected function setUp(): void
    {
        $this->producer = new Producer();
    }

    /**
     * Pedido de una socia con entrega el día dado. `empty` = sin líneas con cantidad
     * (un apunte que no pidió nada), que el servicio debe ignorar.
     */
    private function orderOn(string $deliveryDate, bool $empty = false): ConsumerGroupOrder
    {
        $round = (new ConsumerGroupRound())
            ->setProducer($this->producer)
            ->setDeliveryDate(new \DateTime($deliveryDate));
        $order = new ConsumerGroupOrder($round);
        $round->addOrder($order);

        if (!$empty) {
            $product = (new ConsumerGroupProduct())->setName('AOVE 5 L')->setUnit('garrafa');
            $this->producer->addProduct($product);
            $item = new ConsumerGroupRoundItem($round, $product, '38.00');
            $round->addItem($item);
            $order->addLine(new ConsumerGroupOrderLine($order, $item, '1'));
        }

        return $order;
    }

    /**
     * Servicio con dobles: el repositorio devuelve los pedidos dados y la proyección
     * dibuja los slots indicados para su mes.
     *
     * @param list<ConsumerGroupOrder> $orders
     * @param array<string, bool>      $slots  'Y-m-d' => saltada
     */
    private function service(array $orders, array $slots): PartnerConsumerGroupDeliveries
    {
        $repo = $this->createMock(ConsumerGroupOrderRepository::class);
        $repo->method('findDeliverableUpcomingForPartner')->willReturn($orders);

        $projection = $this->createMock(PartnerMonthProjection::class);
        $projection->method('projectMonth')->willReturnCallback(
            static function (Partner $p, int $year, int $month) use ($slots): array {
                $out = [];
                foreach ($slots as $ymd => $skipped) {
                    $d = new \DateTime($ymd);
                    if ((int) $d->format('Y') === $year && (int) $d->format('n') === $month) {
                        $out[] = ['date' => $d, 'skipped' => $skipped, 'basket' => null, 'items' => []];
                    }
                }

                return $out;
            }
        );

        return new PartnerConsumerGroupDeliveries($repo, $projection);
    }

    public function testDiaConCestaNoSaltada(): void
    {
        $out = $this->service([$this->orderOn('2026-07-10')], ['2026-07-10' => false])
            ->upcomingForPartner(new Partner());

        self::assertCount(1, $out);
        self::assertTrue($out[0]['withBasket'], 'Le toca cesta ese día → "junto a la cesta"');
        self::assertTrue($out[0]['hasSlot']);
    }

    public function testDiaConCestaSaltadaSigueEnElPanel(): void
    {
        $out = $this->service([$this->orderOn('2026-07-10')], ['2026-07-10' => true])
            ->upcomingForPartner(new Partner());

        self::assertCount(1, $out);
        self::assertFalse($out[0]['withBasket'], 'Cesta saltada: ya no es "junto a la cesta"');
        self::assertTrue($out[0]['hasSlot'], 'Pero el día sigue siendo seleccionable → se ve en el panel');
    }

    public function testDiaSinRepartoVaAlAviso(): void
    {
        // La proyección solo tiene reparto otro día: el día del pedido no es celda.
        $out = $this->service([$this->orderOn('2026-07-10')], ['2026-07-17' => false])
            ->upcomingForPartner(new Partner());

        self::assertCount(1, $out);
        self::assertFalse($out[0]['withBasket']);
        self::assertFalse($out[0]['hasSlot'], 'Sin celda que seleccionar → irá al aviso, no al panel');
    }

    public function testPedidoVacioSeIgnora(): void
    {
        $out = $this->service([$this->orderOn('2026-07-10', empty: true)], ['2026-07-10' => false])
            ->upcomingForPartner(new Partner());

        self::assertSame([], $out);
    }
}
