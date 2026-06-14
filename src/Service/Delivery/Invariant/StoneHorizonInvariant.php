<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\WeeklyBasket;

/**
 * L23 — Horizonte de materialización (AVISO, no error): las semanas lejanas
 * deben seguir siendo "dibujo" hasta entrar en operación — es el diseño de
 * materialización tardía, y la limpieza de piedra futura ya se hizo una vez
 * en db y golden. Una semana en piedra más allá del horizonte puede ser
 * legítima (editada a mano, que congela), por eso avisa en vez de fallar:
 * lo que vigila es que la piedra prematura no vuelva a acumularse sola.
 */
final class StoneHorizonInvariant extends AbstractInvariant
{
    /** Semanas de operación por delante dentro de las cuales la piedra es normal. */
    private const HORIZON_WEEKS = 6;

    public function code(): string
    {
        return 'L23';
    }

    public function name(): string
    {
        return sprintf('Piedra solo dentro del horizonte (%d semanas)', self::HORIZON_WEEKS);
    }

    public function severity(): string
    {
        return self::SEVERITY_WARNING;
    }

    public function check(\DateTimeImmutable $from): array
    {
        $horizon = $from->modify(sprintf('+%d weeks', self::HORIZON_WEEKS));

        $rows = $this->em->createQuery(
            'SELECT b.date AS bdate, COUNT(wb.id) AS n
             FROM ' . WeeklyBasket::class . ' wb
             JOIN wb.basket b
             WHERE b.date > :horizon
             GROUP BY b.id'
        )->setParameter('horizon', $horizon)->getArrayResult();

        return array_map(
            fn (array $r): string => sprintf(
                'Semana del %s materializada (%d entregas) más allá del horizonte — ¿edición a mano o piedra prematura?',
                $this->d($r['bdate']),
                $r['n']
            ),
            $rows
        );
    }
}
