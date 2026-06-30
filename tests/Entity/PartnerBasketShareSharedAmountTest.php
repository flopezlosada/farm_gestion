<?php

namespace App\Tests\Entity;

use App\Entity\BasketShare;
use App\Entity\PartnerBasketShare;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Cubre la validación {@see PartnerBasketShare::validateSharedBasketAmount}: una
 * cesta compartida (4/6/7) no puede llevar más de 1 (el camino correcto para una
 * entrega puntual de más es «Añadir cesta extra»). Regresión del bug 2026-06-30:
 * Ana Villa acabó con amount=2 en su quincenal compartida.
 */
class PartnerBasketShareSharedAmountTest extends TestCase
{
    public function testCompartidaConDosCestasViola(): void
    {
        $share = $this->share(BasketShare::IDS_SHARED[1], 2); // quincenal compartida, amount 2

        $context = $this->createMock(ExecutionContextInterface::class);
        $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $context->expects($this->once())->method('buildViolation')->willReturn($builder);
        $builder->expects($this->once())->method('atPath')->with('amount')->willReturn($builder);
        $builder->expects($this->once())->method('addViolation');

        $share->validateSharedBasketAmount($context);
    }

    public function testCompartidaConUnaCestaNoViola(): void
    {
        $share = $this->share(BasketShare::IDS_SHARED[1], 1);

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())->method('buildViolation');

        $share->validateSharedBasketAmount($context);
    }

    public function testNoCompartidaConDosCestasNoViola(): void
    {
        $share = $this->share(BasketShare::ID_BIWEEKLY, 2); // quincenal normal, 2 cestas: permitido

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects($this->never())->method('buildViolation');

        $share->validateSharedBasketAmount($context);
    }

    private function share(int $basketShareId, int $amount): PartnerBasketShare
    {
        $basketShare = new BasketShare();
        $ref = new \ReflectionProperty(BasketShare::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($basketShare, $basketShareId);

        $share = new PartnerBasketShare();
        $share->setBasketShare($basketShare);
        $share->setAmount($amount);

        return $share;
    }
}
