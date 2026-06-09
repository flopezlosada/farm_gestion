<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerEvent;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Entity\WeeklyBasketStatus;
use App\Repository\WeeklyBasketRepository;
use App\Service\Delivery\ExtraBasketEditor;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Delivery\WeeklyBasketComposer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit de la cesta extra puntual: AÑADE cestas/huevos a la entrega de un socio en una
 * semana, creándola si no existía y sumando al total que ya tuviera. Aislado de la BBDD
 * con mocks; lo end-to-end (no duplicar WB, sobrevivir a un regenerado, salir en el
 * listado) lo cubre la batería (app:verify-delivery-battery).
 */
class ExtraBasketEditorTest extends TestCase
{
    private function component(int $id): BasketComponent
    {
        $component = $this->createMock(BasketComponent::class);
        $component->method('getId')->willReturn($id);

        return $component;
    }

    /**
     * Cantidad estampada para un componente dado en las líneas capturadas, o null.
     *
     * @param array<int, array{component: BasketComponent, amount: string}>|null $lines
     */
    private function amountOf(?array $lines, int $componentId): ?string
    {
        foreach ($lines ?? [] as $line) {
            if ($line['component']->getId() === $componentId) {
                return $line['amount'];
            }
        }
        return null;
    }

    /**
     * Socio con nodo (vía su grupo de recogida), para ejercitar el guard per-node de
     * "semana generada para ese nodo".
     */
    private function partnerWithNode(): Partner
    {
        $node = $this->createMock(Node::class);
        $group = $this->createMock(WeeklyBasketGroup::class);
        $group->method('getNode')->willReturn($node);
        $partner = $this->createMock(Partner::class);
        $partner->method('getWeeklyBasketGroup')->willReturn($group);

        return $partner;
    }

    /**
     * Monta el editor con un EntityManager que enruta cada repo por su entidad y
     * recoge lo persistido / el flush.
     *
     * @param WeeklyBasketRepository $wbRepo     Repo de WeeklyBasket (findOneBy + findForNodeAndBasket).
     * @param array<int, object>     &$persisted Recoge lo persistido.
     * @param bool                   &$flushed   Se pone a true al hacer flush.
     */
    private function buildEditor(WeeklyBasketRepository $wbRepo, WeeklyBasketComposer $composer, array &$persisted, bool &$flushed): ExtraBasketEditor
    {
        $componentRepo = $this->createMock(EntityRepository::class);
        $componentRepo->method('find')->willReturnCallback(fn ($id) => $this->component((int) $id));

        $statusRepo = $this->createMock(EntityRepository::class);
        $statusRepo->method('find')->willReturn(new WeeklyBasketStatus());

        $shareRepo = $this->createMock(\App\Repository\PartnerBasketShareRepository::class);
        $shareRepo->method('findActiveForPartner')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(fn (string $class) => match ($class) {
            WeeklyBasket::class => $wbRepo,
            WeeklyBasketStatus::class => $statusRepo,
            \App\Entity\PartnerBasketShare::class => $shareRepo,
            default => $componentRepo,
        });
        $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void {
            $persisted[] = $e;
        });
        $em->method('flush')->willReturnCallback(function () use (&$flushed): void {
            $flushed = true;
        });

        $nodeDeliveryDate = $this->createMock(NodeDeliveryDate::class);

        return new ExtraBasketEditor($em, $composer, $nodeDeliveryDate);
    }

    public function testAddsToExistingDeliverySumsAndRecordsEvent(): void
    {
        $wb = (new WeeklyBasket())->setPartner(new Partner());
        $wbRepo = $this->createMock(WeeklyBasketRepository::class);
        $wbRepo->method('findOneBy')->willReturn($wb);

        $stamped = null;
        $composer = $this->createMock(WeeklyBasketComposer::class);
        // Composición actual: 1 cesta de verdura.
        $composer->method('copyLines')->willReturn([
            ['component' => $this->component(BasketComponent::ID_VEGETABLES), 'amount' => '1.00'],
        ]);
        $composer->method('stamp')->willReturnCallback(function (WeeklyBasket $w, array $lines) use (&$stamped): void {
            $stamped = $lines;
        });

        $persisted = [];
        $flushed = false;
        $editor = $this->buildEditor($wbRepo, $composer, $persisted, $flushed);

        // Se añaden 2 cestas → total 3.
        $result = $editor->addToDelivery(
            new Partner(),
            new Basket(),
            [BasketComponent::ID_VEGETABLES => '2'],
            'dos más',
            'gestor:1',
        );

        $this->assertSame($wb, $result);
        $this->assertSame('3.00', $this->amountOf($stamped, BasketComponent::ID_VEGETABLES));
        $this->assertSame(3, $wb->getAmount());
        $this->assertTrue($flushed);

        $events = array_values(array_filter($persisted, fn ($e) => $e instanceof PartnerEvent));
        $this->assertCount(1, $events);
        $this->assertSame(PartnerEvent::TYPE_BASKET_EXTRA, $events[0]->getType());
    }

    public function testAddingEggsKeepsExistingVegetables(): void
    {
        $wb = (new WeeklyBasket())->setPartner(new Partner());
        $wbRepo = $this->createMock(WeeklyBasketRepository::class);
        $wbRepo->method('findOneBy')->willReturn($wb);

        $stamped = null;
        $composer = $this->createMock(WeeklyBasketComposer::class);
        // Lleva 2 cestas de verdura, sin huevos.
        $composer->method('copyLines')->willReturn([
            ['component' => $this->component(BasketComponent::ID_VEGETABLES), 'amount' => '2.00'],
        ]);
        $composer->method('stamp')->willReturnCallback(function (WeeklyBasket $w, array $lines) use (&$stamped): void {
            $stamped = $lines;
        });

        $persisted = [];
        $flushed = false;
        $editor = $this->buildEditor($wbRepo, $composer, $persisted, $flushed);

        // Se añade 1 docena de huevos: la verdura se conserva.
        $editor->addToDelivery(new Partner(), new Basket(), [BasketComponent::ID_EGGS => '1'], null, 'gestor:1');

        $this->assertSame('2.00', $this->amountOf($stamped, BasketComponent::ID_VEGETABLES));
        $this->assertSame('1.00', $this->amountOf($stamped, BasketComponent::ID_EGGS));
        $this->assertSame(2, $wb->getAmount());
    }

    public function testCreatesDeliveryWhenWeekGeneratedAndPartnerHadNone(): void
    {
        $wbRepo = $this->createMock(WeeklyBasketRepository::class);
        // El socio no tiene entrega (no le tocaba), pero su NODO sí tiene reparto generado
        // esa semana (findForNodeAndBasket no vacío) → es seguro crear su entrega.
        $wbRepo->method('findOneBy')->willReturn(null);
        $wbRepo->method('findForNodeAndBasket')->willReturn([new WeeklyBasket()]);

        $stamped = null;
        $composer = $this->createMock(WeeklyBasketComposer::class);
        // No hay entrega previa: copyLines no debe consultarse.
        $composer->expects($this->never())->method('copyLines');
        $composer->method('stamp')->willReturnCallback(function (WeeklyBasket $w, array $lines) use (&$stamped): void {
            $stamped = $lines;
        });

        $persisted = [];
        $flushed = false;
        $editor = $this->buildEditor($wbRepo, $composer, $persisted, $flushed);

        $result = $editor->addToDelivery(
            $this->partnerWithNode(),
            new Basket(),
            [BasketComponent::ID_VEGETABLES => '1'],
            null,
            'gestor:1',
        );

        $this->assertInstanceOf(WeeklyBasket::class, $result);
        $this->assertSame('1.00', $this->amountOf($stamped, BasketComponent::ID_VEGETABLES));
        $this->assertSame(1, $result->getAmount());
        $this->assertContains($result, $persisted);
        $this->assertTrue($flushed);
    }

    public function testThrowsWhenNodeWeekNotGenerated(): void
    {
        $wbRepo = $this->createMock(WeeklyBasketRepository::class);
        // El socio no tiene entrega y el reparto del NODO de ese socio NO está generado esa
        // semana (findForNodeAndBasket vacío): crear aquí dejaría piedra parcial → rechaza.
        $wbRepo->method('findOneBy')->willReturn(null);
        $wbRepo->method('findForNodeAndBasket')->willReturn([]);

        $composer = $this->createMock(WeeklyBasketComposer::class);
        $composer->expects($this->never())->method('stamp');

        $persisted = [];
        $flushed = false;
        $editor = $this->buildEditor($wbRepo, $composer, $persisted, $flushed);

        $this->expectException(\LogicException::class);
        $editor->addToDelivery($this->partnerWithNode(), new Basket(), [BasketComponent::ID_VEGETABLES => '1'], null, 'gestor:1');
    }
}
