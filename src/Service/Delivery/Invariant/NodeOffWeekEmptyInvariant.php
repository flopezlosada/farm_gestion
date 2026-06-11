<?php

namespace App\Service\Delivery\Invariant;

use App\Entity\Node;
use App\Entity\WeeklyBasket;
use App\Repository\DeliveryExceptionRepository;
use App\Service\Delivery\NodeDeliveryDate;
use Doctrine\ORM\EntityManagerInterface;

/**
 * L19 — Semana "off" del nodo sin entregas: un nodo quincenal solo reparte
 * en semanas alineadas con su ancla (descontando cierres globales). Un
 * WeeklyBasket en una semana en la que su nodo NO reparte por cadencia es
 * estado ilegal. Hermana de L8 (que cubre el caso "no reparte por cierre");
 * para no duplicar el reporte, las semanas con excepción cancelante se
 * dejan a L8.
 */
final class NodeOffWeekEmptyInvariant extends AbstractInvariant
{
    public function __construct(
        EntityManagerInterface $em,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
        private readonly DeliveryExceptionRepository $exceptionRepository,
    ) {
        parent::__construct($em);
    }

    public function code(): string
    {
        return 'L19';
    }

    public function name(): string
    {
        return 'Sin entregas en la semana off del nodo quincenal';
    }

    public function check(\DateTimeImmutable $from): array
    {
        /** @var WeeklyBasket[] $weeklyBaskets */
        $weeklyBaskets = $this->em->createQuery(
            'SELECT wb, b, p, g, n FROM ' . WeeklyBasket::class . ' wb
             JOIN wb.basket b
             JOIN wb.partner p
             JOIN wb.weekly_basket_group g
             JOIN g.node n
             WHERE b.date >= :from AND n.cadence = :biweekly'
        )->setParameter('from', $from)
         ->setParameter('biweekly', Node::CADENCE_BIWEEKLY)
         ->getResult();

        $violations = [];
        foreach ($weeklyBaskets as $wb) {
            $node = $wb->getWeeklyBasketGroup()->getNode();
            $basket = $wb->getBasket();
            if ($node->getDeliveryWeekday() === null || $node->getAnchorDate() === null) {
                continue; // Nodo legacy mal configurado: no se puede juzgar la cadencia.
            }
            if ($this->nodeDeliveryDate->physicalDateFor($basket, $node) !== null) {
                continue; // El nodo reparte: legal.
            }
            if ($this->exceptionRepository->findForBasketAndNode($basket, $node)?->isCancelled() ?? false) {
                continue; // No reparte por CIERRE: eso lo reporta L8.
            }
            $violations[] = sprintf(
                '%s (%d): WB en la semana del %s, pero el nodo %s (quincenal) no reparte esa semana.',
                $wb->getPartner()?->getName(),
                $wb->getPartner()?->getId(),
                $this->d($basket->getDate()),
                $node->getName() ?? '¿?'
            );
        }

        return $violations;
    }
}
