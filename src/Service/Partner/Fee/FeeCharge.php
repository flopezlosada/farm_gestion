<?php

namespace App\Service\Partner\Fee;

use App\Entity\FeeConcept;
use App\Entity\IssuedInvoice;
use App\Entity\Partner;

/**
 * Una cuota de socix que toca a un hogar este mes: la de inscripción el mes de su
 * primera cesta, o la de mantenimiento en junio y diciembre. Va en el recibo de quien
 * paga la cesta de ese hogar.
 *
 * Sin precio ese día, el importe es cero y {@see $missing} lo dice: la cuota no se
 * puede dar por buena, igual que una cesta sin tarifa.
 */
final class FeeCharge
{
    public function __construct(
        public readonly FeeConcept $concept,
        public readonly Partner $household,
        public readonly int $cents,
        public readonly bool $missing,
    ) {
    }

    /** El importe en euros, «25.00». */
    public function getTotal(): string
    {
        return IssuedInvoice::fromCents($this->cents);
    }
}
