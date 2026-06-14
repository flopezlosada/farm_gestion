<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\BasketShare;
use App\Entity\Partner;
use App\Entity\WeeklyBasket;

/**
 * L5 — Compartidas PEGADAS: dos familias que comparten cesta
 * (share_partner_id mutuo) se reparten UNA cesta física el MISMO día — salen
 * juntas en el listado, cada una con media (bs 4/6/7). NO alternan entre sí.
 *
 * Violaciones: (a) una mitad tiene cesta compartida en una semana y la otra
 * no (justo el bug de bs=6 que no se materializaba, commit 16558fb);
 * (b) un socio que comparte tiene una entrega de cesta con un basket_share
 * que no es compartido (los WB solo-huevo, bs=5, se toleran: el huevo es por
 * familia y su cadencia puede diferir).
 */
final class SharedPairsTogetherInvariant extends AbstractInvariant
{
    public function code(): string
    {
        return 'L5';
    }

    public function name(): string
    {
        return 'Compartidas pegadas (la pareja sale junta, ½ + ½)';
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

        $rows = $this->em->createQuery(
            'SELECT p.id AS pid, b.id AS bid, b.date AS bdate, bs.id AS bsid
             FROM ' . WeeklyBasket::class . ' wb
             JOIN wb.partner p
             JOIN wb.basket b
             JOIN wb.basket_share bs
             WHERE b.date >= :from AND p.id IN (:ids)'
        )->setParameter('from', $from)
         ->setParameter('ids', array_keys($byId))
         ->getArrayResult();

        // Semanas con cesta COMPARTIDA por socio (bs 4/6/7); el resto de bs se
        // reporta aparte como (b). bs=5 (solo huevo) es legítimo e independiente.
        $sharedWeeks = [];
        $violations = [];
        foreach ($rows as $r) {
            if (in_array($r['bsid'], BasketShare::IDS_SHARED, true)) {
                $sharedWeeks[$r['pid']][$r['bid']] = $r['bdate'];
            } elseif ((int) $r['bsid'] !== BasketShare::ID_ONLY_EGG) {
                $p = $byId[$r['pid']];
                $violations[] = sprintf(
                    '%s (%d): comparte cesta pero su entrega del %s es de modalidad no compartida (bs=%d).',
                    $p->getName(),
                    $p->getId(),
                    $this->d($r['bdate']),
                    $r['bsid']
                );
            }
        }

        $seenPairs = [];
        foreach ($byId as $id => $p) {
            $other = $p->getSharePartner();
            $otherId = $other?->getId();
            // Reciprocidad y pareja activa las vigila ValidatePartnersConsistencyCommand.
            if ($otherId === null || !isset($byId[$otherId]) || $byId[$otherId]->getSharePartner()?->getId() !== $id) {
                continue;
            }
            $key = min($id, $otherId) . '-' . max($id, $otherId);
            if (isset($seenPairs[$key])) {
                continue;
            }
            $seenPairs[$key] = true;

            $mine = $sharedWeeks[$id] ?? [];
            $theirs = $sharedWeeks[$otherId] ?? [];
            foreach (array_diff_key($mine, $theirs) as $date) {
                $violations[] = sprintf(
                    '%s (%d) tiene su media cesta del %s pero su pareja %s (%d) no.',
                    $p->getName(),
                    $id,
                    $this->d($date),
                    $other->getName(),
                    $otherId
                );
            }
            foreach (array_diff_key($theirs, $mine) as $date) {
                $violations[] = sprintf(
                    '%s (%d) tiene su media cesta del %s pero su pareja %s (%d) no.',
                    $other->getName(),
                    $otherId,
                    $this->d($date),
                    $p->getName(),
                    $id
                );
            }
        }

        return $violations;
    }
}
