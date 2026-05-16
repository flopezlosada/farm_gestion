<?php

namespace App\Tests\Entity;

use App\Entity\PartnerBasketShare;
use PHPUnit\Framework\TestCase;

class PartnerBasketShareDeliveryGroupTest extends TestCase
{
    public function testDeliveryGroupArrancaNull(): void
    {
        $share = new PartnerBasketShare();

        $this->assertNull($share->getDeliveryGroup());
    }

    public function testAceptaValoresA_B_Y_Null(): void
    {
        $share = new PartnerBasketShare();

        $share->setDeliveryGroup(PartnerBasketShare::DELIVERY_GROUP_A);
        $this->assertSame('A', $share->getDeliveryGroup());

        $share->setDeliveryGroup(PartnerBasketShare::DELIVERY_GROUP_B);
        $this->assertSame('B', $share->getDeliveryGroup());

        $share->setDeliveryGroup(null);
        $this->assertNull($share->getDeliveryGroup());
    }

    public function testRechazaValoresFueraDelEnum(): void
    {
        $share = new PartnerBasketShare();

        $this->expectException(\InvalidArgumentException::class);
        $share->setDeliveryGroup('C');
    }
}
