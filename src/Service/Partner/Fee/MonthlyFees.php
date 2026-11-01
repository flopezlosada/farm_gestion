<?php

namespace App\Service\Partner\Fee;

use App\Entity\FeeConcept;
use App\Entity\IssuedInvoice;
use App\Entity\Partner;

/**
 * Las cuotas de todo el mundo en un mes: un {@see FeeStatement} por cada persona que
 * paga, y lo que falta para poder cobrarlas.
 */
final class MonthlyFees
{
    /**
     * @param \DateTimeImmutable       $month      Día 1 del mes.
     * @param list<\DateTimeImmutable> $weeks      Las semanas de reparto del mes (su viernes).
     * @param array<int, FeeStatement> $statements Por id de quien paga.
     */
    public function __construct(
        public readonly \DateTimeImmutable $month,
        public readonly array $weeks,
        private readonly array $statements,
    ) {
    }

    /**
     * Todas, por el nombre de quien paga.
     *
     * @return list<FeeStatement>
     */
    public function getStatements(): array
    {
        $statements = array_values($this->statements);
        usort($statements, static fn (FeeStatement $a, FeeStatement $b): int => strcmp($a->payer->getLegalName(), $b->payer->getLegalName()));

        return $statements;
    }

    /**
     * Todas, por el id de quien paga (quien encabeza su familia).
     *
     * @return array<int, FeeStatement>
     */
    public function getStatementsByPayerId(): array
    {
        return $this->statements;
    }

    /**
     * Lo que suman las cestas de todo el mundo este mes, por concepto, sin los ajustes:
     * verdura, huevos y transporte, y cuántas cestas llevan huevos. Lo que no tiene
     * precio cuenta cero.
     */
    public function getBasketTotals(): FeeTotals
    {
        $lines = array_merge(...array_map(static fn (FeeStatement $s): array => $s->getLines(), array_values($this->statements)));

        return new FeeTotals(
            array_sum(array_map(static fn (FeeLine $l): int => $l->vegCents, $lines)),
            array_sum(array_map(static fn (FeeLine $l): int => $l->eggCents, $lines)),
            \count(array_filter($lines, static fn (FeeLine $l): bool => $l->eggCents > 0)),
            array_sum(array_map(static fn (FeeLine $l): int => $l->transportCents, $lines)),
        );
    }

    /**
     * El recibo en que va lo de una persona este mes: el de su familia (lo paga quien
     * la encabeza) o, si su cesta la paga alguien de fuera, el de esa persona. Null si
     * no tiene nada que pagar.
     */
    public function forPartner(Partner $partner): ?FeeStatement
    {
        $own = $this->statements[self::keyOf($partner->getFamilyHead())] ?? null;
        if ($own !== null) {
            return $own;
        }
        foreach ($this->statements as $statement) {
            foreach ($statement->getLines() as $line) {
                if ($line->share->getPartner() === $partner) {
                    return $statement;
                }
            }
        }

        return null;
    }

    /**
     * La clave con que se guarda el recibo de quien paga: su id, o el objeto si aún no
     * está guardado (en un cálculo sin base de datos).
     */
    public static function keyOf(Partner $payer): int
    {
        return $payer->getId() ?? spl_object_id($payer);
    }

    /**
     * Los conceptos sin precio en alguna cuota del mes.
     *
     * @return list<FeeConcept>
     */
    public function getMissingConcepts(): array
    {
        $missing = [];
        foreach ($this->statements as $statement) {
            foreach ($statement->getMissingConcepts() as $concept) {
                $missing[$concept->value] = $concept;
            }
        }

        return array_values($missing);
    }

    public function getCents(): int
    {
        return array_sum(array_map(static fn (FeeStatement $s): int => $s->getCents(), $this->statements));
    }

    public function getTotal(): string
    {
        return IssuedInvoice::fromCents($this->getCents());
    }

    /** @return list<FeeStatement> Las que se pueden cobrar ya por domiciliación. */
    public function getChargeable(): array
    {
        return array_values(array_filter($this->getStatements(), static fn (FeeStatement $s): bool => $s->isChargeable()));
    }
}
