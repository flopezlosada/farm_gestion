<?php

namespace App\Tests\Entity;

use App\Entity\BasketShare;
use App\Entity\EggAmount;
use App\Entity\EggPeriod;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;
use PHPUnit\Framework\TestCase;

/**
 * Cubre {@see PartnerBasketShare::successor}: la cesta que continúa a otra al cambiarla
 * conserva todo lo que la define y se cobra. Al cambiar la modalidad se perdían el
 * transporte y el pagador, y la cuota calculada dejaba de cobrar el transporte o le
 * cargaba la cesta a quien la recibe en vez de a quien la paga.
 */
class PartnerBasketShareSuccessorTest extends TestCase
{
    /** Una quincenal de Cascorro con huevos, transporte y la paga otra persona. */
    public function testConservaTodoLoQueSeCobra(): void
    {
        $partner = new Partner();
        $payer = new Partner();
        $basketShare = new BasketShare();
        $eggAmount = new EggAmount();
        $eggPeriod = new EggPeriod();

        $old = new PartnerBasketShare();
        $old->setPartner($partner);
        $old->setBasketShare($basketShare);
        $old->setAmount(1);
        $old->setEggAmount($eggAmount);
        $old->setEggPeriod($eggPeriod);
        $old->setDeliveryGroup(PartnerBasketShare::DELIVERY_GROUP_B);
        $old->setDayMonthOrder(2);
        $old->setEggDayMonthOrder(2);
        $old->setPayerPartner($payer);
        $old->setStartDate(new \DateTime('2026-01-01'));
        $old->setIsActive(true);

        $next = $old->successor();

        $this->assertNotSame($old, $next);
        $this->assertSame($partner, $next->getPartner());
        $this->assertSame($basketShare, $next->getBasketShare());
        $this->assertSame(1, $next->getAmount());
        $this->assertSame($eggAmount, $next->getEggAmount());
        $this->assertSame($eggPeriod, $next->getEggPeriod());
        $this->assertSame('B', $next->getDeliveryGroup());
        $this->assertSame(2, $next->getDayMonthOrder());
        $this->assertSame(2, $next->getEggDayMonthOrder());
        $this->assertSame($payer, $next->getPayerPartner(), 'El cambio de modalidad perdía a quien paga la cesta.');
    }

    /** Las fechas y el estado son de cada tramo: la nueva no hereda los de la vieja. */
    public function testNoHeredaFechasNiEstado(): void
    {
        $old = new PartnerBasketShare();
        $old->setPartner(new Partner());
        $old->setStartDate(new \DateTime('2026-01-01'));
        $old->setEndDate(new \DateTime('2026-08-08'));
        $old->setIsActive(true);

        $next = $old->successor();

        $this->assertNull($next->getStartDate());
        $this->assertNull($next->getEndDate());
        $this->assertNull($next->getIsActive());
    }

    /** Lo que la cesta vieja no tiene —sin huevos, la paga ella— sigue sin estar. */
    public function testLoVacioSigueVacio(): void
    {
        $old = new PartnerBasketShare();
        $old->setPartner(new Partner());
        $old->setBasketShare(new BasketShare());
        $old->setAmount(1);

        $next = $old->successor();

        $this->assertNull($next->getEggAmount());
        $this->assertNull($next->getEggPeriod());
        $this->assertNull($next->getPayerPartner());
        $this->assertNull($next->getDeliveryGroup());
    }
}
