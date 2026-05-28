<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\BasketShare;
use App\Entity\EggAmount;
use App\Entity\EggPeriod;
use App\Entity\Node;
use App\Entity\PartnerBasketShare;
use App\Repository\NodeRepository;
use App\Service\Delivery\BiweeklyCohortResolver;
use App\Service\Delivery\EggDeliveryResolver;
use App\Service\Delivery\MonthlyOperativeOrderResolver;
use App\Service\Delivery\NodeDeliveryDate;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del EggDeliveryResolver. Mockea los dos resolvers auxiliares y
 * verifica la decisión por periodicidad. Cubre el bug que motivó el campo
 * egg_day_month_order: huevos mensuales pintados en viernes equivocado
 * (caso MIRIAM).
 */
class EggDeliveryResolverTest extends TestCase
{
    private BiweeklyCohortResolver&MockObject $biweekly;
    private MonthlyOperativeOrderResolver&MockObject $monthly;
    private NodeRepository&MockObject $nodeRepository;
    private NodeDeliveryDate&MockObject $nodeDeliveryDate;
    private EntityManagerInterface&MockObject $em;
    private EggDeliveryResolver $resolver;
    private Basket $basket;
    private Node $weeklyNode;

    protected function setUp(): void
    {
        $this->biweekly = $this->createMock(BiweeklyCohortResolver::class);
        $this->monthly = $this->createMock(MonthlyOperativeOrderResolver::class);
        $this->nodeRepository = $this->createMock(NodeRepository::class);
        $this->nodeDeliveryDate = $this->createMock(NodeDeliveryDate::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->weeklyNode = new Node();
        $this->weeklyNode->setName('Torremocha')->setDeliveryWeekday(5)->setCadence(Node::CADENCE_WEEKLY);
        $this->nodeRepository->method('findOneBy')->willReturn($this->weeklyNode);

        $this->resolver = new EggDeliveryResolver(
            $this->biweekly,
            $this->monthly,
            $this->nodeRepository,
            $this->nodeDeliveryDate,
            $this->em,
        );
        $this->basket = new Basket();
        $this->basket->setDate(new \DateTime('2026-05-15'));
    }

    public function testSinHuevosDevuelveFalse(): void
    {
        $share = new PartnerBasketShare();
        // ni egg_amount ni egg_period seteados
        $this->assertFalse($this->resolver->delivers($share, $this->basket));
    }

    public function testSemanalSiempreTrue(): void
    {
        $share = $this->shareConPeriodo(1);
        $this->biweekly->expects($this->never())->method('cohortForBasket');
        $this->monthly->expects($this->never())->method('operativeOrderForNode');
        $this->assertTrue($this->resolver->delivers($share, $this->basket));
    }

    public function testQuincenalEnSuCohorteTrue(): void
    {
        $share = $this->shareConPeriodo(2);
        $share->setDeliveryGroup('A');
        $this->biweekly->method('cohortForBasket')->willReturn('A');
        $this->assertTrue($this->resolver->delivers($share, $this->basket));
    }

    public function testQuincenalEnCohorteContrariaFalse(): void
    {
        $share = $this->shareConPeriodo(2);
        $share->setDeliveryGroup('A');
        $this->biweekly->method('cohortForBasket')->willReturn('B');
        $this->assertFalse($this->resolver->delivers($share, $this->basket));
    }

    public function testQuincenalSinDeliveryGroupFalse(): void
    {
        $share = $this->shareConPeriodo(2);
        // delivery_group=null
        $this->biweekly->expects($this->never())->method('cohortForBasket');
        $this->assertFalse($this->resolver->delivers($share, $this->basket));
    }

    /**
     * Cesta mensual + huevos mensuales: huevos van con la cesta. Se compara
     * operativeOrderForNode con dayMonthOrder de la cesta; egg_day_month_order
     * se ignora.
     */
    public function testMensualConCestaMensualEnSuOrdenTrue(): void
    {
        $share = $this->shareMensualConCesta(2);
        $this->nodeDeliveryDate->method('deliversInBasket')->willReturn(true);
        $this->monthly->method('operativeOrderForNode')->willReturn(2);
        $this->assertTrue($this->resolver->delivers($share, $this->basket));
    }

    public function testMensualConCestaMensualEnOrdenDistintoFalse(): void
    {
        $share = $this->shareMensualConCesta(2);
        $this->nodeDeliveryDate->method('deliversInBasket')->willReturn(true);
        $this->monthly->method('operativeOrderForNode')->willReturn(3);
        $this->assertFalse($this->resolver->delivers($share, $this->basket));
    }

    public function testMensualConCestaMensualSiNodoNoEntregaFalse(): void
    {
        $share = $this->shareMensualConCesta(2);
        $this->nodeDeliveryDate->method('deliversInBasket')->willReturn(false);
        $this->assertFalse($this->resolver->delivers($share, $this->basket));
    }

    /**
     * Cesta NO mensual con huevos mensuales sin egg_day_month_order:
     * fuera del listado para revisión manual.
     */
    public function testQuincenalConHuevosMensualesSinEggDayMonthOrderFalse(): void
    {
        $share = $this->shareConPeriodo(3);
        $share->setBasketShare($this->makeBasketShare(2));
        $share->setDeliveryGroup('B');
        // egg_day_month_order=null
        $this->assertFalse($this->resolver->delivers($share, $this->basket));
    }

    /**
     * Helper: PBS mensual con cesta + huevos mensuales y day_month_order dado.
     * Atajo para los tests de "cesta mensual = huevo con la cesta".
     */
    private function shareMensualConCesta(int $dayMonthOrder): PartnerBasketShare
    {
        $share = $this->shareConPeriodo(3);
        $share->setBasketShare($this->makeBasketShare(3));
        $share->setDayMonthOrder($dayMonthOrder);
        return $share;
    }

    /**
     * Helper: BasketShare mock con id (la entidad no expone setId).
     */
    private function makeBasketShare(int $id): BasketShare
    {
        $bs = $this->createMock(BasketShare::class);
        $bs->method('getId')->willReturn($id);
        return $bs;
    }

    /**
     * Helper: construye un PBS con egg_amount y egg_period con el id pedido.
     * EggPeriod no tiene setId, así que usamos un mock parcial.
     */
    private function shareConPeriodo(int $periodoId): PartnerBasketShare
    {
        $period = $this->createMock(EggPeriod::class);
        $period->method('getId')->willReturn($periodoId);
        $amount = new EggAmount();

        $share = new PartnerBasketShare();
        $share->setEggAmount($amount);
        $share->setEggPeriod($period);
        return $share;
    }
}
