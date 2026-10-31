<?php

namespace App\Service\Accounting\CashCount;

use App\Entity\CashCount;

/**
 * Un arqueo recién registrado y el libro con el que se ha comparado.
 */
final class RecordedCashCount
{
    /**
     * @param CashCount   $count    El arqueo.
     * @param DayBalances $balances El saldo del libro ese día, antes y después de sus apuntes.
     */
    public function __construct(
        public readonly CashCount $count,
        public readonly DayBalances $balances,
    ) {
    }

    /** Si cuadra al céntimo con el libro. */
    public function squares(): bool
    {
        return $this->balances->matches($this->count->getCountedCents());
    }
}
