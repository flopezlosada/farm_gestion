<?php

namespace App\Service\Accounting;

use App\Entity\Worker;
use App\Entity\WorkerSalary;

/**
 * Lo que cuestan los sueldos cada mes de un año, sacado de los tramos de
 * {@see WorkerSalary}. Es la previsión con la que se simula el año: el libro dice lo
 * que se pagó, esto lo que toca pagar.
 *
 * Cada mes son dos cosas que se pagan por separado:
 *  - el BRUTO de las nóminas (lo que cobra la persona más lo que se le retiene de IRPF
 *    y de su Seguridad Social): la nómina ordinaria y, con 14 pagas, las extras;
 *  - la SEGURIDAD SOCIAL de la empresa, encima del bruto.
 *
 * Reglas, en proporción a los días del mes que cubre cada tramo:
 *  - nómina ordinaria = bruto anual / nº de pagas;
 *  - Seguridad Social = bruto anual / 12 × %: las extras cotizan prorrateadas todos los
 *    meses, se cobren cuando se cobren;
 *  - paga extra (sólo 14 pagas) = una nómina ordinaria, proporcional a los días
 *    trabajados en su semestre: la de julio se gana de enero a junio y la de diciembre
 *    de julio a diciembre. Si el contrato acaba antes, va en el finiquito, el mes en que
 *    acaba.
 *
 * Todo va al mes en que se devenga. En el banco la Seguridad Social sale el mes
 * siguiente y el IRPF cada trimestre, pero el dinero es el mismo y el desfase no cambia
 * cómo acaba el año.
 */
class PayrollCost
{
    /** Los dos semestres de las pagas extra: primer mes, último mes y mes en que se pagan. */
    private const SEMESTERS = [[1, 6, 7], [7, 12, 12]];

    /**
     * El coste de cada trabajador mes a mes: `[workerId => ['worker' => Worker,
     * 'gross' => [1..12 => €], 'ss' => [1..12 => €]]]`, en el orden en que llegan
     * los tramos. Los importes van redondeados al céntimo.
     *
     * @param list<WorkerSalary> $salaries los tramos que tocan el año, de quien sea
     *
     * @return array<int, array{worker: Worker, gross: array<int, float>, ss: array<int, float>}>
     */
    public function forYear(array $salaries, int $year): array
    {
        $byWorker = [];
        foreach ($salaries as $salary) {
            $byWorker[(int) $salary->getWorker()?->getId()][] = $salary;
        }

        return array_map(fn (array $workerSalaries) => $this->forWorker($workerSalaries, $year), $byWorker);
    }

    /**
     * El año de un solo trabajador. Los tramos no tienen por qué estar guardados ni
     * tener ficha: así se calcula también alguien que todavía no existe (¿y si
     * contratamos a otra persona?), con su trabajador a null.
     *
     * @param non-empty-list<WorkerSalary> $salaries sus tramos
     *
     * @return array{worker: ?Worker, gross: array<int, float>, ss: array<int, float>}
     */
    public function forWorker(array $salaries, int $year): array
    {
        usort($salaries, static fn (WorkerSalary $a, WorkerSalary $b) => $a->getValidFrom() <=> $b->getValidFrom());

        $gross = array_fill(1, 12, 0.0);
        $ss = array_fill(1, 12, 0.0);

        foreach ($salaries as $salary) {
            for ($m = 1; $m <= 12; ++$m) {
                $share = self::coveredShare($salary, self::monthStart($year, $m), self::monthEnd($year, $m));
                $gross[$m] += $salary->getMonthlyGross() * $share;
                $ss[$m] += $salary->getMonthlyContributionBase() * $salary->getEmployerSsFraction() * $share;
            }

            if (!$salary->hasSeparateExtraPays()) {
                continue;
            }
            $stintEnd = self::stintEnd($salary, $salaries);
            foreach (self::SEMESTERS as [$first, $last, $payMonth]) {
                $share = self::coveredShare($salary, self::monthStart($year, $first), self::monthEnd($year, $last));
                if ($share === 0.0) {
                    continue;
                }
                $paidIn = $stintEnd !== null && $stintEnd < self::monthStart($year, $payMonth) ? (int) $stintEnd->format('n') : $payMonth;
                // La extra ya cotizó prorrateada mes a mes: no suma Seguridad Social.
                $gross[$paidIn] += $salary->getMonthlyGross() * $share;
            }
        }

        return [
            'worker' => $salaries[0]->getWorker(),
            'gross' => array_map(static fn (float $v) => round($v, 2), $gross),
            'ss' => array_map(static fn (float $v) => round($v, 2), $ss),
        ];
    }

    /**
     * Qué parte de un periodo cubre el tramo, de 0 a 1, contando días.
     */
    private static function coveredShare(WorkerSalary $salary, \DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $start = max($salary->getValidFrom(), $from);
        $end = $salary->getValidTo() === null ? $to : min($salary->getValidTo(), $to);
        if ($end < $start) {
            return 0.0;
        }

        return self::days($start, $end) / self::days($from, $to);
    }

    /**
     * Cuándo deja de trabajar la persona si sigue encadenando tramos desde éste: el
     * final del último tramo pegado (el siguiente empieza al día siguiente), o null si
     * sigue.
     *
     * @param list<WorkerSalary> $salaries sus tramos, ordenados por fecha de inicio
     */
    private static function stintEnd(WorkerSalary $salary, array $salaries): ?\DateTimeImmutable
    {
        $end = $salary->getValidTo();
        foreach ($salaries as $next) {
            if ($end !== null && $next->getValidFrom() == $end->modify('+1 day')) {
                $end = $next->getValidTo();
            }
        }

        return $end;
    }

    /** Días entre dos fechas, ambas incluidas. */
    private static function days(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return $from->diff($to)->days + 1;
    }

    /** Primer día de un mes. */
    private static function monthStart(int $year, int $month): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%d-%02d-01', $year, $month));
    }

    /** Último día de un mes. */
    private static function monthEnd(int $year, int $month): \DateTimeImmutable
    {
        return self::monthStart($year, $month)->modify('last day of this month');
    }
}
