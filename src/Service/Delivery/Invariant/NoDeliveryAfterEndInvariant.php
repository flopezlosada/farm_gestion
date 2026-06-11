<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\PartnerBasketExtra;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasket;

/**
 * L10 — Nada después de la baja: ningún socio tiene entregas materializadas
 * en semanas que ninguna suscripción suya cubre (todas terminadas antes, o
 * aún no empezadas), ni entregas futuras si el socio entero está inactivo.
 * Versión vigilante del fix c3fff60 (el listado incluía bajas).
 *
 * Nota: el WB no persiste qué PBS lo originó (esa propiedad es transitoria,
 * ver WeeklyBasket), así que la baja se detecta por ausencia de PBS vigente
 * en la fecha de la semana (NOT EXISTS), no por el end_date de un PBS enlazado.
 *
 * Una cesta extra puntual ({@see PartnerBasketExtra}) a un NO suscriptor crea
 * legítimamente un WB sin PBS que lo cubra — esa semana se tolera.
 */
final class NoDeliveryAfterEndInvariant extends AbstractInvariant
{
    public function code(): string
    {
        return 'L10';
    }

    public function name(): string
    {
        return 'Sin entregas tras la baja (sin PBS vigente / socio inactivo)';
    }

    public function check(\DateTimeImmutable $from): array
    {
        $violations = [];

        $uncovered = $this->em->createQuery(
            'SELECT p.id AS pid, p.name AS pname, b.date AS bdate
             FROM ' . WeeklyBasket::class . ' wb
             JOIN wb.basket b
             JOIN wb.partner p
             WHERE b.date >= :from AND p.is_active = 1
               AND NOT EXISTS (
                   SELECT pbs.id FROM ' . PartnerBasketShare::class . ' pbs
                   WHERE pbs.partner = p
                     AND (pbs.is_active = 1 OR pbs.end_date IS NOT NULL)
                     AND (pbs.start_date IS NULL OR pbs.start_date <= b.date)
                     AND (pbs.end_date IS NULL OR pbs.end_date >= b.date)
               )
               AND NOT EXISTS (
                   SELECT x.id FROM ' . PartnerBasketExtra::class . ' x
                   WHERE x.partner = p AND x.basket = b
               )'
        )->setParameter('from', $from)->getArrayResult();
        foreach ($uncovered as $r) {
            $violations[] = sprintf(
                '%s (%d): WB en la semana del %s sin ningún PBS vigente que la cubra (¿baja sin reconciliar?).',
                $r['pname'],
                $r['pid'],
                $this->d($r['bdate'])
            );
        }

        $inactive = $this->em->createQuery(
            'SELECT p.id AS pid, p.name AS pname, b.date AS bdate
             FROM ' . WeeklyBasket::class . ' wb
             JOIN wb.basket b
             JOIN wb.partner p
             WHERE b.date >= :from AND p.is_active = 0'
        )->setParameter('from', $from)->getArrayResult();
        foreach ($inactive as $r) {
            $violations[] = sprintf(
                '%s (%d): socio INACTIVO con WB en la semana del %s.',
                $r['pname'],
                $r['pid'],
                $this->d($r['bdate'])
            );
        }

        return $violations;
    }
}
