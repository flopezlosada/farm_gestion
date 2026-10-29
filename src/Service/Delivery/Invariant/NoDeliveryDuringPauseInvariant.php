<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\PartnerPause;
use App\Entity\WeeklyBasket;
use App\Service\Delivery\WeeklyBasketSkipper;

/**
 * L32 — Nada durante una pausa: ningún socio tiene una entrega que recoge
 * (estado "Recoge") en un día que cae dentro de una de sus pausas.
 *
 * La pausa ({@see PartnerPause}) es la fuente de verdad de "¿recibe cesta esa
 * semana?": el generador no materializa nada en un día en pausa y pausar
 * reconcilia la piedra ya generada. Si algún camino se salta eso, la fila sigue
 * en el listado y L10 no lo ve: sólo mira la suscripción y `is_active`, y una
 * pausa no toca ninguna de las dos. Pasó en otoño de 2026, cuando pausar sólo
 * cambiaba el estado del socio y sus cestas seguían saliendo semanas después.
 *
 * El día que cuenta es el físico de la entrega (delivery_date, o el del Basket
 * si no lo tiene), el mismo que consulta el generador. Las filas saltadas no
 * cuentan: no salen en el listado.
 */
final class NoDeliveryDuringPauseInvariant extends AbstractInvariant
{
    public function code(): string
    {
        return 'L32';
    }

    public function name(): string
    {
        return 'Ninguna entrega en un día en que su socio está en pausa';
    }

    public function check(\DateTimeImmutable $from): array
    {
        $rows = $this->em->createQuery(
            'SELECT p.id AS pid, p.name AS pname, b.date AS bdate, wb.delivery_date AS ddate,
                    pp.startsOn AS starts, pp.resumesOn AS resumes
             FROM ' . WeeklyBasket::class . ' wb
             JOIN wb.basket b
             JOIN wb.partner p, ' . PartnerPause::class . ' pp
             WHERE pp.partner = p
               AND b.date >= :from
               AND IDENTITY(wb.weekly_basket_status) = :picks
               AND pp.startsOn <= COALESCE(wb.delivery_date, b.date)
               AND (pp.resumesOn IS NULL OR pp.resumesOn > COALESCE(wb.delivery_date, b.date))
             ORDER BY b.date ASC, p.id ASC'
        )->setParameter('from', $from)
            ->setParameter('picks', WeeklyBasketSkipper::STATUS_PICKS)
            ->getArrayResult();

        return array_map(fn (array $r): string => sprintf(
            '%s (%d): entrega del %s en el listado, pero está en pausa desde el %s%s.',
            $r['pname'],
            $r['pid'],
            $this->d($r['ddate'] ?? $r['bdate']),
            $this->d($r['starts']),
            $r['resumes'] !== null ? ' hasta el ' . $this->d($r['resumes']) . ' (vuelve ese día)' : ' (sin fecha de vuelta)',
        ), $rows);
    }
}
