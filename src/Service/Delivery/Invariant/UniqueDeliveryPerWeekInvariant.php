<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\WeeklyBasket;

/**
 * L1 — Unicidad: ningún socio tiene dos WeeklyBasket en la misma semana
 * (Basket). Ley emergente: ningún camino del generador la garantiza por sí
 * solo (candidatos + shifts entrantes + overrides + huevos-extra podrían
 * solaparse), por eso se vigila aquí.
 */
final class UniqueDeliveryPerWeekInvariant extends AbstractInvariant
{
    public function code(): string
    {
        return 'L1';
    }

    public function name(): string
    {
        return 'Unicidad socio/semana (ninguna entrega duplicada)';
    }

    public function check(\DateTimeImmutable $from): array
    {
        $rows = $this->em->createQuery(
            'SELECT p.id AS pid, p.name AS pname, b.date AS bdate, COUNT(wb.id) AS n
             FROM ' . WeeklyBasket::class . ' wb
             JOIN wb.basket b
             JOIN wb.partner p
             WHERE b.date >= :from
             GROUP BY p.id, b.id
             HAVING COUNT(wb.id) > 1'
        )->setParameter('from', $from)->getArrayResult();

        return array_map(
            fn (array $r): string => sprintf(
                '%s (%d): %d entregas en la semana del %s.',
                $r['pname'],
                $r['pid'],
                $r['n'],
                $this->d($r['bdate'])
            ),
            $rows
        );
    }
}
