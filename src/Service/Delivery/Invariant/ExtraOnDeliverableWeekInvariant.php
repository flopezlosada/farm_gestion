<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\PartnerBasketExtra;
use App\Entity\PartnerNodeOverride;
use App\Service\Delivery\NodeDeliveryDate;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L25 — Extra en semana posible: una cesta extra (override aditivo) solo
 * tiene sentido en una semana en la que el socio puede recoger — es decir,
 * en la que reparte su nodo (el de casa, o el del traslado de nodo si esa
 * semana tiene uno). El selector de la ficha solo ofrece semanas válidas;
 * esta ley vigila que no se cuele un extra imposible por otro camino o que
 * un cierre posterior lo haya dejado colgando.
 */
final class ExtraOnDeliverableWeekInvariant extends AbstractInvariant
{
    public function __construct(
        EntityManagerInterface $em,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
    ) {
        parent::__construct($em);
    }

    public function code(): string
    {
        return 'L25';
    }

    public function name(): string
    {
        return 'Cesta extra solo en semanas en que su nodo reparte';
    }

    public function check(\DateTimeImmutable $from): array
    {
        /** @var PartnerBasketExtra[] $extras */
        $extras = $this->em->createQuery(
            'SELECT x, p, b FROM ' . PartnerBasketExtra::class . ' x
             JOIN x.partner p
             JOIN x.basket b
             WHERE b.date >= :from'
        )->setParameter('from', $from)->getResult();

        if ($extras === []) {
            return [];
        }

        /** @var PartnerNodeOverride[] $overrides */
        $overrides = $this->em->createQuery(
            'SELECT o, g, n FROM ' . PartnerNodeOverride::class . ' o
             JOIN o.targetGroup g
             LEFT JOIN g.node n
             JOIN o.basket b
             WHERE b.date >= :from'
        )->setParameter('from', $from)->getResult();
        $overrideNode = [];
        foreach ($overrides as $o) {
            $overrideNode[$o->getPartner()->getId() . '-' . $o->getBasket()->getId()] = $o->getTargetNode();
        }

        $violations = [];
        foreach ($extras as $extra) {
            $partner = $extra->getPartner();
            $basket = $extra->getBasket();
            $key = $partner->getId() . '-' . $basket->getId();
            $node = $overrideNode[$key] ?? $partner->getWeeklyBasketGroup()?->getNode();

            if ($node === null) {
                $violations[] = sprintf(
                    '%s (%d): cesta extra en la semana del %s pero el socio no tiene nodo.',
                    $partner->getName(),
                    $partner->getId(),
                    $this->d($basket->getDate())
                );
                continue;
            }
            if ($node->getDeliveryWeekday() === null) {
                continue; // Nodo legacy: no se puede juzgar.
            }
            if ($this->nodeDeliveryDate->physicalDateFor($basket, $node) === null) {
                $violations[] = sprintf(
                    '%s (%d): cesta extra en la semana del %s, pero el nodo %s no reparte esa semana.',
                    $partner->getName(),
                    $partner->getId(),
                    $this->d($basket->getDate()),
                    $node->getName() ?? '¿?'
                );
            }
        }

        return $violations;
    }
}
