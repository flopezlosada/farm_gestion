<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerEvent;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Delivery\PickupRelocator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit del traslado puntual de lugar: mueve la entrega de una semana a otro grupo/nodo,
 * recalcula su fecha física y registra el evento. Aislado de la BBDD con mocks; lo
 * end-to-end (sale en el listado del destino, no en el del origen, sobrevive a un
 * regenerado) lo cubre la batería.
 */
class PickupRelocatorTest extends TestCase
{
    private function group(int $id, ?Node $node = null): WeeklyBasketGroup
    {
        $group = $this->createMock(WeeklyBasketGroup::class);
        $group->method('getId')->willReturn($id);
        $group->method('getNode')->willReturn($node);
        $group->method('getName')->willReturn('Grupo ' . $id);

        return $group;
    }

    /**
     * @param bool                    $delivers  Si el nodo destino reparte esa semana.
     * @param array<int, object>      &$persisted
     * @param bool                    &$flushed
     */
    private function buildRelocator(?WeeklyBasket $wb, bool $delivers, array &$persisted, bool &$flushed): PickupRelocator
    {
        $wbRepo = $this->createMock(EntityRepository::class);
        $wbRepo->method('findOneBy')->willReturn($wb);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($wbRepo);
        $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void {
            $persisted[] = $e;
        });
        $em->method('flush')->willReturnCallback(function () use (&$flushed): void {
            $flushed = true;
        });

        $nodeDeliveryDate = $this->createMock(NodeDeliveryDate::class);
        $nodeDeliveryDate->method('deliversInBasket')->willReturn($delivers);
        $nodeDeliveryDate->method('physicalDateFor')->willReturn(new \DateTimeImmutable('2026-06-19'));

        return new PickupRelocator($em, $nodeDeliveryDate);
    }

    public function testRelocatesGroupRecalculatesDateAndRecordsEvent(): void
    {
        $wb = (new WeeklyBasket())->setPartner(new Partner())->setWeeklyBasketGroup($this->group(10));
        $destNode = $this->createMock(Node::class);
        $destNode->method('getId')->willReturn(2);
        $toGroup = $this->group(20, $destNode);

        $persisted = [];
        $flushed = false;
        $relocator = $this->buildRelocator($wb, true, $persisted, $flushed);

        $result = $relocator->relocate(new Partner(), new Basket(), $toGroup, 'gestor:1');

        $this->assertSame($toGroup, $result->getWeeklyBasketGroup());
        $this->assertSame('2026-06-19', $result->getDeliveryDate()?->format('Y-m-d'));
        $this->assertTrue($flushed);

        $events = array_values(array_filter($persisted, fn ($e) => $e instanceof PartnerEvent));
        $this->assertCount(1, $events);
        $this->assertSame(PartnerEvent::TYPE_NODE_CHANGE, $events[0]->getType());
        $this->assertSame('one_off', $events[0]->getPayload()['scope']);
        $this->assertSame(20, $events[0]->getPayload()['to_group_id']);
    }

    public function testThrowsWhenPartnerHasNoDeliveryThatWeek(): void
    {
        $persisted = [];
        $flushed = false;
        $relocator = $this->buildRelocator(null, true, $persisted, $flushed);

        $this->expectException(\LogicException::class);
        $relocator->relocate(new Partner(), new Basket(), $this->group(20, $this->createMock(Node::class)), 'gestor:1');
    }

    public function testThrowsWhenDestinationDoesNotDeliverThatWeek(): void
    {
        $wb = (new WeeklyBasket())->setPartner(new Partner())->setWeeklyBasketGroup($this->group(10));
        $destNode = $this->createMock(Node::class);
        $destNode->method('getId')->willReturn(2);

        $persisted = [];
        $flushed = false;
        $relocator = $this->buildRelocator($wb, false, $persisted, $flushed);

        $this->expectException(\LogicException::class);
        $relocator->relocate(new Partner(), new Basket(), $this->group(20, $destNode), 'gestor:1');
    }

    public function testThrowsWhenAlreadyInThatGroup(): void
    {
        $wb = (new WeeklyBasket())->setPartner(new Partner())->setWeeklyBasketGroup($this->group(20));

        $persisted = [];
        $flushed = false;
        $relocator = $this->buildRelocator($wb, true, $persisted, $flushed);

        // Mismo id de grupo (20) → no tiene sentido trasladar.
        $this->expectException(\LogicException::class);
        $relocator->relocate(new Partner(), new Basket(), $this->group(20, $this->createMock(Node::class)), 'gestor:1');
    }
}
