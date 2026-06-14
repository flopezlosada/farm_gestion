<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerEvent;
use App\Entity\PartnerNodeOverride;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Repository\PartnerDeliveryShiftRepository;
use App\Repository\PartnerNodeOverrideRepository;
use App\Repository\WeeklyBasketRepository;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Delivery\PickupRelocator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit del traslado puntual de lugar, ya modelado como OVERRIDE (PartnerNodeOverride):
 * crea/re-apunta el override de esa semana, cascadea a piedra SOLO si la semana ya está
 * materializada (mueve el WeeklyBasket), y funciona también en semanas dibujadas (sin WB).
 * Aislado de la BBDD con mocks; lo end-to-end (sale en el dibujo/piedra del destino, no en
 * el origen) lo cubre la batería.
 */
class PickupRelocatorTest extends TestCase
{
    private function node(int $id): Node
    {
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn($id);

        return $node;
    }

    private function group(int $id, ?Node $node = null): WeeklyBasketGroup
    {
        $group = $this->createMock(WeeklyBasketGroup::class);
        $group->method('getId')->willReturn($id);
        $group->method('getNode')->willReturn($node);
        $group->method('getName')->willReturn('Grupo ' . $id);

        return $group;
    }

    /**
     * @param WeeklyBasket|null        $wb               Entrega del socio esa semana (null = semana dibujada).
     * @param bool                     $delivers         Si el nodo destino reparte esa semana.
     * @param PartnerNodeOverride|null $existingOverride Override previo del socio esa semana.
     * @param array<int, object>       &$persisted
     * @param bool                     &$flushed
     * @param array<int, object>       &$removed         Capturados por em->remove (vuelta al patrón).
     */
    private function buildRelocator(?WeeklyBasket $wb, bool $delivers, ?PartnerNodeOverride $existingOverride, array &$persisted, bool &$flushed, array &$removed = [], ?PartnerDeliveryShift $outgoingShift = null): PickupRelocator
    {
        $wbRepo = $this->createMock(WeeklyBasketRepository::class);
        $wbRepo->method('findOneBy')->willReturn($wb);

        $overrideRepo = $this->createMock(PartnerNodeOverrideRepository::class);
        $overrideRepo->method('findForPartnerAndBasket')->willReturn($existingOverride);

        // Cambio de día/"no recoge" saliente de esa semana (guard de exclusión relocate↔move).
        $shiftRepo = $this->createMock(PartnerDeliveryShiftRepository::class);
        $shiftRepo->method('findOutgoing')->willReturn($outgoingShift);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(fn (string $class) => match ($class) {
            PartnerNodeOverride::class => $overrideRepo,
            PartnerDeliveryShift::class => $shiftRepo,
            default => $wbRepo,
        });
        $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void {
            $persisted[] = $e;
        });
        $em->method('remove')->willReturnCallback(function (object $e) use (&$removed): void {
            $removed[] = $e;
        });
        $em->method('flush')->willReturnCallback(function () use (&$flushed): void {
            $flushed = true;
        });

        $nodeDeliveryDate = $this->createMock(NodeDeliveryDate::class);
        $nodeDeliveryDate->method('deliversInBasket')->willReturn($delivers);
        $nodeDeliveryDate->method('physicalDateFor')->willReturn(new \DateTimeImmutable('2026-06-19'));

        return new PickupRelocator($em, $nodeDeliveryDate);
    }

    public function testCreatesOverrideAndMovesStoneWhenGenerated(): void
    {
        // Semana ya materializada: hay WeeklyBasket → se crea el override Y se mueve la piedra.
        $wb = (new WeeklyBasket())->setPartner(new Partner())->setWeeklyBasketGroup($this->group(10));
        $toGroup = $this->group(20, $this->node(2));

        $persisted = [];
        $flushed = false;
        $relocator = $this->buildRelocator($wb, true, null, $persisted, $flushed);

        $result = $relocator->relocate(new Partner(), new Basket(), $toGroup, 'gestor:1');

        $this->assertInstanceOf(PartnerNodeOverride::class, $result);
        $this->assertSame($toGroup, $result->getTargetGroup());
        // La piedra se movió al grupo destino y se recalculó la fecha.
        $this->assertSame($toGroup, $wb->getWeeklyBasketGroup());
        $this->assertSame('2026-06-19', $wb->getDeliveryDate()?->format('Y-m-d'));
        $this->assertTrue($flushed);

        $overrides = array_values(array_filter($persisted, fn ($e) => $e instanceof PartnerNodeOverride));
        $this->assertCount(1, $overrides);
        $events = array_values(array_filter($persisted, fn ($e) => $e instanceof PartnerEvent));
        $this->assertCount(1, $events);
        $this->assertSame(PartnerEvent::TYPE_NODE_CHANGE, $events[0]->getType());
    }

    public function testCreatesOverrideWithoutStoneWhenDrawn(): void
    {
        // Semana dibujada (sin WeeklyBasket): se crea el override y NO se toca piedra. Es la
        // capacidad nueva (antes exigía cesta materializada que mover).
        $toGroup = $this->group(20, $this->node(2));

        $persisted = [];
        $flushed = false;
        $relocator = $this->buildRelocator(null, true, null, $persisted, $flushed);

        $result = $relocator->relocate(new Partner(), new Basket(), $toGroup, 'gestor:1');

        $this->assertInstanceOf(PartnerNodeOverride::class, $result);
        $this->assertSame($toGroup, $result->getTargetGroup());
        $this->assertTrue($flushed);
        $this->assertCount(1, array_filter($persisted, fn ($e) => $e instanceof PartnerNodeOverride));
    }

    public function testReuseExistingOverrideRepointsToNewGroup(): void
    {
        // Ya tenía un override a OTRO grupo: se re-apunta (no se crea uno nuevo).
        $oldGroup = $this->group(30, $this->node(3));
        $existing = new PartnerNodeOverride(new Partner(), new Basket(), $oldGroup);
        $toGroup = $this->group(20, $this->node(2));

        $persisted = [];
        $flushed = false;
        $relocator = $this->buildRelocator(null, true, $existing, $persisted, $flushed);

        $result = $relocator->relocate(new Partner(), new Basket(), $toGroup, 'gestor:1');

        $this->assertSame($existing, $result);
        $this->assertSame($toGroup, $existing->getTargetGroup());
        // No se persiste un override nuevo (se reusa el existente).
        $this->assertCount(0, array_filter($persisted, fn ($e) => $e instanceof PartnerNodeOverride));
    }

    public function testThrowsWhenDestinationDoesNotDeliverThatWeek(): void
    {
        $toGroup = $this->group(20, $this->node(2));
        $persisted = [];
        $flushed = false;
        $relocator = $this->buildRelocator(null, false, null, $persisted, $flushed);

        $this->expectException(\LogicException::class);
        $relocator->relocate(new Partner(), new Basket(), $toGroup, 'gestor:1');
    }

    public function testThrowsWhenWeekHasOutgoingDayChange(): void
    {
        // Exclusión relocate↔move: si esa semana tiene un cambio de día / "no recoge"
        // (PartnerDeliveryShift de entrega entera saliente), no se puede trasladar de nodo
        // a la vez (son contradictorios). El guard simétrico vive en DeliveryShiftApplier::move.
        $toGroup = $this->group(20, $this->node(2));
        $shift = $this->createMock(PartnerDeliveryShift::class);

        $persisted = [];
        $flushed = false;
        $removed = [];
        $relocator = $this->buildRelocator(null, true, null, $persisted, $flushed, $removed, $shift);

        $this->expectException(\LogicException::class);
        $relocator->relocate(new Partner(), new Basket(), $toGroup, 'gestor:1');
    }

    public function testThrowsWhenPartnerSharesBasket(): void
    {
        // Las cestas COMPARTIDAS (alternancia entre dos hogares) no se trasladan de nodo de
        // momento: el guard salta antes de tocar nada (sin definir cómo afecta al otro hogar).
        $partner = $this->createMock(Partner::class);
        $partner->method('getSharePartner')->willReturn($this->createMock(Partner::class));
        $toGroup = $this->group(20, $this->node(2));

        $persisted = [];
        $flushed = false;
        $relocator = $this->buildRelocator(null, true, null, $persisted, $flushed);

        $this->expectException(\LogicException::class);
        $relocator->relocate($partner, new Basket(), $toGroup, 'gestor:1');
    }

    public function testRelocatingToOwnNodeVuelveAlPatron(): void
    {
        // El grupo destino es de SU propio nodo: no es un traslado, es VOLVER al patrón.
        // relocate() delega en returnHome → borra el override existente, revierte la piedra
        // al grupo de CASA real (no al representativo que llega en $toGroup) y devuelve null.
        $destNode = $this->node(2);
        $home = $this->group(10, $destNode);
        $partner = $this->createMock(Partner::class);
        $partner->method('getWeeklyBasketGroup')->willReturn($home);
        $toGroup = $this->group(20, $destNode); // grupo representativo del propio nodo

        $existing = new PartnerNodeOverride($partner, new Basket(), $this->group(30, $this->node(3)));
        $wb = (new WeeklyBasket())->setPartner($partner)->setWeeklyBasketGroup($this->group(30, $this->node(3)));

        $persisted = [];
        $flushed = false;
        $removed = [];
        $relocator = $this->buildRelocator($wb, true, $existing, $persisted, $flushed, $removed);

        $result = $relocator->relocate($partner, new Basket(), $toGroup, 'gestor:1');

        $this->assertNull($result);
        $this->assertSame([$existing], $removed);
        $this->assertSame($home, $wb->getWeeklyBasketGroup()); // piedra revertida a casa, no a $toGroup
        $this->assertTrue($flushed);
    }

    public function testReturnHomeRemovesOverrideAndRevertsStone(): void
    {
        // Vuelta directa al nodo de casa: borra el override y, como hay piedra, devuelve el
        // WeeklyBasket a su grupo de casa con la fecha física del nodo de casa.
        $home = $this->group(10, $this->node(1));
        $partner = $this->createMock(Partner::class);
        $partner->method('getWeeklyBasketGroup')->willReturn($home);

        $existing = new PartnerNodeOverride($partner, new Basket(), $this->group(20, $this->node(2)));
        $wb = (new WeeklyBasket())->setPartner($partner)->setWeeklyBasketGroup($this->group(20, $this->node(2)));

        $persisted = [];
        $flushed = false;
        $removed = [];
        $relocator = $this->buildRelocator($wb, true, $existing, $persisted, $flushed, $removed);

        $relocator->returnHome($partner, new Basket(), 'gestor:1');

        $this->assertSame([$existing], $removed);
        $this->assertSame($home, $wb->getWeeklyBasketGroup());
        $this->assertSame('2026-06-19', $wb->getDeliveryDate()?->format('Y-m-d'));
        $this->assertTrue($flushed);
        $events = array_values(array_filter($persisted, fn ($e) => $e instanceof PartnerEvent));
        $this->assertCount(1, $events);
        $this->assertSame(PartnerEvent::TYPE_NODE_CHANGE, $events[0]->getType());
    }

    public function testReturnHomeNoopWhenNoOverride(): void
    {
        // Sin override esa semana no hay traslado que deshacer: no borra ni registra nada.
        $partner = $this->createMock(Partner::class);
        $partner->method('getWeeklyBasketGroup')->willReturn($this->group(10, $this->node(1)));

        $persisted = [];
        $flushed = false;
        $removed = [];
        $relocator = $this->buildRelocator(null, true, null, $persisted, $flushed, $removed);

        $relocator->returnHome($partner, new Basket(), 'gestor:1');

        $this->assertSame([], $removed);
        $this->assertSame([], $persisted);
    }

    public function testThrowsWhenAlreadyOverriddenToThatGroup(): void
    {
        $toGroup = $this->group(20, $this->node(2));
        $existing = new PartnerNodeOverride(new Partner(), new Basket(), $toGroup);

        $persisted = [];
        $flushed = false;
        $relocator = $this->buildRelocator(null, true, $existing, $persisted, $flushed);

        $this->expectException(\LogicException::class);
        $relocator->relocate(new Partner(), new Basket(), $toGroup, 'gestor:1');
    }
}
