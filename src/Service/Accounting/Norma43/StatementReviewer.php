<?php

namespace App\Service\Accounting\Norma43;

use App\Entity\AccountEntry;
use App\Entity\BankStatement;
use App\Repository\AccountEntryRepository;
use App\Repository\BankStatementLineRepository;

/**
 * Cuadra un extracto con el libro de su cuenta. Un número fijo de consultas por
 * extracto, tenga los movimientos que tenga.
 *
 * Saldo inicial: apertura de la cuenta + apuntes ANTERIORES al primer día del extracto,
 * frente al que da el banco. Si no cuadra, el fallo es de antes de este extracto y aquí
 * no se proponen apuntes para taparlo. Si el extracto empieza antes que el libro de la
 * cuenta, no se compara: el libro empieza después.
 *
 * Saldo final: apertura + apuntes hasta el último día, incluido.
 */
class StatementReviewer
{
    public function __construct(
        private readonly AccountEntryRepository $entries,
        private readonly BankStatementLineRepository $lines,
        private readonly BankLineMatcher $matcher,
    ) {
    }

    /** El cuadre del extracto con lo que hay hoy en el libro. */
    public function review(BankStatement $statement): StatementReview
    {
        $account = $statement->getAccount();
        $from = $statement->getFrom();
        $to = $statement->getTo();
        $balances = $this->entries->balancesAroundCents($account, $from, $to);

        $unlinked = $this->matcher->candidatesFor($this->lines->findUnlinkedIn($account, $from, $to));
        $proposed = [];
        foreach ($unlinked as $row) {
            foreach ($row['candidates'] as $entry) {
                $proposed[spl_object_id($entry)] = true;
            }
        }

        return new StatementReview(
            $statement,
            $account->isOpenOn($from) ? $balances['before'] : null,
            $account->isOpenOn($to) ? $balances['through'] : null,
            $unlinked,
            array_values(array_filter(
                $this->entries->findWithoutBankLine([$account], $from, $to),
                static fn (AccountEntry $e): bool => !isset($proposed[spl_object_id($e)]),
            )),
            $this->lines->countLinkedIn($account, $from, $to),
        );
    }
}
