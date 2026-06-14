<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\BasketShare;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerNodeOverride;
use App\Entity\WeeklyBasket;

/**
 * L15 — Estado vigente: toda entrega futura debe cuadrar con la configuración
 * ACTUAL del socio. Si se cambia la modalidad, el nodo o la cohorte y las
 * semanas ya generadas no se reconcilian, quedan WB "huérfanos" de la
 * configuración vieja (el bug del cambio de modalidad en mes generado,
 * commit 70add5a, generalizado a ley).
 *
 * Comprobaciones por WB de hoy en adelante (la ausencia de PBS vigente la
 * reporta L10):
 *  (a) el basket_share del WB es el del PBS vigente — o solo-huevos (bs=5),
 *      legítimo para semanas de huevo extra (caso SANTOS);
 *  (b) el WB cuelga de un grupo del NODO actual del socio, salvo que un
 *      PartnerNodeOverride de esa semana explique el traslado.
 */
final class CurrentConfigMatchInvariant extends AbstractInvariant
{
    public function code(): string
    {
        return 'L15';
    }

    public function name(): string
    {
        return 'Entregas futuras cuadran con la configuración vigente';
    }

    public function check(\DateTimeImmutable $from): array
    {
        /** @var WeeklyBasket[] $weeklyBaskets */
        $weeklyBaskets = $this->em->createQuery(
            'SELECT wb, b, p, bs, g, n FROM ' . WeeklyBasket::class . ' wb
             JOIN wb.basket b
             JOIN wb.partner p
             LEFT JOIN wb.basket_share bs
             LEFT JOIN wb.weekly_basket_group g
             LEFT JOIN g.node n
             WHERE b.date >= :from AND p.is_active = 1'
        )->setParameter('from', $from)->getResult();

        if ($weeklyBaskets === []) {
            return [];
        }

        $partnerIds = array_unique(array_map(
            static fn (WeeklyBasket $wb): int => $wb->getPartner()->getId(),
            $weeklyBaskets
        ));

        // Todas las suscripciones de los socios implicados, de una vez (sin N+1).
        $sharesByPartner = [];
        /** @var PartnerBasketShare[] $shares */
        $shares = $this->em->createQuery(
            'SELECT pbs, bs FROM ' . PartnerBasketShare::class . ' pbs
             JOIN pbs.basket_share bs
             WHERE pbs.partner IN (:pids)'
        )->setParameter('pids', $partnerIds)->getResult();
        foreach ($shares as $pbs) {
            $sharesByPartner[$pbs->getPartner()->getId()][] = $pbs;
        }

        // Traslados de nodo de la ventana, como set socio-semana.
        $overrides = $this->em->createQuery(
            'SELECT IDENTITY(o.partner) AS pid, IDENTITY(o.basket) AS bid
             FROM ' . PartnerNodeOverride::class . ' o
             JOIN o.basket b
             WHERE b.date >= :from'
        )->setParameter('from', $from)->getArrayResult();
        $overrideSet = [];
        foreach ($overrides as $o) {
            $overrideSet[$o['pid'] . '-' . $o['bid']] = true;
        }

        $violations = [];
        foreach ($weeklyBaskets as $wb) {
            $partner = $wb->getPartner();
            $basketDate = $wb->getBasket()->getDate();
            $pbs = $this->pbsActiveAt($sharesByPartner[$partner->getId()] ?? [], $basketDate);

            if ($pbs === null) {
                continue; // Sin PBS vigente: lo reporta L10, no se duplica aquí.
            }

            $wbShareId = $wb->getBasketShare()?->getId();
            $currentShareId = $pbs->getBasketShare()?->getId();
            if ($wbShareId !== $currentShareId && $wbShareId !== BasketShare::ID_ONLY_EGG) {
                $violations[] = sprintf(
                    '%s (%d): WB del %s con modalidad bs=%s, pero su cesta vigente es bs=%s — ¿cambio sin reconciliar?',
                    $partner->getName(),
                    $partner->getId(),
                    $this->d($basketDate),
                    $wbShareId ?? 'NULL',
                    $currentShareId ?? 'NULL'
                );
            }

            $homeNodeId = $partner->getWeeklyBasketGroup()?->getNode()?->getId();
            $wbNodeId = $wb->getWeeklyBasketGroup()?->getNode()?->getId();
            $key = $partner->getId() . '-' . $wb->getBasket()->getId();
            if ($homeNodeId !== null && $wbNodeId !== null && $wbNodeId !== $homeNodeId && !isset($overrideSet[$key])) {
                $violations[] = sprintf(
                    '%s (%d): WB del %s en el nodo %s, pero su nodo de casa es %s y no hay traslado registrado.',
                    $partner->getName(),
                    $partner->getId(),
                    $this->d($basketDate),
                    $wb->getWeeklyBasketGroup()?->getNode()?->getName() ?? '¿?',
                    $partner->getWeeklyBasketGroup()?->getNode()?->getName() ?? '¿?'
                );
            }
        }

        return $violations;
    }
}
