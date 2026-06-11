<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\Basket;
use App\Entity\BasketComponent;
use App\Entity\PartnerBasketExtra;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\WeeklyBasket;
use App\Entity\WeeklyBasketItem;
use App\Service\Delivery\EggDeliveryResolver;
use App\Service\Delivery\NodeDeliveryDate;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L11 — Docenas por semana = resolver: en cada semana GENERADA, las docenas
 * materializadas de cada socio con contrato de huevo deben ser exactamente
 * las que {@see EggDeliveryResolver} dicta para esa semana (su cantidad si
 * le toca, cero si no), siempre que su nodo reparta.
 *
 * Juez y parte declarado: reusa el resolver, así que un bug DENTRO del
 * resolver no lo caza (para eso está la L17, agregado independiente, y la
 * comparación contra PDF). Lo que sí caza es el DRIFT entre el resolver y lo
 * materializado: semanas generadas antes de un fix, ediciones a mano, ítems
 * perdidos al congelar.
 *
 * Exclusiones para no dar falsos positivos: socios con un shift (entero o de
 * componente) tocando esa semana (los juzga L6/L16) y socios con extra de
 * huevos esa semana (delta legítimo). También se reporta el caso inverso:
 * docenas materializadas SIN contrato de huevo vigente (y sin extra ni shift
 * que las explique).
 */
final class EggsMatchResolverInvariant extends AbstractInvariant
{
    private const EPSILON = 0.01;

    public function __construct(
        EntityManagerInterface $em,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
        private readonly EggDeliveryResolver $eggResolver,
    ) {
        parent::__construct($em);
    }

    public function code(): string
    {
        return 'L11';
    }

    public function name(): string
    {
        return 'Docenas por semana cuadran con el resolver de huevos';
    }

    public function check(\DateTimeImmutable $from): array
    {
        /** @var Basket[] $generatedBaskets */
        $generatedBaskets = $this->em->createQuery(
            'SELECT b FROM ' . Basket::class . ' b
             WHERE b.date >= :from AND EXISTS (
                 SELECT wb.id FROM ' . WeeklyBasket::class . ' wb WHERE wb.basket = b
             )
             ORDER BY b.date ASC'
        )->setParameter('from', $from)->getResult();
        if ($generatedBaskets === []) {
            return [];
        }

        /** @var PartnerBasketShare[] $eggShares */
        $eggShares = $this->em->createQuery(
            'SELECT pbs, p, ea, g, n FROM ' . PartnerBasketShare::class . ' pbs
             JOIN pbs.partner p
             JOIN pbs.egg_amount ea
             JOIN pbs.egg_period ep
             LEFT JOIN p.weekly_basket_group g
             LEFT JOIN g.node n
             WHERE pbs.is_active = 1 AND p.is_active = 1 AND p.parent IS NULL'
        )->getResult();

        $actual = $this->eggSumsByPartnerBasket($from);
        $excluded = $this->excludedPartnerBaskets($from);
        $eggPartnerIds = array_fill_keys(
            array_map(static fn (PartnerBasketShare $pbs): int => $pbs->getPartner()->getId(), $eggShares),
            true
        );

        $violations = [];
        foreach ($generatedBaskets as $basket) {
            $day = $basket->getDate()->format('Y-m-d');
            foreach ($eggShares as $pbs) {
                $partner = $pbs->getPartner();
                $key = $partner->getId() . '-' . $basket->getId();
                if (isset($excluded[$key])) {
                    continue;
                }
                $start = $pbs->getStartDate()?->format('Y-m-d');
                $end = $pbs->getEndDate()?->format('Y-m-d');
                if (($start !== null && $start > $day) || ($end !== null && $end < $day)) {
                    continue;
                }
                $node = $partner->getWeeklyBasketGroup()?->getNode();
                if ($node === null || $node->getDeliveryWeekday() === null) {
                    continue; // Sin nodo juzgable.
                }

                $nodeDelivers = $this->nodeDeliveryDate->physicalDateFor($basket, $node) !== null;
                $expected = $nodeDelivers && $this->eggResolver->delivers($pbs, $basket)
                    ? $pbs->getEggAmount()->getDozens()
                    : 0.0;
                $got = $actual[$key] ?? 0.0;

                if (abs($got - $expected) >= self::EPSILON) {
                    $violations[] = sprintf(
                        '%s (%d): semana del %s con %s docenas materializadas, el resolver dicta %s.',
                        $partner->getName(),
                        $partner->getId(),
                        $this->d($basket->getDate()),
                        rtrim(rtrim(number_format($got, 2, '.', ''), '0'), '.'),
                        rtrim(rtrim(number_format($expected, 2, '.', ''), '0'), '.')
                    );
                }
            }

            // Inverso: docenas sin contrato vigente que las explique.
            foreach ($actual as $key => $got) {
                [$pid, $bid] = array_map('intval', explode('-', $key));
                if ($bid !== $basket->getId() || $got < self::EPSILON) {
                    continue;
                }
                if (!isset($eggPartnerIds[$pid]) && !isset($excluded[$key])) {
                    $violations[] = sprintf(
                        'Socio %d: %s docenas en la semana del %s sin contrato de huevos vigente.',
                        $pid,
                        rtrim(rtrim(number_format($got, 2, '.', ''), '0'), '.'),
                        $this->d($basket->getDate())
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Docenas materializadas por (socio, semana) en la ventana.
     *
     * @return array<string, float> Clave "pid-bid".
     */
    private function eggSumsByPartnerBasket(\DateTimeImmutable $from): array
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(wb.partner) AS pid, b.id AS bid, i.amount AS amount
             FROM ' . WeeklyBasketItem::class . ' i
             JOIN i.weeklyBasket wb
             JOIN wb.basket b
             WHERE b.date >= :from AND IDENTITY(i.basketComponent) = :eggs'
        )->setParameter('from', $from)
         ->setParameter('eggs', BasketComponent::ID_EGGS)
         ->getArrayResult();

        $sums = [];
        foreach ($rows as $r) {
            $key = $r['pid'] . '-' . $r['bid'];
            $sums[$key] = ($sums[$key] ?? 0.0) + (float) $r['amount'];
        }

        return $sums;
    }

    /**
     * Pares (socio, semana) que esta ley NO juzga: tocados por un shift
     * (cualquier dirección/componente — los juzgan L6 y L16) o con extra de
     * huevos (delta legítimo sobre el patrón).
     *
     * @return array<string, true> Clave "pid-bid".
     */
    private function excludedPartnerBaskets(\DateTimeImmutable $from): array
    {
        $excluded = [];

        $shifts = $this->em->createQuery(
            'SELECT IDENTITY(s.partner) AS pid, IDENTITY(s.fromBasket) AS fbid, IDENTITY(s.toBasket) AS tbid
             FROM ' . PartnerDeliveryShift::class . ' s'
        )->getArrayResult();
        foreach ($shifts as $s) {
            $excluded[$s['pid'] . '-' . $s['fbid']] = true;
            if ($s['tbid'] !== null) {
                $excluded[$s['pid'] . '-' . $s['tbid']] = true;
            }
        }

        $extras = $this->em->createQuery(
            'SELECT IDENTITY(x.partner) AS pid, IDENTITY(x.basket) AS bid
             FROM ' . PartnerBasketExtra::class . ' x
             JOIN x.basket b
             WHERE b.date >= :from AND IDENTITY(x.component) = :eggs'
        )->setParameter('from', $from)
         ->setParameter('eggs', BasketComponent::ID_EGGS)
         ->getArrayResult();
        foreach ($extras as $x) {
            $excluded[$x['pid'] . '-' . $x['bid']] = true;
        }

        return $excluded;
    }
}
