<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\WeeklyBasket;
use App\Service\Delivery\NodeDeliveryDate;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L9 — Fecha física = día real del nodo: la fecha grabada en cada entrega
 * (delivery_date) debe ser el día en que el NODO del socio reparte de verdad
 * esa semana (miércoles en Madrid, festivo trasladado incluido), no el
 * viernes-etiqueta del ciclo (Basket.date). Caza fechas sin recalcular tras
 * un traslado de nodo, un festivo o un cambio de nodo.
 *
 * Nodos legacy sin delivery_weekday usan el viernes del ciclo como fallback
 * (mismo criterio que el generador). Si el nodo no reparte esa semana, la
 * existencia del WB la juzgan L8 (cierre) y L19 (cadencia), no esta ley.
 */
final class PhysicalDateMatchesNodeInvariant extends AbstractInvariant
{
    public function __construct(
        EntityManagerInterface $em,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
    ) {
        parent::__construct($em);
    }

    public function code(): string
    {
        return 'L9';
    }

    public function name(): string
    {
        return 'Fecha física de la entrega = día real del nodo';
    }

    public function check(\DateTimeImmutable $from): array
    {
        /** @var WeeklyBasket[] $weeklyBaskets */
        $weeklyBaskets = $this->em->createQuery(
            'SELECT wb, b, p, g, n FROM ' . WeeklyBasket::class . ' wb
             JOIN wb.basket b
             JOIN wb.partner p
             LEFT JOIN wb.weekly_basket_group g
             LEFT JOIN g.node n
             WHERE b.date >= :from'
        )->setParameter('from', $from)->getResult();

        $violations = [];
        foreach ($weeklyBaskets as $wb) {
            $node = $wb->getWeeklyBasketGroup()?->getNode();
            $basket = $wb->getBasket();

            if ($node === null || $node->getDeliveryWeekday() === null) {
                // Legacy: el generador usa la fecha del ciclo tal cual.
                $expected = $basket->getDate();
            } else {
                $expected = $this->nodeDeliveryDate->physicalDateFor($basket, $node);
                if ($expected === null) {
                    continue; // El nodo no reparte: lo juzgan L8/L19.
                }
            }

            $actual = $wb->getDeliveryDate();
            if ($actual === null || $actual->format('Y-m-d') !== $expected->format('Y-m-d')) {
                $violations[] = sprintf(
                    '%s (%d): entrega de la semana del %s con fecha %s, el nodo %s reparte el %s.',
                    $wb->getPartner()?->getName(),
                    $wb->getPartner()?->getId(),
                    $this->d($basket->getDate()),
                    $actual === null ? 'SIN FECHA' : $this->d($actual),
                    $node?->getName() ?? '(sin nodo)',
                    $this->d($expected)
                );
            }
        }

        return $violations;
    }
}
