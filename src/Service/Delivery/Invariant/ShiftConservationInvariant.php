<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\PartnerDeliveryShift;
use App\Entity\WeeklyBasket;
use App\Service\Delivery\WeeklyBasketSkipper;

/**
 * L6 — Conservación del shift, en ambas direcciones sobre semanas generadas:
 * un cambio puntual de entrega ENTERA (component=null) implica que el socio
 * NO tiene WB en la semana origen y SÍ lo tiene en la destino (si hay
 * destino; "no recoge" = sin destino). Es la ley que demuestra que un cambio
 * hecho por DnD en el calendario "se queda en su sitio".
 *
 * Mira también el pasado reciente (8 semanas) para detectar entregas
 * perdidas: un shift cuyo destino ya pasó sin materializarse es una cesta
 * que alguien no recibió. Las semanas NO generadas (sin ningún WB) se
 * saltan: ahí el shift vive en el dibujo y aún no hay piedra que validar.
 */
final class ShiftConservationInvariant extends AbstractInvariant
{
    private const LOOKBACK_WEEKS = 8;

    public function code(): string
    {
        return 'L6';
    }

    public function name(): string
    {
        return 'Conservación del shift (0 en origen, 1 en destino)';
    }

    public function check(\DateTimeImmutable $from): array
    {
        $since = $from->modify(sprintf('-%d weeks', self::LOOKBACK_WEEKS));

        /** @var PartnerDeliveryShift[] $shifts */
        $shifts = $this->em->createQuery(
            'SELECT s, p, fb, tb FROM ' . PartnerDeliveryShift::class . ' s
             JOIN s.partner p
             JOIN s.fromBasket fb
             LEFT JOIN s.toBasket tb
             WHERE s.component IS NULL AND (fb.date >= :since OR tb.date >= :since)'
        )->setParameter('since', $since)->getResult();

        if ($shifts === []) {
            return [];
        }

        // Una sola pasada para saber qué semanas están generadas y qué
        // (socio, semana) tienen WB — sin N+1. En el ORIGEN, el applier no
        // borra el WB de una semana materializada: lo deja en estado SALTADA;
        // un WB de origen saltado es la representación LEGAL del shift.
        $basketIds = [];
        foreach ($shifts as $s) {
            $basketIds[] = $s->getFromBasket()->getId();
            if ($s->getToBasket() !== null) {
                $basketIds[] = $s->getToBasket()->getId();
            }
        }
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(wb.partner) AS pid, b.id AS bid, IDENTITY(wb.weekly_basket_status) AS sid
             FROM ' . WeeklyBasket::class . ' wb
             JOIN wb.basket b
             WHERE b.id IN (:bids)'
        )->setParameter('bids', array_unique($basketIds))->getArrayResult();

        $generated = [];
        $hasWb = [];
        $hasLiveWb = [];
        foreach ($rows as $r) {
            $generated[$r['bid']] = true;
            $hasWb[$r['pid'] . '-' . $r['bid']] = true;
            if ((int) $r['sid'] !== WeeklyBasketSkipper::STATUS_SKIPPED) {
                $hasLiveWb[$r['pid'] . '-' . $r['bid']] = true;
            }
        }

        $violations = [];
        foreach ($shifts as $s) {
            $p = $s->getPartner();
            $fromB = $s->getFromBasket();
            $toB = $s->getToBasket();

            if (isset($generated[$fromB->getId()], $hasLiveWb[$p->getId() . '-' . $fromB->getId()])) {
                $violations[] = sprintf(
                    '%s (%d): shift de entrega entera desde el %s, pero sigue teniendo WB VIVO (no saltado) en esa semana.',
                    $p->getName(),
                    $p->getId(),
                    $this->d($fromB->getDate())
                );
            }
            if ($toB !== null
                && isset($generated[$toB->getId()])
                && !isset($hasWb[$p->getId() . '-' . $toB->getId()])
            ) {
                $violations[] = sprintf(
                    '%s (%d): shift con destino %s (semana generada), pero NO tiene WB allí — entrega perdida.',
                    $p->getName(),
                    $p->getId(),
                    $this->d($toB->getDate())
                );
            }
        }

        return $violations;
    }
}
