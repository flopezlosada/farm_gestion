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
     *    B-viernes operativo del mes. El orden puede ser NEGATIVO (misma
     *    convención que day_month_order): -1 = su última cesta del mes,
     *    -2 = la penúltima… — así "última cesta" sigue al último reparto
     *    del socio aunque el mes tenga una entrega más de lo habitual.
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
            // Cesta mensual: huevos con la cesta. Emparejamiento PEGAJOSO
            // (ordersServedBy): un cierre no desplaza al mensual de las
            // semanas posteriores; el de la semana cerrada hace fallback.
            return in_array($share->getDayMonthOrder(), $this->monthlyOrder->ordersServedBy($basket, $node), true);
        }

        $order = $share->getEggDayMonthOrder();
        if ($order === null) {
            return false;
        }

        // Semántica pegajosa también aquí: el orden apunta a la lista BASE de
        // entregas del socio en el mes (las semanas canceladas conservan su
        // posición). Si la designada está cancelada, fallback a la siguiente
        // operativa del mes; si no hay siguiente (o el orden excede la lista),
        // a la última operativa — el huevo no salta de mes ni se pierde.
        $deliveries = $this->shareBaselineDeliveriesInMonth($share, $basket, $node);
        if ($deliveries === []) {
            return false;
        }

        // Índice 0-based de la entrega designada. Positivo cuenta desde el
        // principio (N-ésima, acotado a la última); negativo desde el final
        // (-1 = última). El bucle de fallback (adelante y luego atrás) resuelve
        // igual en ambos casos: si la designada está cancelada, busca la
        // siguiente operativa y, si no hay, la anterior.
        $count = count($deliveries);
        $designated = $order > 0
            ? min($order, $count) - 1
            : max($count + $order, 0);
        $resolved = null;
        for ($j = $designated, $n = count($deliveries); $j < $n; $j++) {
            if ($deliveries[$j]['operative']) {
                $resolved = $j;
                break;
            }
        }
        if ($resolved === null) {
            for ($j = $designated - 1; $j >= 0; $j--) {
                if ($deliveries[$j]['operative']) {
                    $resolved = $j;
                    break;
                }
            }
        }

        return $resolved !== null && $deliveries[$resolved]['basket']->getId() === $basket->getId();
    }

    /**
     * Lista BASE de entregas del partner en el mes calendario, según su
     * modalidad (basket_share) y cohorte (delivery_group). "Base" = las semanas
     * canceladas por excepción CONSERVAN su posición en la lista (con
     * operative=false), para que el orden mensual del huevo sea pegajoso: un
     * cierre no renumera las entregas posteriores. La cadencia del nodo sí
     * filtra (una semana fuera de fase no es entrega, ni base ni operativa).
     * Ordenada por fecha ascendente.
     *
     * @return array<int,array{basket:Basket,operative:bool}>
     */
    private function shareBaselineDeliveriesInMonth(PartnerBasketShare $share, Basket $basket, Node $node): array
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

        $deliveries = [];
        foreach ($monthBaskets as $b) {
            if ($this->nodeDeliveryDate->baselineDateFor($b, $node) === null) {
                continue;
            }
            $isDelivery = match ($shareTypeId) {
                self::SHARE_WEEKLY, self::SHARE_HALF, self::SHARE_ONLY_EGG => true,
                self::SHARE_BIWEEKLY => $this->biweeklyCohort->cohortForBasket($b) === $share->getDeliveryGroup(),
                self::SHARE_MONTHLY  => in_array($share->getDayMonthOrder(), $this->monthlyOrder->ordersServedBy($b, $node), true),
                default              => false,
            };
            if (!$isDelivery) {
                continue;
            }
            $deliveries[] = [
                'basket'    => $b,
                'operative' => $this->nodeDeliveryDate->deliversInBasket($b, $node),
            ];
        }

        return $deliveries;
    }
}
