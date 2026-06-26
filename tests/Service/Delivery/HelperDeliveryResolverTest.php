<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Helper;
use App\Entity\Node;
use App\Entity\Stay;
use App\Repository\HelperBasketSkipRepository;
use App\Repository\StayRepository;
use App\Service\Delivery\HelperDeliveryResolver;
use App\Service\Delivery\NodeDeliveryDate;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del corazón de la cesta del voluntario: a partir de la fecha física
 * del nodo, las estancias confirmadas que cubren ese día y los "no recoge",
 * decide qué voluntarios salen en el listado. Repos y NodeDeliveryDate mockeados.
 */
class HelperDeliveryResolverTest extends TestCase
{
    private const DELIVERY_DATE = '2026-06-19';

    /**
     * Caso normal: Torremocha reparte ese día, un voluntario con cesta activa y
     * estancia confirmada que cubre el día → una línea isHelper con sus cantidades.
     * Y como el nodo es el por defecto, la consulta incluye helpers sin nodo.
     */
    public function testActiveHelperWithCoveringStayProducesLine(): void
    {
        $node = $this->node('Torremocha');
        $basket = new Basket();
        $date = new \DateTimeImmutable(self::DELIVERY_DATE);

        $nodeDate = $this->createMock(NodeDeliveryDate::class);
        $nodeDate->method('physicalDateFor')->with($basket, $node)->willReturn($date);

        $stayRepo = $this->createMock(StayRepository::class);
        $stayRepo->expects(self::once())
            ->method('findConfirmedDeliveringOn')
            ->with($date, $node, true) // Torremocha = nodo por defecto → incluye basketNode null
            ->willReturn([$this->stayFor($this->helper(7, 'Germán', 1, 1.0))]);

        $skipRepo = $this->createMock(HelperBasketSkipRepository::class);
        $skipRepo->method('helperIdsSkippingDate')->with([7], $date)->willReturn([]);

        $lines = $this->resolver($nodeDate, $stayRepo, $skipRepo)->forNodeAndBasket($node, $basket);

        self::assertCount(1, $lines);
        self::assertTrue($lines[0]->isHelper);
        self::assertSame('Germán', $lines[0]->nameForDelivery);
        self::assertNull($lines[0]->basketShareId);
        self::assertSame(1.0, $lines[0]->cestas);
        self::assertSame(1.0, $lines[0]->dozens);
        self::assertTrue($lines[0]->subscribedToEggs);
    }

    /**
     * El nodo no reparte esa semana (quincenal fuera de fase o excepción que
     * cancela): physicalDateFor null → no se consulta nada y se devuelve vacío.
     */
    public function testNodeNotDeliveringReturnsEmpty(): void
    {
        $node = $this->node('Torremocha');
        $basket = new Basket();

        $nodeDate = $this->createMock(NodeDeliveryDate::class);
        $nodeDate->method('physicalDateFor')->willReturn(null);

        $stayRepo = $this->createMock(StayRepository::class);
        $stayRepo->expects(self::never())->method('findConfirmedDeliveringOn');

        $skipRepo = $this->createMock(HelperBasketSkipRepository::class);

        $lines = $this->resolver($nodeDate, $stayRepo, $skipRepo)->forNodeAndBasket($node, $basket);

        self::assertSame([], $lines);
    }

    /**
     * Ningún voluntario tiene estancia confirmada que cubra el día → vacío.
     */
    public function testNoCoveringStayReturnsEmpty(): void
    {
        $node = $this->node('Torremocha');
        $basket = new Basket();
        $date = new \DateTimeImmutable(self::DELIVERY_DATE);

        $nodeDate = $this->createMock(NodeDeliveryDate::class);
        $nodeDate->method('physicalDateFor')->willReturn($date);

        $stayRepo = $this->createMock(StayRepository::class);
        $stayRepo->method('findConfirmedDeliveringOn')->willReturn([]);

        $skipRepo = $this->createMock(HelperBasketSkipRepository::class);

        $lines = $this->resolver($nodeDate, $stayRepo, $skipRepo)->forNodeAndBasket($node, $basket);

        self::assertSame([], $lines);
    }

    /**
     * El voluntario tiene un "no recoge" esa fecha → se excluye del listado.
     */
    public function testSkippedHelperIsExcluded(): void
    {
        $node = $this->node('Torremocha');
        $basket = new Basket();
        $date = new \DateTimeImmutable(self::DELIVERY_DATE);

        $nodeDate = $this->createMock(NodeDeliveryDate::class);
        $nodeDate->method('physicalDateFor')->willReturn($date);

        $stayRepo = $this->createMock(StayRepository::class);
        $stayRepo->method('findConfirmedDeliveringOn')->willReturn([$this->stayFor($this->helper(7, 'Germán', 1, 1.0))]);

        $skipRepo = $this->createMock(HelperBasketSkipRepository::class);
        $skipRepo->method('helperIdsSkippingDate')->willReturn([7]); // 7 salta

        $lines = $this->resolver($nodeDate, $stayRepo, $skipRepo)->forNodeAndBasket($node, $basket);

        self::assertSame([], $lines);
    }

    /**
     * Un nodo que NO es el por defecto NO recoge a los voluntarios con basketNode
     * null (esos van a Torremocha): se consulta con includeNullNode=false.
     */
    public function testNonDefaultNodeExcludesNullNodeHelpers(): void
    {
        $node = $this->node('Cascorro');
        $basket = new Basket();
        $date = new \DateTimeImmutable(self::DELIVERY_DATE);

        $nodeDate = $this->createMock(NodeDeliveryDate::class);
        $nodeDate->method('physicalDateFor')->willReturn($date);

        $stayRepo = $this->createMock(StayRepository::class);
        $stayRepo->expects(self::once())
            ->method('findConfirmedDeliveringOn')
            ->with($date, $node, false)
            ->willReturn([]);

        $skipRepo = $this->createMock(HelperBasketSkipRepository::class);

        $this->resolver($nodeDate, $stayRepo, $skipRepo)->forNodeAndBasket($node, $basket);
    }

    /**
     * Un voluntario con dos estancias confirmadas que (defensivamente) solapan el
     * mismo día sale una sola vez.
     */
    public function testHelperWithTwoStaysIsNotDuplicated(): void
    {
        $node = $this->node('Torremocha');
        $basket = new Basket();
        $date = new \DateTimeImmutable(self::DELIVERY_DATE);
        $helper = $this->helper(7, 'Germán', 1, 1.0);

        $nodeDate = $this->createMock(NodeDeliveryDate::class);
        $nodeDate->method('physicalDateFor')->willReturn($date);

        $stayRepo = $this->createMock(StayRepository::class);
        $stayRepo->method('findConfirmedDeliveringOn')->willReturn([
            $this->stayFor($helper),
            $this->stayFor($helper),
        ]);

        $skipRepo = $this->createMock(HelperBasketSkipRepository::class);
        $skipRepo->method('helperIdsSkippingDate')->willReturn([]);

        $lines = $this->resolver($nodeDate, $stayRepo, $skipRepo)->forNodeAndBasket($node, $basket);

        self::assertCount(1, $lines);
    }

    /**
     * Voluntario con 0 docenas de huevos: la línea no marca suscrito a huevos y
     * dozens es 0 (solo verdura).
     */
    public function testHelperWithoutEggs(): void
    {
        $node = $this->node('Torremocha');
        $basket = new Basket();
        $date = new \DateTimeImmutable(self::DELIVERY_DATE);

        $nodeDate = $this->createMock(NodeDeliveryDate::class);
        $nodeDate->method('physicalDateFor')->willReturn($date);

        $stayRepo = $this->createMock(StayRepository::class);
        $stayRepo->method('findConfirmedDeliveringOn')->willReturn([$this->stayFor($this->helper(9, 'Marie', 2, 0.0))]);

        $skipRepo = $this->createMock(HelperBasketSkipRepository::class);
        $skipRepo->method('helperIdsSkippingDate')->willReturn([]);

        $lines = $this->resolver($nodeDate, $stayRepo, $skipRepo)->forNodeAndBasket($node, $basket);

        self::assertCount(1, $lines);
        self::assertSame(2.0, $lines[0]->cestas);
        self::assertSame(0.0, $lines[0]->dozens);
        self::assertFalse($lines[0]->subscribedToEggs);
    }

    private function resolver(
        NodeDeliveryDate $nodeDate,
        StayRepository $stayRepo,
        HelperBasketSkipRepository $skipRepo,
    ): HelperDeliveryResolver {
        return new HelperDeliveryResolver($nodeDate, $stayRepo, $skipRepo);
    }

    /**
     * Nodo mockeado con su nombre (lo demás lo resuelve el NodeDeliveryDate mock).
     */
    private function node(string $name): Node
    {
        $node = $this->createMock(Node::class);
        $node->method('getName')->willReturn($name);

        return $node;
    }

    /**
     * Voluntario mockeado con id, nombre y config de cesta.
     */
    private function helper(int $id, string $name, int $veg, float $eggs): Helper
    {
        $helper = $this->createMock(Helper::class);
        $helper->method('getId')->willReturn($id);
        $helper->method('getName')->willReturn($name);
        $helper->method('getBasketVegBaskets')->willReturn($veg);
        $helper->method('getBasketEggDozens')->willReturn($eggs);

        return $helper;
    }

    /**
     * Estancia mockeada que devuelve el voluntario dado (el resolver sólo lee getHelper()).
     */
    private function stayFor(Helper $helper): Stay
    {
        $stay = $this->createMock(Stay::class);
        $stay->method('getHelper')->willReturn($helper);

        return $stay;
    }
}
