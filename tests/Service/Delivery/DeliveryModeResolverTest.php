<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\DeliveryException;
use App\Entity\Node;
use App\Entity\WeeklyBasket;
use App\Repository\DeliveryExceptionRepository;
use App\Repository\WeeklyBasketRepository;
use App\Service\Delivery\DeliveryModeResolver;
use App\Service\Delivery\NodeDeliveryDate;
use PHPUnit\Framework\TestCase;

/**
 * Unit de la decisión piedra-vs-dibujo POR NODO. Es el árbitro que la batería no
 * ejercitaba (sus aserciones leen siempre de piedra vía NodeDeliverySheet::build), y por
 * eso se coló el bug del cierre que desplaza un nodo biweekly: con la regla GLOBAL el
 * nodo desplazado se leía de su piedra vacía en vez de dibujarse. Aislado con mocks.
 */
class DeliveryModeResolverTest extends TestCase
{
    private function resolver(
        bool $delivers,
        bool $hasStone,
        ?bool $cancelled,
        \DateTime $basketDate,
    ): array {
        $node = $this->createMock(Node::class);
        $basket = $this->createMock(Basket::class);
        $basket->method('getDate')->willReturn($basketDate);

        $nodeDeliveryDate = $this->createMock(NodeDeliveryDate::class);
        $nodeDeliveryDate->method('deliversInBasket')->willReturn($delivers);

        $weeklyBasketRepo = $this->createMock(WeeklyBasketRepository::class);
        $weeklyBasketRepo->method('findForNodeAndBasket')
            ->willReturn($hasStone ? [$this->createMock(WeeklyBasket::class)] : []);

        $exceptionRepo = $this->createMock(DeliveryExceptionRepository::class);
        if ($cancelled === null) {
            $exceptionRepo->method('findGlobalForBasket')->willReturn(null);
        } else {
            $exception = $this->createMock(DeliveryException::class);
            $exception->method('isCancelled')->willReturn($cancelled);
            $exceptionRepo->method('findGlobalForBasket')->willReturn($exception);
        }

        $resolver = new DeliveryModeResolver($nodeDeliveryDate, $weeklyBasketRepo, $exceptionRepo);

        return [$resolver, $node, $basket];
    }

    private function future(): \DateTime
    {
        return new \DateTime('2999-01-01');
    }

    private function past(): \DateTime
    {
        return new \DateTime('2000-01-01');
    }

    public function testNoReparteEsEmpty(): void
    {
        [$resolver, $node, $basket] = $this->resolver(delivers: false, hasStone: false, cancelled: null, basketDate: $this->future());
        self::assertSame(DeliveryModeResolver::EMPTY, $resolver->mode($node, $basket));
    }

    public function testConPiedraEsStone(): void
    {
        [$resolver, $node, $basket] = $this->resolver(delivers: true, hasStone: true, cancelled: null, basketDate: $this->future());
        self::assertSame(DeliveryModeResolver::STONE, $resolver->mode($node, $basket));
    }

    public function testLaPiedraGanaSobreLaCancelacionGlobal(): void
    {
        // Lo que ya está congelado se respeta aunque el día tenga un cierre global.
        [$resolver, $node, $basket] = $this->resolver(delivers: true, hasStone: true, cancelled: true, basketDate: $this->future());
        self::assertSame(DeliveryModeResolver::STONE, $resolver->mode($node, $basket));
    }

    public function testCancelacionGlobalSinPiedraEsEmpty(): void
    {
        [$resolver, $node, $basket] = $this->resolver(delivers: true, hasStone: false, cancelled: true, basketDate: $this->future());
        self::assertSame(DeliveryModeResolver::EMPTY, $resolver->mode($node, $basket));
    }

    public function testPasadoSinPiedraEsEmpty(): void
    {
        [$resolver, $node, $basket] = $this->resolver(delivers: true, hasStone: false, cancelled: null, basketDate: $this->past());
        self::assertSame(DeliveryModeResolver::EMPTY, $resolver->mode($node, $basket));
    }

    public function testReparteFuturoSinPiedraNiCierreEsDraw(): void
    {
        // El caso del bug: el nodo reparte (cadencia desplazada por un cierre) pero no
        // tiene piedra propia esa semana → debe DIBUJARSE, no leerse de piedra vacía.
        [$resolver, $node, $basket] = $this->resolver(delivers: true, hasStone: false, cancelled: false, basketDate: $this->future());
        self::assertSame(DeliveryModeResolver::DRAW, $resolver->mode($node, $basket));
    }
}
