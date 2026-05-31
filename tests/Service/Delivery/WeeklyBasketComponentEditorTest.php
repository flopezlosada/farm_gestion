<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketItem;
use App\Service\Delivery\WeeklyBasketComponentEditor;
use App\Service\Delivery\WeeklyBasketComposer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit del toggle de componente (verdura/huevos) de una entrega. Lógica de
 * quitar/añadir aislada de la BBDD; las cantidades al añadir las dicta el
 * patrón vía WeeklyBasketComposer::computeItems (aquí mockeado).
 */
class WeeklyBasketComponentEditorTest extends TestCase
{
    private function component(int $id): BasketComponent
    {
        $component = $this->createMock(BasketComponent::class);
        $component->method('getId')->willReturn($id);

        return $component;
    }

    private function weeklyBasket(): WeeklyBasket
    {
        return (new WeeklyBasket())
            ->setPartnerBasketShare(new PartnerBasketShare())
            ->setBasket(new Basket());
    }

    public function testRemovesExistingComponent(): void
    {
        $itemRepo = $this->createMock(EntityRepository::class);
        $itemRepo->method('findOneBy')->willReturn(new WeeklyBasketItem());

        $removed = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($itemRepo);
        $em->method('remove')->willReturnCallback(function (object $e) use (&$removed): void {
            $removed[] = $e;
        });

        $composer = $this->createMock(WeeklyBasketComposer::class);
        $composer->expects($this->never())->method('computeItems');

        $result = (new WeeklyBasketComponentEditor($em, $composer))->toggle($this->weeklyBasket(), BasketComponent::ID_VEGETABLES);

        $this->assertSame('removed', $result);
        $this->assertCount(1, $removed);
    }

    public function testAddsComponentFromPattern(): void
    {
        $itemRepo = $this->createMock(EntityRepository::class);
        $itemRepo->method('findOneBy')->willReturn(null);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($itemRepo);
        $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void {
            $persisted[] = $e;
        });

        $composer = $this->createMock(WeeklyBasketComposer::class);
        $composer->method('computeItems')->willReturn([
            ['component' => $this->component(BasketComponent::ID_EGGS), 'amount' => '1.00'],
        ]);

        $result = (new WeeklyBasketComponentEditor($em, $composer))->toggle($this->weeklyBasket(), BasketComponent::ID_EGGS);

        $this->assertSame('added', $result);
        $this->assertCount(1, $persisted);
        $this->assertInstanceOf(WeeklyBasketItem::class, $persisted[0]);
    }

    public function testUnavailableWhenPatternHasNoSuchComponent(): void
    {
        $itemRepo = $this->createMock(EntityRepository::class);
        $itemRepo->method('findOneBy')->willReturn(null);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($itemRepo);
        $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void {
            $persisted[] = $e;
        });

        $composer = $this->createMock(WeeklyBasketComposer::class);
        // El patrón solo da verdura; pedir huevos no es posible sin tocar el derecho.
        $composer->method('computeItems')->willReturn([
            ['component' => $this->component(BasketComponent::ID_VEGETABLES), 'amount' => '1.00'],
        ]);

        $result = (new WeeklyBasketComponentEditor($em, $composer))->toggle($this->weeklyBasket(), BasketComponent::ID_EGGS);

        $this->assertSame('unavailable', $result);
        $this->assertCount(0, $persisted);
    }
}
