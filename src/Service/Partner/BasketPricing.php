<?php

namespace App\Service\Partner;

use App\Entity\BasketShare;
use App\Entity\EggAmount;
use App\Entity\EggPeriod;
use App\Entity\PartnerBasketShare;

/**
 * Fuente ÚNICA de la cuota mensual de una cesta de socix. Antes la fórmula vivía
 * duplicada en 5 sitios (alta, edición, cambio de modalidad, batería…) y, para
 * los huevos, multiplicaba mal por el nº de CESTAS en vez de por las docenas.
 *
 * - Verdura: precio de la modalidad ({@see BasketShare::getMonthPrice}) × nº de cestas.
 * - Huevos: precio mensual por docena de la FRECUENCIA ({@see EggPeriod::getMonthPrice})
 *   × nº de docenas ({@see EggAmount::getDozens}). Cuota FIJA mensual, no por los
 *   repartos reales del mes (el factor 4/2/1 ya va dentro del precio por docena).
 *
 * Devuelve cadenas con 2 decimales y punto, como las espera la columna DECIMAL.
 */
class BasketPricing
{
    /** Cuota mensual de la verdura: precio de la modalidad × nº de cestas. */
    public function vegMonthPrice(BasketShare $basketShare, int $amount): string
    {
        return $this->money((float) $basketShare->getMonthPrice() * $amount);
    }

    /**
     * Cuota mensual de los huevos: precio/docena de la frecuencia × docenas.
     * Devuelve "0" si el hogar no lleva huevos (sin cantidad o sin frecuencia).
     */
    public function eggMonthPrice(?EggAmount $eggAmount, ?EggPeriod $eggPeriod): string
    {
        if ($eggAmount === null || $eggPeriod === null) {
            return '0';
        }

        return $this->money((float) $eggPeriod->getMonthPrice() * $eggAmount->getDozens());
    }

    /**
     * Recalcula y estampa ambas cuotas en el PBS a partir de su catálogo actual.
     * Pensado para el recálculo masivo al cambiar precios. NO contempla la cesta
     * gratuita (ese caso lo fuerzan a 0 los formularios antes de llamar aquí).
     */
    public function applyTo(PartnerBasketShare $pbs): void
    {
        $basketShare = $pbs->getBasketShare();
        $pbs->setMonthPrice($basketShare !== null ? $this->vegMonthPrice($basketShare, (int) $pbs->getAmount()) : '0');
        $pbs->setEggMonthPrice($this->eggMonthPrice($pbs->getEggAmount(), $pbs->getEggPeriod()));
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
