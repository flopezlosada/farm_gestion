<?php

namespace App\Service\Accounting\Scenario;

use App\Entity\Budget;
use App\Service\Accounting\BudgetTracker;
use App\Service\Accounting\PayrollCost;

/**
 * ¿Y si…? Cómo acabaría la tesorería del año si cambia algo: más cestas, otro precio,
 * un trabajador más, un tractor en marzo.
 *
 * La BASE es la misma proyección que «cómo vamos»: lo real del libro hasta el último
 * mes anotado y lo presupuestado el resto. El ESCENARIO cambia sólo los meses que
 * faltan —lo pasado ya pasó— y sólo lo que tocan las palancas; todo lo demás sigue
 * siendo lo presupuestado. Así la diferencia entre las dos líneas es exactamente lo que
 * se ha cambiado, y nada más.
 *
 * No guarda nada: un escenario es su enlace. Si convence, se duplica el presupuesto y
 * se teclean las cifras.
 */
class ScenarioSimulator
{
    /** La partida que mueve la palanca de cuotas: [grupo, partida]. */
    public const FEES_CATEGORY = ['INGRESOS', 'Cuotas de socixs'];

    /** La partida que mueve la palanca de mantenimiento: [grupo, partida]. */
    public const MAINTENANCE_CATEGORY = ['INGRESOS', 'Cuota de mantenimiento'];

    /**
     * El grupo entero que sustituyen los sueldos de las fichas. Va por grupo porque el
     * presupuesto lo tiene en una línea (Nóminas, con la Seguridad Social dentro) y el
     * libro en tres (Nóminas, Seguridad Social e IRPF); el grupo cuadra siempre.
     */
    public const STAFF_GROUP = 'SUELDOS';

    /** Las palancas, en el orden en que se pintan. */
    public const PARTS = ['fees', 'maintenance', 'staff', 'oneOffs'];

    public function __construct(
        private readonly BudgetTracker $tracker,
        private readonly PayrollCost $payroll,
    ) {
    }

    /**
     * La base y el escenario, mes a mes.
     *
     * Cada mes lleva la tesorería a fin de mes de los dos (`base`, `scenario`), lo que
     * el escenario cambia ese mes (`delta`) y de qué palanca sale cada euro (`parts`,
     * con la clave de {@see PARTS}). `projected` dice si el mes está por venir: sólo
     * esos cambian.
     *
     * @return array{
     *     year: int,
     *     throughMonth: int,
     *     opening: float,
     *     fromAccounts: bool,
     *     months: array<int, array{month: int, projected: bool, base: float, scenario: float, delta: float, parts: array<string, float>}>,
     *     base: array{end: float, lowest: array{month: int, cumulative: float}},
     *     scenario: array{end: float, lowest: array{month: int, cumulative: float}}
     * }
     */
    public function simulate(Budget $budget, ScenarioLevers $levers): array
    {
        $year = (int) $budget->getYear();
        $through = $this->tracker->lastMonthWithData($year);
        $cash = $this->tracker->cashFlow($year, $budget, $through);
        $tracked = $this->tracker->track($year, $budget, $through);

        $parts = $this->parts($tracked['groups'], $levers, $year, $through);

        $months = [];
        $shift = 0.0;
        foreach ($cash['months'] as $m => $month) {
            $monthParts = array_map(static fn (array $part): float => round($part[$m], 2), $parts);
            $delta = round(array_sum($monthParts), 2);
            $shift += $delta;
            $months[$m] = [
                'month' => $m,
                'projected' => $month['projected'],
                'base' => $month['cumulative'],
                'scenario' => round($month['cumulative'] + $shift, 2),
                'delta' => $delta,
                'parts' => $monthParts,
            ];
        }

        return [
            'year' => $year,
            'throughMonth' => $through,
            'opening' => $cash['opening'],
            'fromAccounts' => $cash['fromAccounts'],
            'months' => $months,
            'base' => $this->summary(array_column($months, 'base', 'month')),
            'scenario' => $this->summary(array_column($months, 'scenario', 'month')),
        ];
    }

    /**
     * Lo que cambia cada palanca cada mes, frente a lo presupuestado. Las palancas que
     * no se tocan no salen.
     *
     * @param array<int, array<string, mixed>> $groups la rejilla de {@see BudgetTracker::track()}
     *
     * @return array<string, array<int, float>>
     */
    private function parts(array $groups, ScenarioLevers $levers, int $year, int $through): array
    {
        $future = array_filter(range(1, 12), static fn (int $m): bool => $m > $through);
        $parts = [];

        if ($levers->fees !== null) {
            $planned = $this->plannedCategory($groups, self::FEES_CATEGORY);
            $parts['fees'] = $this->replace($future, $planned, static fn (): float => $levers->fees->monthly());
        }

        if ($levers->maintenance !== null) {
            $planned = $this->plannedCategory($groups, self::MAINTENANCE_CATEGORY);
            // Se cobra cuando el presupuesto la cobra: la palanca cambia cuánto, no cuándo.
            $charged = array_filter($future, static fn (int $m): bool => abs($planned[$m]) > 0.005);
            $parts['maintenance'] = $this->replace($charged, $planned, static fn (): float => $levers->maintenance->perCharge());
        }

        if ($levers->staff !== null) {
            $cost = array_fill(1, 12, 0.0);
            foreach ($levers->staff as $salaries) {
                $person = $this->payroll->forWorker($salaries, $year);
                for ($m = 1; $m <= 12; ++$m) {
                    $cost[$m] += $person['gross'][$m] + $person['ss'][$m];
                }
            }
            $planned = $this->plannedGroup($groups, self::STAFF_GROUP);
            $parts['staff'] = $this->replace($future, $planned, static fn (int $m): float => -$cost[$m]);
        }

        if ($levers->oneOffs !== []) {
            $parts['oneOffs'] = array_fill(1, 12, 0.0);
            foreach ($levers->oneOffs as $oneOff) {
                if ($oneOff->month > $through) {
                    $parts['oneOffs'][$oneOff->month] += $oneOff->signedAmount();
                }
            }
        }

        return $parts;
    }

    /**
     * Lo que cambia sustituir lo presupuestado por otra cifra en unos meses: la cifra
     * nueva menos la vieja, y cero en el resto.
     *
     * @param list<int>          $months  los meses que se sustituyen
     * @param array<int, float>  $planned lo presupuestado
     * @param callable(int): float $value lo nuevo de cada mes
     *
     * @return array<int, float>
     */
    private function replace(array $months, array $planned, callable $value): array
    {
        $delta = array_fill(1, 12, 0.0);
        foreach ($months as $m) {
            $delta[$m] = $value($m) - $planned[$m];
        }

        return $delta;
    }

    /**
     * Lo presupuestado de una partida mes a mes; ceros si no tiene nada.
     *
     * @param array<int, array<string, mixed>> $groups
     * @param array{0: string, 1: string}       $category [grupo, partida]
     *
     * @return array<int, float>
     */
    private function plannedCategory(array $groups, array $category): array
    {
        foreach ($groups as $group) {
            if ($group['name'] !== $category[0]) {
                continue;
            }
            foreach ($group['categories'] as $row) {
                if ($row['name'] === $category[1]) {
                    return $row['budget'];
                }
            }
        }

        return array_fill(1, 12, 0.0);
    }

    /**
     * Lo presupuestado de un grupo entero mes a mes; ceros si no tiene nada.
     *
     * @param array<int, array<string, mixed>> $groups
     *
     * @return array<int, float>
     */
    private function plannedGroup(array $groups, string $name): array
    {
        foreach ($groups as $group) {
            if ($group['name'] === $name) {
                return $group['budget'];
            }
        }

        return array_fill(1, 12, 0.0);
    }

    /**
     * Cómo acaba el año y el peor mes.
     *
     * @param array<int, float> $cumulative tesorería a fin de cada mes
     *
     * @return array{end: float, lowest: array{month: int, cumulative: float}}
     */
    private function summary(array $cumulative): array
    {
        $lowest = array_keys($cumulative, min($cumulative), true)[0];

        return [
            'end' => $cumulative[12],
            'lowest' => ['month' => $lowest, 'cumulative' => $cumulative[$lowest]],
        ];
    }
}
