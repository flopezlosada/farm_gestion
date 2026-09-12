<?php

namespace App\Service\Accounting;

use App\Entity\Budget;
use App\Entity\BudgetCategoryGroup;
use App\Repository\AccountEntryRepository;
use App\Repository\BudgetCategoryGroupRepository;
use App\Repository\BudgetLineRepository;

/**
 * Compara lo presupuestado con lo que de verdad ha pasado, y proyecta cómo acabará
 * el año. Es la pantalla que la asociación no tiene: hoy el seguimiento se teclea a
 * mano en una pestaña del Excel, y en 2026 el desfase se descubrió en julio con el
 * año medio gastado.
 *
 * La comparación se hace por GRUPO, con el detalle por partida debajo. El motivo es
 * que la granularidad cambia con los años —un año los sueldos son una línea y al
 * siguiente se abren en nómina, seguridad social e IRPF— y comparar partida contra
 * partida rompería la serie en cuanto alguien añade una. El grupo cuadra siempre.
 */
class BudgetTracker
{
    public function __construct(
        private readonly AccountEntryRepository $entries,
        private readonly BudgetLineRepository $lines,
        private readonly BudgetCategoryGroupRepository $groups,
    ) {
    }

    /**
     * La rejilla completa de un año: por cada grupo, lo previsto y lo real mes a mes,
     * con sus partidas dentro.
     *
     * Cada grupo y cada partida devuelven:
     *  - `budget[1..12]` y `actual[1..12]`: importes con signo (negativo lo que sale)
     *  - `budgetTotal`, `actualTotal`: sumas del año
     *  - `budgetToDate`: lo previsto hasta el mes de corte, que es contra lo que tiene
     *    sentido medir a mitad de año (comparar seis meses de gasto real contra el
     *    presupuesto de doce siempre da la falsa impresión de que sobra dinero)
     *  - `deviation`: real menos previsto hasta la fecha
     *
     * @return array{
     *     year: int,
     *     throughMonth: int,
     *     groups: array<int, array<string, mixed>>,
     *     totals: array<int, array<string, mixed>>
     * }
     */
    public function track(int $year, ?Budget $budget, ?int $throughMonth = null): array
    {
        $throughMonth = $throughMonth ?? $this->lastMonthWithData($year);
        $actualByCategory = $this->entries->totalsByCategoryAndMonth($year);
        $budgetByCategory = $budget !== null ? $this->lines->totalsByCategoryAndMonth($budget) : [];

        $groups = [];
        $totals = [];

        foreach ($this->groups->findAllWithCategories() as $group) {
            if ($group->isTransfer()) {
                continue; // Mover dinero entre cuentas propias no es actividad.
            }

            $categories = [];
            $groupBudget = $this->emptyMonths();
            $groupActual = $this->emptyMonths();

            foreach ($group->getCategories() as $category) {
                $id = (int) $category->getId();
                $budgetMonths = $this->fill($budgetByCategory[$id] ?? []);
                $actualMonths = $this->fill($actualByCategory[$id] ?? []);

                // Una partida sin nada previsto y sin nada movido no aporta una fila.
                if (!$this->hasAnything($budgetMonths) && !$this->hasAnything($actualMonths)) {
                    continue;
                }

                for ($m = 1; $m <= 12; ++$m) {
                    $groupBudget[$m] += $budgetMonths[$m];
                    $groupActual[$m] += $actualMonths[$m];
                }

                $categories[] = $this->row($category->getName(), $budgetMonths, $actualMonths, $throughMonth)
                    + ['id' => $id];
            }

            if ($categories === []) {
                continue;
            }

            $row = $this->row($group->getName(), $groupBudget, $groupActual, $throughMonth);
            $row['kind'] = $group->getKind();
            $row['kindLabel'] = $group->getKindLabel();
            $row['categories'] = $categories;
            $groups[] = $row;

            $kind = $group->getKind();
            $totals[$kind] ??= ['kind' => $kind, 'label' => $group->getKindLabel(), 'budget' => $this->emptyMonths(), 'actual' => $this->emptyMonths()];
            for ($m = 1; $m <= 12; ++$m) {
                $totals[$kind]['budget'][$m] += $groupBudget[$m];
                $totals[$kind]['actual'][$m] += $groupActual[$m];
            }
        }

        foreach ($totals as $kind => $data) {
            $totals[$kind] = $this->row($data['label'], $data['budget'], $data['actual'], $throughMonth)
                + ['kind' => $kind];
        }
        ksort($totals);

        return [
            'year' => $year,
            'throughMonth' => $throughMonth,
            'groups' => $groups,
            'totals' => $totals,
        ];
    }

    /**
     * Cómo va la caja mes a mes: lo que entra menos lo que sale, y el acumulado
     * partiendo del saldo con el que arrancó el año.
     *
     * La proyección es deliberadamente simple: para los meses ya cerrados, lo real;
     * para los que faltan, lo presupuestado. Es exactamente lo que se hace a mano al
     * rehacer el presupuesto a mitad de año, y no pretende adivinar nada más.
     *
     * @return array{
     *     months: array<int, array{month: int, net: float, cumulative: float, projected: bool}>,
     *     endOfYear: float,
     *     lowest: array{month: int, cumulative: float}
     * }
     */
    public function cashFlow(int $year, ?Budget $budget, ?int $throughMonth = null): array
    {
        $throughMonth = $throughMonth ?? $this->lastMonthWithData($year);
        $tracked = $this->track($year, $budget, $throughMonth);

        $actual = $this->emptyMonths();
        $planned = $this->emptyMonths();
        foreach ($tracked['totals'] as $total) {
            for ($m = 1; $m <= 12; ++$m) {
                $actual[$m] += $total['actual'][$m];
                $planned[$m] += $total['budget'][$m];
            }
        }

        $cumulative = $budget !== null ? (float) $budget->getOpeningCash() : 0.0;
        $months = [];
        $lowest = ['month' => 1, 'cumulative' => \PHP_FLOAT_MAX];

        for ($m = 1; $m <= 12; ++$m) {
            $projected = $m > $throughMonth;
            $net = $projected ? $planned[$m] : $actual[$m];
            $cumulative += $net;
            $months[$m] = [
                'month' => $m,
                'net' => round($net, 2),
                'cumulative' => round($cumulative, 2),
                'projected' => $projected,
            ];
            if ($cumulative < $lowest['cumulative']) {
                $lowest = ['month' => $m, 'cumulative' => round($cumulative, 2)];
            }
        }

        return [
            'months' => $months,
            'endOfYear' => round($cumulative, 2),
            'lowest' => $lowest,
        ];
    }

    /**
     * Lo que tendría que costar la cesta para que el año cuadre, y cuántas cestas
     * harían falta al precio actual.
     *
     * Es el cálculo que da sentido a una CSA: el coste del proyecto se reparte entre
     * las cestas comprometidas. Se descuentan los gastos de huevos porque los huevos
     * se cobran aparte, igual que se hace hoy a mano al final del presupuesto.
     *
     * @return array{
     *     expenses: float,
     *     eggExpenses: float,
     *     toCover: float,
     *     basketsNeeded: ?float,
     *     pricePerBasketNeeded: ?float
     * }
     */
    public function basketCost(int $year, ?Budget $budget, float $currentBaskets, float $currentPrice): array
    {
        $tracked = $this->track($year, $budget, 12);

        $expenses = 0.0;
        $eggExpenses = 0.0;
        foreach ($tracked['groups'] as $group) {
            if (!\in_array($group['kind'], [BudgetCategoryGroup::KIND_EXPENSE, BudgetCategoryGroup::KIND_INVESTMENT], true)) {
                continue;
            }
            $amount = abs($group['budgetTotal']);
            $expenses += $amount;
            if (mb_strtoupper($group['name']) === 'HUEVOS') {
                $eggExpenses += $amount;
            }
        }

        $toCover = $expenses - $eggExpenses;

        return [
            'expenses' => round($expenses, 2),
            'eggExpenses' => round($eggExpenses, 2),
            'toCover' => round($toCover, 2),
            'basketsNeeded' => $currentPrice > 0 ? round($toCover / ($currentPrice * 12), 2) : null,
            'pricePerBasketNeeded' => $currentBaskets > 0 ? round($toCover / ($currentBaskets * 12), 2) : null,
        ];
    }

    /**
     * Última mensualidad con apuntes. Es el corte natural del «hasta la fecha»: no
     * tiene sentido contar como ejecutado un mes del que aún no se ha anotado nada.
     */
    private function lastMonthWithData(int $year): int
    {
        $last = 0;
        foreach ($this->entries->totalsByCategoryAndMonth($year) as $months) {
            foreach (array_keys($months) as $month) {
                $last = max($last, (int) $month);
            }
        }

        return $last > 0 ? $last : (int) date('n');
    }

    /**
     * Una fila de la rejilla, con sus sumas del año y hasta el mes de corte.
     *
     * @param array<int, float> $budget
     * @param array<int, float> $actual
     *
     * @return array<string, mixed>
     */
    private function row(string $name, array $budget, array $actual, int $throughMonth): array
    {
        $budgetTotal = array_sum($budget);
        $actualTotal = array_sum($actual);
        $budgetToDate = 0.0;
        $actualToDate = 0.0;
        for ($m = 1; $m <= $throughMonth; ++$m) {
            $budgetToDate += $budget[$m];
            $actualToDate += $actual[$m];
        }

        return [
            'name' => $name,
            'budget' => array_map(static fn (float $v): float => round($v, 2), $budget),
            'actual' => array_map(static fn (float $v): float => round($v, 2), $actual),
            'budgetTotal' => round($budgetTotal, 2),
            'actualTotal' => round($actualTotal, 2),
            'budgetToDate' => round($budgetToDate, 2),
            'actualToDate' => round($actualToDate, 2),
            'deviation' => round($actualToDate - $budgetToDate, 2),
        ];
    }

    /** @return array<int, float> */
    private function emptyMonths(): array
    {
        return array_fill(1, 12, 0.0);
    }

    /**
     * Pasa un mapa disperso de mes => importe a los doce meses completos.
     *
     * @param array<int, string> $sparse
     *
     * @return array<int, float>
     */
    private function fill(array $sparse): array
    {
        $months = $this->emptyMonths();
        foreach ($sparse as $month => $amount) {
            $months[(int) $month] = (float) $amount;
        }

        return $months;
    }

    /** @param array<int, float> $months */
    private function hasAnything(array $months): bool
    {
        foreach ($months as $amount) {
            if (abs($amount) > 0.005) {
                return true;
            }
        }

        return false;
    }
}
