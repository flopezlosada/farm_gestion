<?php

namespace App\Tests\Service\Delivery;

use App\Entity\Basket;
use App\Entity\EggAmount;
use App\Entity\EggPeriod;
use App\Entity\PartnerBasketShare;
use App\Service\Delivery\BiweeklyCohortResolver;
use App\Service\Delivery\EggDeliveryResolver;
use App\Service\Delivery\MonthlyOperativeOrderResolver;
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
    private EggDeliveryResolver $resolver;
    private Basket $basket;

    protected function setUp(): void
    {
        $this->biweekly = $this->createMock(BiweeklyCohortResolver::class);
        $this->monthly = $this->createMock(MonthlyOperativeOrderResolver::class);
        $this->resolver = new EggDeliveryResolver($this->biweekly, $this->monthly);
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
        $this->monthly->expects($this->never())->method('operativeOrderInMonth');
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

    public function testMensualEnSuOrdenTrue(): void
    {
        $share = $this->shareConPeriodo(3);
        $share->setEggDayMonthOrder(2);
        $this->monthly->method('operativeOrderInMonth')->willReturn(2);
        $this->assertTrue($this->resolver->delivers($share, $this->basket));
    }

    public function testMensualEnOrdenDistintoFalse(): void
    {
        $share = $this->shareConPeriodo(3);
        $share->setEggDayMonthOrder(2);
        $this->monthly->method('operativeOrderInMonth')->willReturn(3);
        $this->assertFalse($this->resolver->delivers($share, $this->basket));
    }

    public function testMensualSinEggDayMonthOrderFalse(): void
    {
        $share = $this->shareConPeriodo(3);
        // egg_day_month_order=null
        $this->monthly->expects($this->never())->method('operativeOrderInMonth');
        $this->assertFalse($this->resolver->delivers($share, $this->basket));
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
