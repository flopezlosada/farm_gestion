<?php

namespace App\Service\Accounting\Scenario;

/**
 * La palanca de la cuota de mantenimiento: cada familia paga una cuota por semestre,
 * en los meses en que el presupuesto la cobra.
 */
final class MaintenanceFee
{
    /**
     * @param float $families las familias que la pagan
     * @param float $fee      lo que paga cada una en cada cobro
     */
    public function __construct(
        public readonly float $families,
        public readonly float $fee,
    ) {
    }

    /** Lo que entra en cada cobro. */
    public function perCharge(): float
    {
        return $this->families * $this->fee;
    }
}
