<?php

namespace App\Service\Partner\Fee;

use App\Entity\FeeConcept;
use App\Entity\IssuedInvoice;
use App\Entity\PartnerBasketShare;

/**
 * Lo que cuesta una cesta en un mes: su verdura, sus huevos y su transporte, por las
 * semanas del mes que se cobran. Si la tuvo todo el mes es la cuota entera; las reglas
 * de cambios y altas a mitad están en {@see MonthlyFeeCalculator}.
 *
 * Los importes ya van prorrateados y en céntimos. Si algún concepto no tenía precio,
 * el importe de esa parte es cero y el concepto sale en {@see $missing}: la cuota no
 * se puede dar por buena.
 *
 * Las semanas en que la cesta es de quien trabaja en la asociación cuentan en
 * {@see $weeks} pero no se cobran ({@see $freeWeeks}); las de quien ya se fue sin que
 * nadie decidiera qué hacer se cobran, pero dejan la cuota sin poder pasar
 * ({@see $undecidedWeeks}).
 *
 * El alta a mitad de una semanal o una quincenal paga medio mes ({@see $halfMonth}):
 * {@see $weeks} es 1 y {@see $monthWeeks} 2, sean cuales sean las cestas que recibe.
 */
final class FeeLine
{
    /**
     * @param list<FeeConcept> $missing
     * @param bool $halfMonth Si es el medio mes de un alta a mitad: $weeks y $monthWeeks son mitades, no semanas.
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
        public readonly bool $halfMonth = false,
    ) {
    }

    /** Qué parte del mes se cobra, para listados: «medio mes», «2 de 4 semanas» o que no recibe cesta. */
    public function getPartLabel(): string
    {
        return match (true) {
            $this->halfMonth => 'medio mes',
            $this->weeks === 0 => 'este mes no recibe cesta',
            default => sprintf('%d de %d semanas', $this->weeks, $this->monthWeeks),
        };
    }

    public function getCents(): int
    {
        return $this->vegCents + $this->eggCents + $this->transportCents;
    }

    public function getTotal(): string
    {
        return IssuedInvoice::fromCents($this->getCents());
    }

    /**
     * Si su importe suma más de una parte (verdura y huevos, o con transporte): el
     * total de la cesta no se entiende sin decir cuánto es cada cosa.
     */
    public function hasSeveralParts(): bool
    {
        return count($this->getParts()) > 1;
    }

    /**
     * Las partes que cobra, cada una con su cuenta: cestas × tarifa de la modalidad,
     * docenas × tarifa de la docena, repartos × precio del reparto. Sin las que salen a
     * cero.
     *
     * @return list<FeeLinePart>
     */
    public function getParts(): array
    {
        $exact = !$this->isPartial() && $this->freeWeeks === 0;
        $amount = (int) $this->share->getAmount();
        $basketShare = $this->share->getBasketShare();
        $deliveries = $basketShare !== null ? (FeeConcept::forBasketShare($basketShare)?->deliveriesPerMonth() ?? 0) : 0;

        return array_values(array_filter([
            new FeeLinePart('Verdura', $this->vegCents, $amount, 'cesta', 'cestas', $exact),
            new FeeLinePart('Huevos', $this->eggCents, $this->share->getEggAmount()?->getDozens() ?? 0.0, 'docena', 'docenas', $exact),
            new FeeLinePart('Transporte', $this->transportCents, $deliveries * $amount, 'reparto', 'repartos', $exact),
        ], static fn (FeeLinePart $p): bool => $p->cents !== 0));
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
