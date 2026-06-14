<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\PartnerBasketShare;

/**
 * L22 — Pareja compartida coherente: si dos familias comparten cesta, sus
 * suscripciones vigentes deben tener la MISMA modalidad y, según esta, la
 * misma cohorte A/B (quincenales) o el mismo orden mensual (mensuales). Si
 * difieren, jamás saldrían pegadas — mejor cazar la precondición que el
 * síntoma (que cazaría L5). El picker de compartidas lo fuerza en UI; esta
 * ley vigila el dato.
 */
final class SharedPairCoherentInvariant extends AbstractInvariant
{
    /** Modalidades con cohorte A/B: quincenal y quincenal compartida. */
    private const IDS_BIWEEKLY = [2, 6];
    /** Modalidades con orden mensual: mensual y mensual compartida. */
    private const IDS_MONTHLY = [3, 7];

    public function code(): string
    {
        return 'L22';
    }

    public function name(): string
    {
        return 'Pareja compartida con misma modalidad y cohorte/orden';
    }

    public function check(\DateTimeImmutable $from): array
    {
        /** @var Partner[] $sharers */
        $sharers = $this->em->createQuery(
            'SELECT p FROM ' . Partner::class . ' p
             WHERE p.is_active = 1 AND p.share_partner IS NOT NULL'
        )->getResult();

        if ($sharers === []) {
            return [];
        }

        $byId = [];
        foreach ($sharers as $p) {
            $byId[$p->getId()] = $p;
        }

        $sharesByPartner = [];
        /** @var PartnerBasketShare[] $shares */
        $shares = $this->em->createQuery(
            'SELECT pbs, bs FROM ' . PartnerBasketShare::class . ' pbs
             JOIN pbs.basket_share bs
             WHERE pbs.partner IN (:pids)'
        )->setParameter('pids', array_keys($byId))->getResult();
        foreach ($shares as $pbs) {
            $sharesByPartner[$pbs->getPartner()->getId()][] = $pbs;
        }

        $violations = [];
        $seenPairs = [];
        foreach ($byId as $id => $p) {
            $other = $p->getSharePartner();
            $otherId = $other?->getId();
            if ($otherId === null || !isset($byId[$otherId]) || $byId[$otherId]->getSharePartner()?->getId() !== $id) {
                continue; // Reciprocidad rota: la reporta ValidatePartnersConsistencyCommand.
            }
            $key = min($id, $otherId) . '-' . max($id, $otherId);
            if (isset($seenPairs[$key])) {
                continue;
            }
            $seenPairs[$key] = true;

            $mine = $this->pbsActiveAt($sharesByPartner[$id] ?? [], $from);
            $theirs = $this->pbsActiveAt($sharesByPartner[$otherId] ?? [], $from);
            if ($mine === null || $theirs === null) {
                continue; // Sin PBS vigente: caso de "quincenal sin cohorte"/consistencia, no de pareja.
            }

            $label = sprintf('%s (%d) ↔ %s (%d)', $p->getName(), $id, $other->getName(), $otherId);
            $myBs = $mine->getBasketShare()?->getId();
            $theirBs = $theirs->getBasketShare()?->getId();

            if ($myBs !== $theirBs) {
                $violations[] = sprintf('%s: modalidades distintas (bs=%s vs bs=%s).', $label, $myBs, $theirBs);
                continue;
            }
            if (in_array($myBs, self::IDS_BIWEEKLY, true)
                && $mine->getDeliveryGroup() !== $theirs->getDeliveryGroup()
            ) {
                $violations[] = sprintf(
                    '%s: cohortes distintas (%s vs %s) — jamás saldrían pegadas.',
                    $label,
                    $mine->getDeliveryGroup() ?? 'NULL',
                    $theirs->getDeliveryGroup() ?? 'NULL'
                );
            }
            if (in_array($myBs, self::IDS_MONTHLY, true)
                && $mine->getDayMonthOrder() !== $theirs->getDayMonthOrder()
            ) {
                $violations[] = sprintf(
                    '%s: orden mensual distinto (%s vs %s).',
                    $label,
                    $mine->getDayMonthOrder() ?? 'NULL',
                    $theirs->getDayMonthOrder() ?? 'NULL'
                );
            }
        }

        return $violations;
    }
}
