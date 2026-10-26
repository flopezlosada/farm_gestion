<?php

namespace App\Service\Accounting\Scenario;

use App\Entity\BudgetCategory;

/**
 * Un ingreso o gasto puntual que se SUMA a un mes del escenario: un tractor, una
 * subvención… El signo lo pone el grupo de la partida, como en la rejilla del
 * presupuesto; sólo en financiación, que admite los dos, vale el que se escriba.
 */
final class OneOff
{
    public function __construct(
        public readonly BudgetCategory $category,
        public readonly int $month,
        public readonly float $amount,
    ) {
    }

    /** El importe con el signo que le toca: negativo si el dinero sale. */
    public function signedAmount(): float
    {
        $sign = $this->category->getGroup()?->expectedSign() ?? 0;

        return $sign === 0 ? $this->amount : $sign * abs($this->amount);
    }
}
