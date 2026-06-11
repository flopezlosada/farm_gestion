<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\BasketComponent;
use App\Entity\PartnerBasketShare;
use App\Entity\PartnerDeliveryShift;
use App\Entity\WeeklyBasketItem;
use App\Service\Delivery\EggDeliveryResolver;
use App\Service\Delivery\NodeDeliveryDate;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L16 — La composición viaja ENTERA con el shift: al mover una entrega
 * completa a otra semana, viajan la cesta Y los huevos. Nace del caso Franco:
 * el shift movió la verdura pero el resolver dejó los huevos en el viernes de
 * cohorte original, y el socio recibió cesta sin docenas en el destino.
 *
 * Para cada shift de entrega entera cuyo destino está generado y tiene WB:
 *  (a) si el patrón del socio repartía huevos en la semana de ORIGEN
 *      (resolver + nodo), el WB de destino debe llevar docenas (> 0);
 *  (b) si su modalidad lleva cesta física (peso > 0), el WB de destino debe
 *      llevar verdura (> 0).
 * Solo comprueba PRESENCIA (faltante = violación), no cantidades exactas —
 * las cantidades por semana las juzga L11 y el agregado L17.
 */
final class ShiftCarriesCompositionInvariant extends AbstractInvariant
{
    private const LOOKBACK_WEEKS = 8;

    public function __construct(
        EntityManagerInterface $em,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
        private readonly EggDeliveryResolver $eggResolver,
    ) {
        parent::__construct($em);
    }

    public function code(): string
    {
        return 'L16';
    }

    public function name(): string
    {
        return 'La composición viaja entera con el shift (cesta + huevos)';
    }

    public function check(\DateTimeImmutable $from): array
    {
        $since = $from->modify(sprintf('-%d weeks', self::LOOKBACK_WEEKS));

        /** @var PartnerDeliveryShift[] $shifts */
        $shifts = $this->em->createQuery(
            'SELECT s, p, fb, tb FROM ' . PartnerDeliveryShift::class . ' s
             JOIN s.partner p
             JOIN s.fromBasket fb
             JOIN s.toBasket tb
             WHERE s.component IS NULL AND (fb.date >= :since OR tb.date >= :since)'
        )->setParameter('since', $since)->getResult();
        if ($shifts === []) {
            return [];
        }

        // Composición materializada en los destinos, por (socio, semana, componente).
        $toBasketIds = array_unique(array_map(
            static fn (PartnerDeliveryShift $s): int => $s->getToBasket()->getId(),
            $shifts
        ));
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(wb.partner) AS pid, b.id AS bid, IDENTITY(i.basketComponent) AS cid, i.amount AS amount
             FROM ' . WeeklyBasketItem::class . ' i
             JOIN i.weeklyBasket wb
             JOIN wb.basket b
             WHERE b.id IN (:bids)'
        )->setParameter('bids', $toBasketIds)->getArrayResult();
        $composition = [];
        $hasWb = [];
        foreach ($rows as $r) {
            $key = $r['pid'] . '-' . $r['bid'];
            $hasWb[$key] = true;
            $composition[$key][$r['cid']] = ($composition[$key][$r['cid']] ?? 0.0) + (float) $r['amount'];
        }

        // Suscripciones de los socios implicados, para resolver el patrón en ORIGEN.
        $partnerIds = array_unique(array_map(
            static fn (PartnerDeliveryShift $s): int => $s->getPartner()->getId(),
            $shifts
        ));
        $sharesByPartner = [];
        /** @var PartnerBasketShare[] $shares */
        $shares = $this->em->createQuery(
            'SELECT pbs, bs, ea, ep FROM ' . PartnerBasketShare::class . ' pbs
             JOIN pbs.basket_share bs
             LEFT JOIN pbs.egg_amount ea
             LEFT JOIN pbs.egg_period ep
             WHERE pbs.partner IN (:pids)'
        )->setParameter('pids', $partnerIds)->getResult();
        foreach ($shares as $pbs) {
            $sharesByPartner[$pbs->getPartner()->getId()][] = $pbs;
        }

        $violations = [];
        foreach ($shifts as $shift) {
            $partner = $shift->getPartner();
            $key = $partner->getId() . '-' . $shift->getToBasket()->getId();
            if (!isset($hasWb[$key])) {
                continue; // Destino sin WB: ausencia total = L6, no composición.
            }
            $pbs = $this->pbsActiveAt(
                $sharesByPartner[$partner->getId()] ?? [],
                $shift->getFromBasket()->getDate()
            );
            if ($pbs === null) {
                continue;
            }
            $node = $partner->getWeeklyBasketGroup()?->getNode();

            $originHadEggs = $pbs->getEggAmount() !== null
                && $pbs->getEggPeriod() !== null
                && $node !== null
                && $node->getDeliveryWeekday() !== null
                && $this->nodeDeliveryDate->physicalDateFor($shift->getFromBasket(), $node) !== null
                && $this->eggResolver->delivers($pbs, $shift->getFromBasket());
            if ($originHadEggs && ($composition[$key][BasketComponent::ID_EGGS] ?? 0.0) <= 0.0) {
                $violations[] = sprintf(
                    '%s (%d): shift %s → %s sin DOCENAS en destino (el origen llevaba huevos) — caso Franco.',
                    $partner->getName(),
                    $partner->getId(),
                    $this->d($shift->getFromBasket()->getDate()),
                    $this->d($shift->getToBasket()->getDate())
                );
            }

            $carriesBasket = ($pbs->getBasketShare()?->getDeliveredBasketWeight() ?? 0.0) > 0.0;
            if ($carriesBasket && ($composition[$key][BasketComponent::ID_VEGETABLES] ?? 0.0) <= 0.0) {
                $violations[] = sprintf(
                    '%s (%d): shift %s → %s sin VERDURA en destino (su modalidad lleva cesta).',
                    $partner->getName(),
                    $partner->getId(),
                    $this->d($shift->getFromBasket()->getDate()),
                    $this->d($shift->getToBasket()->getDate())
                );
            }
        }

        return $violations;
    }
}
