<?php

namespace App\Service\Accounting;

use App\Entity\Absence;
use App\Entity\Worker;
use App\Entity\WorkerSalary;
use Symfony\Component\Clock\ClockInterface;

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
 *
 * BAJA MÉDICA (incapacidad temporal por contingencia común, Régimen General). Sólo las
 * bajas aprobadas ({@see Absence::isApprovedSickLeave()}). Los días se numeran desde el
 * primero de la baja, aunque cruce meses o años. La base reguladora diaria se aproxima
 * con el bruto anual del sueldo de ese día / 365. La prestación:
 *  - días 1 a 3: nada;
 *  - días 4 a 15: el 60 % de la base, y lo paga la empresa;
 *  - desde el 16: la paga la Seguridad Social, el 60 % hasta el día 20 y el 75 % desde
 *    el 21. La empresa la adelanta en la nómina (pago delegado) y se la descuenta de las
 *    cotizaciones, así que a la asociación no le cuesta nada.
 * La Seguridad Social de la empresa se sigue pagando entera durante la baja. Con eso:
 *  - si la asociación COMPLEMENTA hasta el 100 % ({@see Absence::topsUpToFullPay()}),
 *    la persona cobra su sueldo entero y la asociación paga el bruto de siempre, pero
 *    recupera lo que habría pagado la Seguridad Social desde el día 16: eso es
 *    `sickLeaveRefund`, en negativo;
 *  - si NO complementa, cada día de baja deja de pagar su parte de la nómina (y de la
 *    paga extra) y paga sólo el 60 % de la base de los días 4 a 15.
 *
 * Una baja sin fecha de alta se cuenta sólo hasta hoy: del futuro no hay dato, y una
 * previsión no debe abaratarse con una baja que a lo mejor acaba mañana.
 */
class PayrollCost
{
    /** Los dos semestres de las pagas extra: primer mes, último mes y mes en que se pagan. */
    private const SEMESTERS = [[1, 6, 7], [7, 12, 12]];

    /** Días del año con los que se saca la base reguladora diaria del bruto anual. */
    private const DAYS_PER_YEAR = 365;

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * El coste de cada trabajador mes a mes: `[workerId => ['worker' => Worker,
     * 'gross' => [1..12 => €], 'ss' => [1..12 => €], 'sickLeaveRefund' => [1..12 => €],
     * 'total' => [1..12 => €]]]`, en el orden en que llegan los tramos. Los importes van
     * redondeados al céntimo.
     *
     * @param list<WorkerSalary> $salaries   los tramos que tocan el año, de quien sea
     * @param list<Absence>      $sickLeaves las bajas que tocan el año, de quien sea: cada
     *                                       trabajador se queda con las suyas
     *
     * @return array<int, array{worker: Worker, gross: array<int, float>, ss: array<int, float>, sickLeaveRefund: array<int, float>, total: array<int, float>}>
     */
    public function forYear(array $salaries, array $sickLeaves, int $year): array
    {
        $byWorker = [];
        foreach ($salaries as $salary) {
            $byWorker[(int) $salary->getWorker()?->getId()][] = $salary;
        }

        return array_map(fn (array $workerSalaries) => $this->forWorker($workerSalaries, $sickLeaves, $year), $byWorker);
    }

    /**
     * El año de un solo trabajador. Los tramos no tienen por qué estar guardados ni
     * tener ficha: así se calcula también alguien que todavía no existe (¿y si
     * contratamos a otra persona?), con su trabajador a null y, por tanto, sin bajas.
     *
     * `gross` es el bruto de las nóminas, `ss` la Seguridad Social de la empresa,
     * `sickLeaveRefund` lo que la Seguridad Social le devuelve de una baja complementada
     * (cero o negativo) y `total` la suma de los tres: lo que le cuesta a la asociación.
     *
     * @param non-empty-list<WorkerSalary> $salaries   sus tramos
     * @param list<Absence>                $sickLeaves bajas de quien sea: sólo cuentan las
     *                                                 suyas, aprobadas
     *
     * @return array{worker: ?Worker, gross: array<int, float>, ss: array<int, float>, sickLeaveRefund: array<int, float>, total: array<int, float>}
     */
    public function forWorker(array $salaries, array $sickLeaves, int $year): array
    {
        usort($salaries, static fn (WorkerSalary $a, WorkerSalary $b) => $a->getValidFrom() <=> $b->getValidFrom());

        $gross = array_fill(1, 12, 0.0);
        $ss = array_fill(1, 12, 0.0);
        $refund = array_fill(1, 12, 0.0);
        $sickDays = $this->sickDays($salaries, $sickLeaves, $year);

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
                $from = self::monthStart($year, $first);
                $to = self::monthEnd($year, $last);
                $share = self::coveredShare($salary, $from, $to);
                if ($share === 0.0) {
                    continue;
                }
                // Los días de baja sin complemento no generan paga extra: los cubre la
                // prestación, que ya la lleva prorrateada en la base reguladora.
                $unpaid = array_filter($sickDays, static fn (array $d): bool => !$d['toppedUp'] && $d['salary'] === $salary && $d['day'] >= $from && $d['day'] <= $to);
                $share -= \count($unpaid) / self::days($from, $to);
                $paidIn = $stintEnd !== null && $stintEnd < self::monthStart($year, $payMonth) ? (int) $stintEnd->format('n') : $payMonth;
                // La extra ya cotizó prorrateada mes a mes: no suma Seguridad Social.
                $gross[$paidIn] += $salary->getMonthlyGross() * $share;
            }
        }

        foreach ($sickDays as $sick) {
            $m = (int) $sick['day']->format('n');
            $dailyBase = (float) $sick['salary']->getAnnualGross() / self::DAYS_PER_YEAR;
            if ($sick['toppedUp']) {
                $refund[$m] -= $dailyBase * self::socialSecurityBenefitRate($sick['number']);
            } else {
                $dayOfPayslip = $sick['salary']->getMonthlyGross() / self::days(self::monthStart($year, $m), self::monthEnd($year, $m));
                $gross[$m] += $dailyBase * self::employerBenefitRate($sick['number']) - $dayOfPayslip;
            }
        }

        $round = static fn (array $values): array => array_map(static fn (float $v): float => round($v, 2), $values);
        $gross = $round($gross);
        $ss = $round($ss);
        $refund = $round($refund);
        $total = [];
        foreach ($gross as $m => $g) {
            $total[$m] = round($g + $ss[$m] + $refund[$m], 2);
        }

        return [
            'worker' => $salaries[0]->getWorker(),
            'gross' => $gross,
            'ss' => $ss,
            'sickLeaveRefund' => $refund,
            'total' => $total,
        ];
    }

    /**
     * Cada día del año que la persona está de baja con sueldo: el día, el tramo de sueldo
     * que lo cubre, qué número de día de baja es (desde el primero de la baja) y si la
     * asociación lo complementa. Un día de baja sin sueldo no cuesta nada y no sale.
     *
     * @param non-empty-list<WorkerSalary> $salaries   sus tramos
     * @param list<Absence>                $sickLeaves bajas de quien sea
     *
     * @return list<array{day: \DateTimeImmutable, salary: WorkerSalary, number: int, toppedUp: bool}>
     */
    private function sickDays(array $salaries, array $sickLeaves, int $year): array
    {
        $workerId = $salaries[0]->getWorker()?->getId();
        if ($workerId === null) {
            return [];
        }

        $today = $this->clock->now()->setTime(0, 0);
        $yearStart = self::monthStart($year, 1);
        $yearEnd = self::monthEnd($year, 12);
        $days = [];
        foreach ($sickLeaves as $leave) {
            $start = $leave->getStartDate();
            if ($start === null || !$leave->isApprovedSickLeave() || $leave->getWorker()?->getId() !== $workerId) {
                continue;
            }
            $last = min($leave->getEndDateOr($today), $yearEnd);
            for ($day = max($start, $yearStart); $day <= $last; $day = $day->modify('+1 day')) {
                $salary = self::salaryOn($salaries, $day);
                if ($salary !== null) {
                    $days[] = ['day' => $day, 'salary' => $salary, 'number' => self::days($start, $day), 'toppedUp' => $leave->topsUpToFullPay()];
                }
            }
        }

        return $days;
    }

    /**
     * El tramo de sueldo vigente un día, o null si ese día no tiene sueldo.
     *
     * @param list<WorkerSalary> $salaries
     */
    private static function salaryOn(array $salaries, \DateTimeImmutable $day): ?WorkerSalary
    {
        foreach ($salaries as $salary) {
            if ($salary->getValidFrom() <= $day && ($salary->getValidTo() === null || $day <= $salary->getValidTo())) {
                return $salary;
            }
        }

        return null;
    }

    /** Qué parte de la base reguladora paga la empresa el día `$number` de baja. */
    private static function employerBenefitRate(int $number): float
    {
        return $number >= 4 && $number <= 15 ? 0.60 : 0.0;
    }

    /** Qué parte de la base reguladora paga la Seguridad Social el día `$number` de baja. */
    private static function socialSecurityBenefitRate(int $number): float
    {
        return match (true) {
            $number >= 21 => 0.75,
            $number >= 16 => 0.60,
            default => 0.0,
        };
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
