<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasket;

/**
 * L5F — Familia entrega por el principal: un Partner miembro de familia
 * (parent_id != null) no debe tener ni PBS activo ni WeeklyBasket propios —
 * su cesta y huevos se suman al socio principal. Convención del modelo
 * familia/compartición (memoria partner-family-share-model) que hasta ahora
 * no se vigilaba en ningún sitio.
 */
final class FamilyMemberBareInvariant extends AbstractInvariant
{
    public function code(): string
    {
        return 'L5F';
    }

    public function name(): string
    {
        return 'Miembro de familia sin cesta propia (entrega el principal)';
    }

    public function check(\DateTimeImmutable $from): array
    {
        $violations = [];

        $withPbs = $this->em->createQuery(
            'SELECT DISTINCT p.id AS pid, p.name AS pname
             FROM ' . PartnerBasketShare::class . ' pbs
             JOIN pbs.partner p
             WHERE pbs.is_active = 1 AND p.parent IS NOT NULL'
        )->getArrayResult();
        foreach ($withPbs as $r) {
            $violations[] = sprintf(
                '%s (%d) es miembro de familia (parent_id) pero tiene un PBS activo propio.',
                $r['pname'],
                $r['pid']
            );
        }

        $withWb = $this->em->createQuery(
            'SELECT DISTINCT p.id AS pid, p.name AS pname
             FROM ' . WeeklyBasket::class . ' wb
             JOIN wb.partner p
             JOIN wb.basket b
             WHERE b.date >= :from AND p.parent IS NOT NULL'
        )->setParameter('from', $from)->getArrayResult();
        foreach ($withWb as $r) {
            $violations[] = sprintf(
                '%s (%d) es miembro de familia (parent_id) pero tiene entregas materializadas propias.',
                $r['pname'],
                $r['pid']
            );
        }

        return $violations;
    }
}
