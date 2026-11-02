<?php

namespace App\Service\Partner\Fee;

use App\Entity\IssuedInvoice;

/**
 * Una parte de lo que cuesta una cesta en el mes (su verdura, sus huevos o su
 * transporte), con la cuenta de la que sale: «3 cestas × 15,00 €».
 *
 * La cuenta sólo se da cuando el importe sale de ella tal cual ({@see self::isMultiplied()}):
 * si la cesta se tuvo parte del mes o tuvo semanas gratis, el importe va prorrateado y
 * una multiplicación limpia mentiría.
 */
final class FeeLinePart
{
    /** El precio de una unidad, en céntimos, o null si el importe no sale de multiplicar. */
    public readonly ?int $unitCents;

    /**
     * @param string $name     «Verdura», «Huevos», «Transporte».
     * @param int    $cents    Lo que se cobra de esta parte en el mes.
     * @param float  $quantity Cuántas unidades: cestas, docenas o repartos.
     * @param string $singular La unidad en singular, «cesta».
     * @param string $plural   La unidad en plural, «cestas».
     * @param bool   $exact    Si el mes se cobra entero (sin prorrateo ni semanas gratis).
     */
    public function __construct(
        public readonly string $name,
        public readonly int $cents,
        public readonly float $quantity,
        private readonly string $singular,
        private readonly string $plural,
        bool $exact,
    ) {
        $unit = $quantity > 0 ? (int) round($cents / $quantity) : null;
        $this->unitCents = $exact && $unit !== null && (int) round($unit * $quantity) === $cents ? $unit : null;
    }

    /** Si el importe es exactamente cantidad × precio de una unidad. */
    public function isMultiplied(): bool
    {
        return $this->unitCents !== null;
    }

    /** «3 cestas», «1 docena», «0,5 docenas». */
    public function getQuantityLabel(): string
    {
        $number = floor($this->quantity) === $this->quantity
            ? (string) (int) $this->quantity
            : number_format($this->quantity, 1, ',', '.');

        return $number . ' ' . ($this->quantity == 1.0 ? $this->singular : $this->plural);
    }

    public function getTotal(): string
    {
        return IssuedInvoice::fromCents($this->cents);
    }

    /** El precio de una unidad en euros, «15.00», o null si no se multiplica. */
    public function getUnitPrice(): ?string
    {
        return $this->unitCents === null ? null : IssuedInvoice::fromCents($this->unitCents);
    }
}
