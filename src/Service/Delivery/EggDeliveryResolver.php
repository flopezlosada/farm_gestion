<?php

namespace App\Service\Delivery;

use App\Entity\Basket;
use App\Entity\PartnerBasketShare;

/**
 * Decide si un PartnerBasketShare recoge huevos en un Basket (viernes) dado.
 *
 * Cruza la periodicidad de huevos (egg_period) con los campos auxiliares:
 *  - Semanal: siempre toca.
 *  - Quincenal: depende de la cohorte A/B del Basket vía BiweeklyCohortResolver
 *    cf delivery_group del PBS.
 *  - Mensual: depende de la posición operativa del Basket en el mes vía
 *    MonthlyOperativeOrderResolver cf egg_day_month_order del PBS.
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

    public function __construct(
        private readonly BiweeklyCohortResolver $biweeklyCohort,
        private readonly MonthlyOperativeOrderResolver $monthlyOrder,
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

    private function deliversBiweekly(PartnerBasketShare $share, Basket $basket): bool
    {
        $group = $share->getDeliveryGroup();
        if ($group === null) {
            return false;
        }
        return $group === $this->biweeklyCohort->cohortForBasket($basket);
    }

    private function deliversMonthly(PartnerBasketShare $share, Basket $basket): bool
    {
        $order = $share->getEggDayMonthOrder();
        if ($order === null) {
            return false;
        }
        return $order === $this->monthlyOrder->operativeOrderInMonth($basket);
    }
}
