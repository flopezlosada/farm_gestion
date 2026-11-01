<?php

namespace App\Service\Partner\Fee;

use App\Entity\FeeConcept;
use App\Entity\IssuedInvoice;
use App\Entity\PartnerBasketShare;

/**
 * Lo que cuesta una cesta en un mes: su verdura, sus huevos y su transporte, por las
 * semanas del mes en que la tuvo. Si la tuvo todo el mes es la cuota entera; si
 * cambió de modalidad a mitad, cada cesta paga su parte.
 *
 * Los importes ya van prorrateados y en céntimos. Si algún concepto no tenía precio,
 * el importe de esa parte es cero y el concepto sale en {@see $missing}: la cuota no
 * se puede dar por buena.
 *
 * Las semanas en que la cesta es de quien trabaja en la asociación cuentan en
 * {@see $weeks} pero no se cobran ({@see $freeWeeks}); las de quien ya se fue sin que
 * nadie decidiera qué hacer se cobran, pero dejan la cuota sin poder pasar
 * ({@see $undecidedWeeks}).
 */
final class FeeLine
{
    /**
     * @param list<FeeConcept> $missing
     */
    public function __construct(
        public readonly PartnerBasketShare $share,
        public readonly int $vegCents,
        public readonly int $eggCents,
        public readonly int $transportCents,
        public readonly int $weeks,
        public readonly int $monthWeeks,
        public readonly array $missing,
        public readonly int $freeWeeks = 0,
        public readonly int $undecidedWeeks = 0,
    ) {
    }

    public function getCents(): int
    {
        return $this->vegCents + $this->eggCents + $this->transportCents;
    }

    public function getTotal(): string
    {
        return IssuedInvoice::fromCents($this->getCents());
    }

    /** Si la tuvo sólo parte del mes. */
    public function isPartial(): bool
    {
        return $this->weeks < $this->monthWeeks;
    }

    /** Si no se cobra nada: todas las semanas que la tuvo era cesta de trabajador. */
    public function isFree(): bool
    {
        return $this->weeks > 0 && $this->freeWeeks === $this->weeks;
    }

    /** «Quincenal compartida · Una docena», para listados. */
    public function getLabel(): string
    {
        $basket = $this->share->getBasketShare()?->getName() ?? 'Sin modalidad';
        $amount = (int) $this->share->getAmount();
        $eggs = $this->share->getEggAmount();

        return ($amount > 1 ? $amount . ' × ' : '') . $basket
            . ($eggs !== null ? ' · ' . $eggs->getName() . ($this->share->getEggPeriod() !== null ? ' ' . mb_strtolower($this->share->getEggPeriod()->getName()) : '') : '');
    }
}
