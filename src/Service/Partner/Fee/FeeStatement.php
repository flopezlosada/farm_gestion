<?php

namespace App\Service\Partner\Fee;

use App\Entity\FeeAdjustment;
use App\Entity\FeeConcept;
use App\Entity\IssuedInvoice;
use App\Entity\Partner;

/**
 * Lo que se le cobra a quien paga en un mes: las cestas de su familia (y las que paga
 * de otrxs), más sus ajustes. Es lo que será un recibo de la remesa.
 *
 * Dice también por qué no se le puede cobrar todavía: falta el precio de algo que
 * tiene, no tiene mandato de domiciliación, o el total no sale positivo.
 */
final class FeeStatement
{
    /** @var list<FeeLine> */
    private array $lines = [];

    /** @var list<FeeAdjustment> */
    private array $adjustments = [];

    public function __construct(public readonly Partner $payer)
    {
    }

    public function addLine(FeeLine $line): void
    {
        $this->lines[] = $line;
    }

    public function addAdjustment(FeeAdjustment $adjustment): void
    {
        $this->adjustments[] = $adjustment;
    }

    /** @return list<FeeLine> */
    public function getLines(): array
    {
        return $this->lines;
    }

    /** @return list<FeeAdjustment> */
    public function getAdjustments(): array
    {
        return $this->adjustments;
    }

    public function getBasketsCents(): int
    {
        return array_sum(array_map(static fn (FeeLine $l): int => $l->getCents(), $this->lines));
    }

    public function getAdjustmentsCents(): int
    {
        return array_sum(array_map(static fn (FeeAdjustment $a): int => IssuedInvoice::toCents($a->getAmount()), $this->adjustments));
    }

    public function getCents(): int
    {
        return $this->getBasketsCents() + $this->getAdjustmentsCents();
    }

    public function getTotal(): string
    {
        return IssuedInvoice::fromCents($this->getCents());
    }

    /**
     * Los conceptos sin precio que tiene alguna de sus cestas.
     *
     * @return list<FeeConcept>
     */
    public function getMissingConcepts(): array
    {
        $missing = [];
        foreach ($this->lines as $line) {
            foreach ($line->missing as $concept) {
                $missing[$concept->value] = $concept;
            }
        }

        return array_values($missing);
    }

    /**
     * Por qué no se le puede pasar el recibo tal cual.
     *
     * @return list<string>
     */
    public function getProblems(): array
    {
        $missing = $this->getMissingConcepts();

        return array_values(array_filter([
            $missing !== [] ? 'Falta el precio de ' . implode(', ', array_map(static fn (FeeConcept $c): string => mb_strtolower($c->label()), $missing)) . '.' : null,
            !$this->payer->hasDirectDebit() ? 'No tiene mandato de domiciliación completo (cuenta, referencia y fecha).' : null,
            $missing === [] && $this->getCents() <= 0 ? 'Le sale ' . number_format($this->getCents() / 100, 2, ',', '.') . ' €: no hay nada que cobrarle.' : null,
        ]));
    }

    /** Si se le puede pasar el recibo: tiene todo y algo que cobrar. */
    public function isChargeable(): bool
    {
        return $this->getProblems() === [];
    }

    /**
     * Las personas cuyas cestas paga, sin repetir y sin contarse a sí mismx.
     *
     * @return list<Partner>
     */
    public function getCoveredPartners(): array
    {
        $covered = [];
        foreach ($this->lines as $line) {
            $partner = $line->share->getPartner();
            if ($partner !== null && $partner !== $this->payer) {
                $covered[spl_object_id($partner)] = $partner;
            }
        }

        return array_values($covered);
    }
}
