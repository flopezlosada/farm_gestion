<?php

namespace App\Service\Accounting\Norma43;

use App\Entity\AccountEntry;
use App\Entity\BankStatement;
use App\Entity\BankStatementLine;
use App\Entity\IssuedInvoice;

/**
 * El cuadre de un extracto con el libro: los saldos de los dos al principio y al final,
 * los movimientos del banco que el libro no tiene y los apuntes que el banco no trae.
 * Todo en céntimos con signo.
 */
final class StatementReview
{
    /**
     * @param BankStatement                                                     $statement
     * @param int|null                                                          $bookOpeningCents Saldo del libro antes del primer día; null si el libro empieza después.
     * @param int|null                                                          $bookClosingCents Saldo del libro al acabar el último día; null si el libro empieza después.
     * @param list<array{line: BankStatementLine, candidates: list<AccountEntry>}> $unlinked   Movimientos sin apunte, con los que podrían serlo.
     * @param list<AccountEntry>                                                $unbanked         Apuntes de esas fechas sin movimiento ni propuesta.
     * @param int                                                               $linkedCount      Movimientos ya ligados a su apunte.
     */
    public function __construct(
        public readonly BankStatement $statement,
        public readonly ?int $bookOpeningCents,
        public readonly ?int $bookClosingCents,
        public readonly array $unlinked,
        public readonly array $unbanked,
        public readonly int $linkedCount,
    ) {
    }

    /** Lo que el libro tiene de más (positivo) o de menos (negativo) al empezar; null si no se compara. */
    public function openingDiffCents(): ?int
    {
        return $this->bookOpeningCents !== null ? $this->bookOpeningCents - IssuedInvoice::toCents($this->statement->getOpeningBalance()) : null;
    }

    /** Lo mismo al acabar. */
    public function closingDiffCents(): ?int
    {
        return $this->bookClosingCents !== null ? $this->bookClosingCents - IssuedInvoice::toCents($this->statement->getClosingBalance()) : null;
    }

    /** Si el libro y el banco dicen lo mismo al principio y al final. */
    public function isBalanced(): bool
    {
        return $this->openingDiffCents() === 0 && $this->closingDiffCents() === 0;
    }
}
