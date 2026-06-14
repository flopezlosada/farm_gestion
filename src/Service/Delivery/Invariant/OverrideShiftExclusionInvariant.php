<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\PartnerDeliveryShift;
use App\Entity\PartnerNodeOverride;

/**
 * L18 — Exclusión traslado↔shift: un traslado puntual de nodo ANCLA la
 * entrega a esa semana; un cambio de día la SACA de esa semana. Son
 * incompatibles por definición y PickupRelocator los rechaza al crear
 * (commit 9595fd7) — pero eso solo protege ese punto de entrada. Esta ley
 * vigila que la combinación no se cuele por ningún otro camino.
 */
final class OverrideShiftExclusionInvariant extends AbstractInvariant
{
    public function code(): string
    {
        return 'L18';
    }

    public function name(): string
    {
        return 'Traslado de nodo y cambio de día excluyentes en la misma semana';
    }

    public function check(\DateTimeImmutable $from): array
    {
        $overrides = $this->em->createQuery(
            'SELECT IDENTITY(o.partner) AS pid, p.name AS pname, b.id AS bid, b.date AS bdate
             FROM ' . PartnerNodeOverride::class . ' o
             JOIN o.partner p
             JOIN o.basket b
             WHERE b.date >= :from'
        )->setParameter('from', $from)->getArrayResult();

        if ($overrides === []) {
            return [];
        }

        $shiftRows = $this->em->createQuery(
            'SELECT IDENTITY(s.partner) AS pid, IDENTITY(s.fromBasket) AS fbid, IDENTITY(s.toBasket) AS tbid
             FROM ' . PartnerDeliveryShift::class . ' s'
        )->getArrayResult();
        $shiftKeys = [];
        foreach ($shiftRows as $s) {
            $shiftKeys[$s['pid'] . '-' . $s['fbid']] = true;
            if ($s['tbid'] !== null) {
                $shiftKeys[$s['pid'] . '-' . $s['tbid']] = true;
            }
        }

        $violations = [];
        foreach ($overrides as $o) {
            if (isset($shiftKeys[$o['pid'] . '-' . $o['bid']])) {
                $violations[] = sprintf(
                    '%s (%d): la semana del %s tiene a la vez traslado de nodo y cambio de día.',
                    $o['pname'],
                    $o['pid'],
                    $this->d($o['bdate'])
                );
            }
        }

        return $violations;
    }
}
