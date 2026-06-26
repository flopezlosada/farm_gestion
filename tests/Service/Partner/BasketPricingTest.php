<?php

namespace App\Tests\Service\Partner;

use App\Entity\BasketShare;
use App\Entity\EggAmount;
use App\Entity\EggPeriod;
use App\Entity\PartnerBasketShare;
use App\Service\Partner\BasketPricing;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del cálculo de cuota: verdura por modalidad × cestas, y huevos por
 * frecuencia (precio/docena) × docenas (NO por nº de cestas).
 */
class BasketPricingTest extends TestCase
{
    private BasketPricing $pricing;

    protected function setUp(): void
    {
        $this->pricing = new BasketPricing();
    }

    public function testVerduraPrecioPorModalidadPorCestas(): void
    {
        $this->assertSame('100.00', $this->pricing->vegMonthPrice($this->basketShare('100.00'), 1));
        $this->assertSame('200.00', $this->pricing->vegMonthPrice($this->basketShare('100.00'), 2));
        $this->assertSame('27.50', $this->pricing->vegMonthPrice($this->basketShare('27.50'), 1));
    }

    public function testHuevosPrecioPorFrecuenciaPorDocenas(): void
    {
        // Docena (1) semanal (20/docena) = 20; media docena (0,5) = 10.
        $this->assertSame('20.00', $this->pricing->eggMonthPrice($this->eggAmount(1.0), $this->eggPeriod('20.00')));
        $this->assertSame('10.00', $this->pricing->eggMonthPrice($this->eggAmount(0.5), $this->eggPeriod('20.00')));
        // Dos docenas (2) mensual (5/docena) = 10.
        $this->assertSame('10.00', $this->pricing->eggMonthPrice($this->eggAmount(2.0), $this->eggPeriod('5.00')));
    }

    public function testSinHuevosCuotaCero(): void
    {
        $this->assertSame('0', $this->pricing->eggMonthPrice(null, $this->eggPeriod('20.00')));
        $this->assertSame('0', $this->pricing->eggMonthPrice($this->eggAmount(1.0), null));
    }

    public function testVerduraGratisOSoloHuevosCero(): void
    {
        // Modalidad a 0 € (p. ej. "Solo huevos") → verdura 0 aunque lleve varias.
        $this->assertSame('0.00', $this->pricing->vegMonthPrice($this->basketShare('0.00'), 3));
    }

    public function testApplyToSinModalidadNiHuevosNoRevienta(): void
    {
        $pbs = new PartnerBasketShare();
        $pbs->setAmount(1);

        $this->pricing->applyTo($pbs);

        $this->assertSame('0', $pbs->getMonthPrice());
        $this->assertSame('0', $pbs->getEggMonthPrice());
    }

    public function testApplyToEstampaAmbasCuotas(): void
    {
        $pbs = (new PartnerBasketShare())
            ->setBasketShare($this->basketShare('55.00'))
            ->setEggAmount($this->eggAmount(1.0))
            ->setEggPeriod($this->eggPeriod('10.00'));
        $pbs->setAmount(1);

        $this->pricing->applyTo($pbs);

        $this->assertSame('55.00', $pbs->getMonthPrice(), 'quincenal 55 × 1 cesta');
        $this->assertSame('10.00', $pbs->getEggMonthPrice(), 'docena quincenal = 10/docena × 1 docena');
    }

    private function basketShare(string $monthPrice): BasketShare
    {
        $bs = $this->createStub(BasketShare::class);
        $bs->method('getMonthPrice')->willReturn($monthPrice);

        return $bs;
    }

    private function eggPeriod(string $monthPrice): EggPeriod
    {
        $p = $this->createStub(EggPeriod::class);
        $p->method('getMonthPrice')->willReturn($monthPrice);

        return $p;
    }

    private function eggAmount(float $dozens): EggAmount
    {
        $a = $this->createStub(EggAmount::class);
        $a->method('getDozens')->willReturn($dozens);

        return $a;
    }
}
