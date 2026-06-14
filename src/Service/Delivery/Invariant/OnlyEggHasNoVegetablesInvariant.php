<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\BasketComponent;
use App\Entity\BasketShare;
use App\Entity\WeeklyBasketItem;

/**
 * L12 — Solo-huevos sin verdura: una entrega de modalidad "solo huevos"
 * (basket_share=5) jamás lleva componente de verdura con cantidad > 0.
 * Su peso de cesta física es 0 por definición (BasketShare::getDeliveredBasketWeight).
 */
final class OnlyEggHasNoVegetablesInvariant extends AbstractInvariant
{
    public function code(): string
    {
        return 'L12';
    }

    public function name(): string
    {
        return 'Solo-huevos (bs=5) sin componente de verdura';
    }

    public function check(\DateTimeImmutable $from): array
    {
        $rows = $this->em->createQuery(
            'SELECT p.id AS pid, p.name AS pname, b.date AS bdate, i.amount AS amount
             FROM ' . WeeklyBasketItem::class . ' i
             JOIN i.weeklyBasket wb
             JOIN i.basketComponent c
             JOIN wb.basket b
             JOIN wb.partner p
             JOIN wb.basket_share bs
             WHERE b.date >= :from
               AND bs.id = :onlyEgg
               AND c.id = :vegetables
               AND i.amount > 0'
        )->setParameter('from', $from)
         ->setParameter('onlyEgg', BasketShare::ID_ONLY_EGG)
         ->setParameter('vegetables', BasketComponent::ID_VEGETABLES)
         ->getArrayResult();

        return array_map(
            fn (array $r): string => sprintf(
                '%s (%d): entrega solo-huevos del %s con %s de verdura.',
                $r['pname'],
                $r['pid'],
                $this->d($r['bdate']),
                $r['amount']
            ),
            $rows
        );
    }
}
