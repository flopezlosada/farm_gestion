<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\Node;
use App\Entity\PartnerBasketShare;
use App\Repository\NodeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Decide si un PartnerBasketShare recoge huevos en un Basket (viernes) dado.
 *
 * Cruza la periodicidad de huevos (egg_period) con los campos auxiliares:
 *  - Semanal: siempre toca.
 *  - Quincenal: depende de la cohorte A/B del Basket vía BiweeklyCohortResolver
 *    cf delivery_group del PBS.
 *  - Mensual: el N-ésimo Basket en el mes donde el partner SÍ recoge cesta
 *    (con su modalidad y cohorte). N = egg_day_month_order. Captura el
 *    patrón "huevos en la primera cesta del mes" de los quincenales con
 *    huevos mensuales: 1ª entrega quincenal del partner en el mes, no
 *    1º viernes operativo genérico.
 *
 * Si el socio no tiene huevos (egg_amount o egg_period a null) devuelve false.
 * Si la cohorte/orden no se puede resolver (delivery_group o
 * egg_day_month_order a null para quincenal/mensual respectivamente) devuelve
 * false: el socio queda fuera del listado para revisión manual, en línea con
 * el resto del sistema (cf BiweeklyCohortResolver).
 */
class EggDeliveryResolver
{
    private const EGG_PERIOD_ID_WEEKLY    = 1;
    private const EGG_PERIOD_ID_BIWEEKLY  = 2;
    private const EGG_PERIOD_ID_MONTHLY   = 3;

    /** Modalidades de basket_share, alineadas con WeeklyBasketGenerator. */
    private const SHARE_WEEKLY    = 1;
    private const SHARE_BIWEEKLY  = 2;
    private const SHARE_MONTHLY   = 3;
    private const SHARE_HALF      = 4;
    private const SHARE_ONLY_EGG  = 5;

    public function __construct(
        private readonly BiweeklyCohortResolver $biweeklyCohort,
        private readonly MonthlyOperativeOrderResolver $monthlyOrder,
        private readonly NodeRepository $nodeRepository,
        private readonly NodeDeliveryDate $nodeDeliveryDate,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function delivers(PartnerBasketShare $share, Basket $basket): bool
    {
        if ($share->getEggAmount() === null || $share->getEggPeriod() === null) {
            return false;
        }

        return match ($share->getEggPeriod()->getId()) {
            self::EGG_PERIOD_ID_WEEKLY   => true,
            self::EGG_PERIOD_ID_BIWEEKLY => $this->deliversBiweekly($share, $basket),
            self::EGG_PERIOD_ID_MONTHLY  => $this->deliversMonthly($share, $basket),
            default                      => false,
        };
    }

    /**
     * Huevos quincenales. La "quincena" se determina de dos formas según dónde
     * recoja el socio:
     *  - Nodo BIWEEKLY (Madrid: Cascorro/Midori): la cadencia la marca el propio
     *    nodo (anchor). El socio no tiene cohorte A/B interna (delivery_group NULL);
     *    lleva huevos siempre que el nodo reparte este Basket.
     *  - Nodo WEEKLY (Torremocha): el nodo reparte todas las semanas, así que la
     *    quincena la marca la cohorte A/B del socio (delivery_group).
     *
     * Deuda: lo ideal sería que la cadencia viviera siempre en el socio (cohorte),
     * no en el nodo — ver [[madrid-reconciliacion-state]] (decisión arquitectura).
     */
    private function deliversBiweekly(PartnerBasketShare $share, Basket $basket): bool
    {
        $node = $share->getPartner()?->getWeeklyBasketGroup()?->getNode();
        if ($node !== null && $node->getCadence() === Node::CADENCE_BIWEEKLY) {
            return $this->nodeDeliveryDate->deliversInBasket($basket, $node);
        }

        $group = $share->getDeliveryGroup();
        if ($group === null) {
            return false;
        }
        return $group === $this->biweeklyCohort->cohortForBasket($basket);
    }

    /**
     * Huevos mensuales:
     *  - Si la cesta es mensual: los huevos van con la cesta (1 entrega/mes,
     *    misma semana). Se ignora egg_day_month_order; basta con verificar
     *    que es el viernes mensual del partner.
     *  - Si la cesta es quincenal/semanal: egg_day_month_order indica la
     *    N-ésima entrega de cesta del partner en el mes que lleva huevos.
     *    Ej. quincenal B con egg_day_month_order=1 → huevos en su primer
     *    B-viernes operativo del mes.
     */
    private function deliversMonthly(PartnerBasketShare $share, Basket $basket): bool
    {
        $node = $share->getPartner()?->getWeeklyBasketGroup()?->getNode()
            ?? $this->nodeRepository->findOneBy(['cadence' => Node::CADENCE_WEEKLY]);
        if ($node === null) {
            return false;
        }

        $shareTypeId = $share->getBasketShare()?->getId();

        if ($shareTypeId === self::SHARE_MONTHLY) {
            // Cesta mensual: huevos con la cesta. Sólo entrega en el viernes
            // operativo que coincida con day_month_order y donde el nodo reparte.
            return $this->nodeDeliveryDate->deliversInBasket($basket, $node)
                && $this->monthlyOrder->operativeOrderForNode($basket, $node) === $share->getDayMonthOrder();
        }

        $order = $share->getEggDayMonthOrder();
        if ($order === null) {
            return false;
        }

        $deliveries = $this->shareDeliveriesInMonth($share, $basket, $node);
        foreach ($deliveries as $i => $b) {
            if ($b->getId() === $basket->getId()) {
                return ($i + 1) === $order;
            }
        }
        return false;
    }

    /**
     * Baskets del mes calendario en los que el partner SÍ recoge cesta,
     * según su modalidad (basket_share) y cohorte (delivery_group), filtrando
     * además por cadencia del nodo y excepciones (vía NodeDeliveryDate). El
     * resultado va ordenado por fecha ascendente.
     *
     * @return Basket[]
     */
    private function shareDeliveriesInMonth(PartnerBasketShare $share, Basket $basket, Node $node): array
    {
        $date = $basket->getDate();
        $dql = "SELECT b FROM App\\Entity\\Basket b
                WHERE YEAR(b.date) = :year AND MONTH(b.date) = :month
                ORDER BY b.date ASC";
        $monthBaskets = $this->em->createQuery($dql)
            ->setParameter('year', (int) $date->format('Y'))
            ->setParameter('month', (int) $date->format('m'))
            ->getResult();

        $shareTypeId = $share->getBasketShare()?->getId();

        return array_values(array_filter($monthBaskets, function (Basket $b) use ($share, $node, $shareTypeId) {
            if (!$this->nodeDeliveryDate->deliversInBasket($b, $node)) {
                return false;
            }
            return match ($shareTypeId) {
                self::SHARE_WEEKLY, self::SHARE_HALF, self::SHARE_ONLY_EGG => true,
                self::SHARE_BIWEEKLY => $this->biweeklyCohort->cohortForBasket($b) === $share->getDeliveryGroup(),
                self::SHARE_MONTHLY  => $this->monthlyOrder->operativeOrderForNode($b, $node) === $share->getDayMonthOrder(),
                default              => false,
            };
        }));
    }
}
