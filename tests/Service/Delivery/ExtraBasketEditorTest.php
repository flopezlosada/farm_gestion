<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\BasketShare;
use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\PartnerBasketExtra;
use App\Entity\PartnerEvent;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketGroup;
use App\Entity\WeeklyBasketStatus;
use App\Repository\PartnerBasketExtraRepository;
use App\Repository\WeeklyBasketRepository;
use App\Service\Delivery\ExtraBasketEditor;
use App\Service\Delivery\NodeDeliveryDate;
use App\Service\Delivery\WeeklyBasketComposer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit de la cesta extra puntual, ya modelada como OVERRIDE (PartnerBasketExtra): guarda
 * el delta como override (acumulando) y cascadea a la piedra (vía WeeklyBasketComposer::addAmounts)
 * SOLO si el reparto del nodo del socio ya está generado; en una semana DIBUJADA basta el
 * override, que la proyección suma al dibujar (sin tocar piedra ni lanzar error). Aislado de la
 * BBDD con mocks; la suma fina de líneas se prueba en WeeklyBasketComposerTest::testAddAmounts*,
 * y lo end-to-end (sale en dibujo y piedra) en la batería.
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
     * Socio con nodo (vía su grupo de recogida), para ejercitar la decisión "semana
     * generada para ese nodo" vs "dibujada".
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
     * Monta el editor con un EntityManager que enruta cada repo por su entidad y recoge lo
     * persistido / el flush.
     *
     * @param WeeklyBasketRepository   $wbRepo           Repo de WeeklyBasket (findOneBy + findForNodeAndBasket).
     * @param WeeklyBasketComposer     $composer
     * @param PartnerBasketExtra|null  $existingOverride Override previo del componente (acumulación), o null.
     * @param array<int, object>       &$persisted       Recoge lo persistido.
     * @param bool                     &$flushed         Se pone a true al hacer flush.
     * @param PartnerBasketExtra[]     $existingExtras   Overrides existentes (findForPartnerAndBasket / hasExtra / removeExtra).
     * @param array<int, object>       &$removed         Recoge lo borrado (em->remove).
     */
    private function buildEditor(
        WeeklyBasketRepository $wbRepo,
        WeeklyBasketComposer $composer,
        ?PartnerBasketExtra $existingOverride,
        array &$persisted,
        bool &$flushed,
        array $existingExtras = [],
        array &$removed = [],
    ): ExtraBasketEditor {
        $componentRepo = $this->createMock(EntityRepository::class);
        $componentRepo->method('find')->willReturnCallback(fn ($id) => $this->component((int) $id));

        $statusRepo = $this->createMock(EntityRepository::class);
        $statusRepo->method('find')->willReturn(new WeeklyBasketStatus());

        $shareRepo = $this->createMock(\App\Repository\PartnerBasketShareRepository::class);
        $shareRepo->method('findActiveForPartner')->willReturn(null);

        $extraRepo = $this->createMock(PartnerBasketExtraRepository::class);
        $extraRepo->method('findOneForPartnerBasketComponent')->willReturn($existingOverride);
        $extraRepo->method('findForPartnerAndBasket')->willReturn($existingExtras);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(fn (string $class) => match ($class) {
            WeeklyBasket::class => $wbRepo,
            WeeklyBasketStatus::class => $statusRepo,
            PartnerBasketExtra::class => $extraRepo,
            \App\Entity\PartnerBasketShare::class => $shareRepo,
            default => $componentRepo,
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

        return new ExtraBasketEditor($em, $composer, $nodeDeliveryDate);
    }

    /**
     * Override de cesta extra de prueba para un componente con una cantidad dada.
     */
    private function override(int $componentId, string $amount): PartnerBasketExtra
    {
        return new PartnerBasketExtra(new Partner(), new Basket(), $this->component($componentId), $amount);
    }

    /**
     * @param object[] $persisted
     * @return PartnerBasketExtra[]
     */
    private function overridesIn(array $persisted): array
    {
        return array_values(array_filter($persisted, static fn ($e) => $e instanceof PartnerBasketExtra));
    }

    public function testStoresOverrideAndCascadesWhenWeekGenerated(): void
    {
        // Semana generada (el socio ya tiene entrega): guarda el override Y cascadea a piedra
        // con el delta (la suma fina la hace el composer, aquí solo verificamos la delegación).
        $wb = (new WeeklyBasket())->setPartner(new Partner());
        $wbRepo = $this->createMock(WeeklyBasketRepository::class);
        $wbRepo->method('findOneBy')->willReturn($wb);

        $captured = null;
        $composer = $this->createMock(WeeklyBasketComposer::class);
        $composer->method('addAmounts')->willReturnCallback(function (WeeklyBasket $w, array $deltas) use (&$captured): void {
            $captured = $deltas;
        });

        $persisted = [];
        $flushed = false;
        $editor = $this->buildEditor($wbRepo, $composer, null, $persisted, $flushed);

        $editor->addToDelivery(new Partner(), new Basket(), [BasketComponent::ID_VEGETABLES => '2'], 'dos más', 'gestor:1');

        $overrides = $this->overridesIn($persisted);
        $this->assertCount(1, $overrides);
        $this->assertSame('2.00', $overrides[0]->getAmount());
        // Cascada a piedra con el incremento (no el total).
        $this->assertSame([BasketComponent::ID_VEGETABLES => 2.0], $captured);
        $this->assertTrue($flushed);

        $events = array_values(array_filter($persisted, fn ($e) => $e instanceof PartnerEvent));
        $this->assertCount(1, $events);
        $this->assertSame(PartnerEvent::TYPE_BASKET_EXTRA, $events[0]->getType());
    }

    public function testStoresOnlyOverrideWhenWeekDrawn(): void
    {
        // Semana dibujada (sin entrega del socio y el nodo aún sin generar): solo el override,
        // SIN cascada a piedra y SIN error. La proyección lo sumará al dibujar.
        $wbRepo = $this->createMock(WeeklyBasketRepository::class);
        $wbRepo->method('findOneBy')->willReturn(null);
        $wbRepo->method('findForNodeAndBasket')->willReturn([]);

        $composer = $this->createMock(WeeklyBasketComposer::class);
        $composer->expects($this->never())->method('addAmounts');

        $persisted = [];
        $flushed = false;
        $editor = $this->buildEditor($wbRepo, $composer, null, $persisted, $flushed);

        $editor->addToDelivery($this->partnerWithNode(), new Basket(), [BasketComponent::ID_VEGETABLES => '1'], null, 'gestor:1');

        $overrides = $this->overridesIn($persisted);
        $this->assertCount(1, $overrides);
        $this->assertSame('1.00', $overrides[0]->getAmount());
        $this->assertTrue($flushed);

        $events = array_values(array_filter($persisted, fn ($e) => $e instanceof PartnerEvent));
        $this->assertCount(1, $events);
    }

    public function testCreatesDeliveryWhenWeekGeneratedAndPartnerHadNone(): void
    {
        // "No le tocaba" pero el NODO ya tiene reparto generado esa semana: crea su entrega
        // (la persiste) y le cascadea el extra.
        $wbRepo = $this->createMock(WeeklyBasketRepository::class);
        $wbRepo->method('findOneBy')->willReturn(null);
        $wbRepo->method('findForNodeAndBasket')->willReturn([new WeeklyBasket()]);

        $captured = null;
        $composer = $this->createMock(WeeklyBasketComposer::class);
        $composer->method('addAmounts')->willReturnCallback(function (WeeklyBasket $w, array $deltas) use (&$captured): void {
            $captured = $deltas;
        });

        $persisted = [];
        $flushed = false;
        $editor = $this->buildEditor($wbRepo, $composer, null, $persisted, $flushed);

        $editor->addToDelivery($this->partnerWithNode(), new Basket(), [BasketComponent::ID_VEGETABLES => '1'], null, 'gestor:1');

        // Se creó (persistió) la entrega del socio y se le sumó el extra.
        $createdWbs = array_values(array_filter($persisted, fn ($e) => $e instanceof WeeklyBasket));
        $this->assertCount(1, $createdWbs);
        $this->assertSame([BasketComponent::ID_VEGETABLES => 1.0], $captured);
        $this->assertCount(1, $this->overridesIn($persisted));
    }

    public function testAccumulatesOnExistingOverride(): void
    {
        // Ya había un override de +1: una segunda extra de +2 lo acumula a 3 (no crea otro).
        $wb = (new WeeklyBasket())->setPartner(new Partner());
        $wbRepo = $this->createMock(WeeklyBasketRepository::class);
        $wbRepo->method('findOneBy')->willReturn($wb);

        $existing = new PartnerBasketExtra(new Partner(), new Basket(), $this->component(BasketComponent::ID_VEGETABLES), '1.00');

        $captured = null;
        $composer = $this->createMock(WeeklyBasketComposer::class);
        $composer->method('addAmounts')->willReturnCallback(function (WeeklyBasket $w, array $deltas) use (&$captured): void {
            $captured = $deltas;
        });

        $persisted = [];
        $flushed = false;
        $editor = $this->buildEditor($wbRepo, $composer, $existing, $persisted, $flushed);

        $editor->addToDelivery(new Partner(), new Basket(), [BasketComponent::ID_VEGETABLES => '2'], null, 'gestor:1');

        // El override existente se acumula a 3; no se persiste uno nuevo.
        $this->assertSame('3.00', $existing->getAmount());
        $this->assertCount(0, $this->overridesIn($persisted));
        // La cascada a piedra usa el incremento de ESTA llamada, no el total acumulado.
        $this->assertSame([BasketComponent::ID_VEGETABLES => 2.0], $captured);
    }

    public function testRemoveExtraReturnsFalseWhenNone(): void
    {
        // Sin overrides esa semana: no hay nada que quitar.
        $wbRepo = $this->createMock(WeeklyBasketRepository::class);
        $composer = $this->createMock(WeeklyBasketComposer::class);
        $composer->expects($this->never())->method('subtractAmounts');

        $persisted = [];
        $flushed = false;
        $removed = [];
        $editor = $this->buildEditor($wbRepo, $composer, null, $persisted, $flushed, [], $removed);

        $result = $editor->removeExtra(new Partner(), new Basket(), 'gestor:1');

        $this->assertFalse($result);
        $this->assertSame([], $removed);
        $this->assertSame([], $persisted);
    }

    public function testRemoveExtraSubtractsFromBaseDelivery(): void
    {
        // Semana generada, socio con entrega de patrón (basketShare != null): se borra el
        // override y se RESTA el extra de la composición (queda su cesta base).
        $wb = (new WeeklyBasket())->setPartner(new Partner())->setBasketShare(new BasketShare());
        $wbRepo = $this->createMock(WeeklyBasketRepository::class);
        $wbRepo->method('findOneBy')->willReturn($wb);

        $captured = null;
        $composer = $this->createMock(WeeklyBasketComposer::class);
        $composer->method('subtractAmounts')->willReturnCallback(function (WeeklyBasket $w, array $deltas) use (&$captured): void {
            $captured = $deltas;
        });

        $extra = $this->override(BasketComponent::ID_VEGETABLES, '2.00');
        $persisted = [];
        $flushed = false;
        $removed = [];
        $editor = $this->buildEditor($wbRepo, $composer, null, $persisted, $flushed, [$extra], $removed);

        $result = $editor->removeExtra(new Partner(), new Basket(), 'gestor:1');

        $this->assertTrue($result);
        $this->assertContains($extra, $removed); // el override se borra
        $this->assertNotContains($wb, $removed);  // la entrega base NO se borra (tenía patrón)
        $this->assertSame([BasketComponent::ID_VEGETABLES => 2.0], $captured); // se resta el extra
        $events = array_values(array_filter($persisted, fn ($e) => $e instanceof PartnerEvent));
        $this->assertCount(1, $events);
        $this->assertSame(PartnerEvent::TYPE_BASKET_EXTRA_REMOVED, $events[0]->getType());
    }

    public function testRemoveExtraDeletesExtraOnlyDelivery(): void
    {
        // Entrega "solo extra" (sin modalidad, basketShare null): era enteramente el extra →
        // se borra la entrega entera, sin restar.
        $wb = (new WeeklyBasket())->setPartner(new Partner()); // basketShare null
        $wbRepo = $this->createMock(WeeklyBasketRepository::class);
        $wbRepo->method('findOneBy')->willReturn($wb);

        $composer = $this->createMock(WeeklyBasketComposer::class);
        $composer->expects($this->never())->method('subtractAmounts');

        $extras = [$this->override(BasketComponent::ID_VEGETABLES, '1.00'), $this->override(BasketComponent::ID_EGGS, '1.00')];
        $persisted = [];
        $flushed = false;
        $removed = [];
        $editor = $this->buildEditor($wbRepo, $composer, null, $persisted, $flushed, $extras, $removed);

        $result = $editor->removeExtra(new Partner(), new Basket(), 'gestor:1');

        $this->assertTrue($result);
        $this->assertContains($wb, $removed); // la entrega solo-extra se borra entera
        $this->assertContains($extras[0], $removed);
        $this->assertContains($extras[1], $removed);
    }

    public function testRemoveExtraOnDrawnWeekJustDeletesOverride(): void
    {
        // Semana dibujada (sin entrega materializada): basta borrar el override, sin tocar piedra.
        $wbRepo = $this->createMock(WeeklyBasketRepository::class);
        $wbRepo->method('findOneBy')->willReturn(null);

        $composer = $this->createMock(WeeklyBasketComposer::class);
        $composer->expects($this->never())->method('subtractAmounts');

        $extra = $this->override(BasketComponent::ID_VEGETABLES, '1.00');
        $persisted = [];
        $flushed = false;
        $removed = [];
        $editor = $this->buildEditor($wbRepo, $composer, null, $persisted, $flushed, [$extra], $removed);

        $result = $editor->removeExtra(new Partner(), new Basket(), 'gestor:1');

        $this->assertTrue($result);
        $this->assertSame([$extra], $removed); // solo el override, nada de piedra
    }
}
