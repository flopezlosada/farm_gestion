<?php

namespace App\Service\Accounting\Suggestion;

use App\Entity\BudgetCategory;

/**
 * La partida que se propone para un movimiento del banco y en cuántos apuntes
 * anteriores se basa, para decírselo a quien anota.
 */
final class CategorySuggestion
{
    /**
     * @param BudgetCategory $category La partida.
     * @param int            $times    Cuántos apuntes con el mismo concepto la llevaban.
     */
    public function __construct(
        public readonly BudgetCategory $category,
        public readonly int $times,
    ) {
    }
}
