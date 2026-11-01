<?php

namespace App\Service\Partner\Fee;

use App\Entity\IssuedInvoice;

/**
 * Lo que suman en un mes las cestas de todo el mundo, por concepto y en céntimos: la
 * cuenta de las cuotas que hace el presupuesto (cestas × precio + huevos × precio +
 * transporte).
 */
final class FeeTotals
{
    public function __construct(
        public readonly int $vegCents,
        public readonly int $eggCents,
        public readonly int $eggLines,
        public readonly int $transportCents,
    ) {
    }

    /** Verdura, huevos y transporte juntos, en euros. */
    public function getTotal(): string
    {
        return IssuedInvoice::fromCents($this->vegCents + $this->eggCents + $this->transportCents);
    }
}
