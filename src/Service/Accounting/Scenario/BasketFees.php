<?php

namespace App\Service\Accounting\Scenario;

/**
 * La palanca de las cuotas de socixs: lo que entra cada mes por cestas, huevos y
 * transporte, con la misma cuenta que la hoja del presupuesto (cestas × precio +
 * huevos × precio + transporte).
 */
final class BasketFees
{
    /**
     * @param float $baskets     cestas completas equivalentes
     * @param float $basketPrice lo que paga cada una al mes
     * @param float $eggShares   contratos con huevos
     * @param float $eggPrice    lo que paga cada uno al mes por los huevos
     * @param float $transport   lo que se cobra al mes por transporte, todo junto
     */
    public function __construct(
        public readonly float $baskets,
        public readonly float $basketPrice,
        public readonly float $eggShares,
        public readonly float $eggPrice,
        public readonly float $transport,
    ) {
    }

    /** Lo que entra al mes por cuotas. */
    public function monthly(): float
    {
        return $this->baskets * $this->basketPrice + $this->eggShares * $this->eggPrice + $this->transport;
    }
}
