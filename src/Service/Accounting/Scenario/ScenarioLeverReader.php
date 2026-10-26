<?php

namespace App\Service\Accounting\Scenario;

use App\Entity\BudgetCategory;
use App\Entity\PaySchedule;
use App\Entity\Worker;
use App\Entity\WorkerSalary;
use App\Repository\BudgetCategoryRepository;
use App\Repository\PartnerBasketShareRepository;
use App\Repository\WorkerSalaryRepository;
use App\Service\Accounting\Amount;

/**
 * Lee las palancas de un escenario del enlace. El escenario no se guarda: vive en la
 * URL, y así se comparte o se vuelve a él con un marcador.
 *
 * Una casilla vacía no es un cero: es «lo que haya». En las cuotas, lo que pagan hoy
 * los contratos activos; en el resto, lo presupuestado. Las pistas que se pintan junto
 * a cada casilla salen de aquí, de los mismos datos que se usan al calcular, para que
 * lo que se ve y lo que se cuenta no se separen.
 */
class ScenarioLeverReader
{
    /** Filas para trabajadores imaginarios. */
    public const EXTRA_ROWS = 3;

    /** Filas para ingresos y gastos puntuales. */
    public const ONE_OFF_ROWS = 4;

    public function __construct(
        private readonly PartnerBasketShareRepository $shares,
        private readonly WorkerSalaryRepository $salaries,
        private readonly BudgetCategoryRepository $categories,
    ) {
    }

    /**
     * Las palancas del enlace y lo que hace falta para pintar el formulario.
     *
     * Los sueldos sólo se leen para quien puede verlos: la suma de los sueldos de dos
     * personas es casi el sueldo de cada una. Para el resto la palanca no existe, ni en
     * la pantalla ni en el cálculo, aunque el enlace la traiga.
     *
     * @param array<array-key, mixed> $query los parámetros de la petición
     *
     * @return array{
     *     levers: ScenarioLevers,
     *     hints: array{baskets: float, basketPrice: float, eggShares: float, eggPrice: float, transport: float},
     *     staffRows: list<array{salary: WorkerSalary, gross: string, to: string}>,
     *     categories: array<string, list<BudgetCategory>>,
     *     warnings: list<string>
     * }
     */
    public function read(array $query, int $year, bool $canSeeSalaries): array
    {
        $warnings = [];
        $hints = $this->hints();
        $categories = $this->oneOffCategories();

        $salaries = $canSeeSalaries ? $this->salaries->findTouchingYear($year) : [];
        $staffRows = $this->staffRows($salaries, self::child($query, 'staff'));
        $staff = null;
        if ($canSeeSalaries && ($query['recalc'] ?? '') === '1') {
            $staff = $this->staff($salaries, $staffRows, self::child($query, 'extra'), $year, $warnings);
        }

        return [
            'levers' => new ScenarioLevers(
                $this->fees(self::child($query, 'fees'), $hints),
                $this->maintenance(self::child($query, 'maintenance'), $warnings),
                $staff,
                $this->oneOffs(self::child($query, 'oneoff'), $categories, $warnings),
            ),
            'hints' => $hints,
            'staffRows' => $staffRows,
            'categories' => $categories,
            'warnings' => $warnings,
        ];
    }

    /**
     * Lo que pagan hoy los contratos activos, en las mismas unidades que la palanca:
     * los precios son la media de lo que paga cada cesta y cada contrato con huevos.
     *
     * @return array{baskets: float, basketPrice: float, eggShares: float, eggPrice: float, transport: float}
     */
    private function hints(): array
    {
        $fees = $this->shares->activeMonthlyFees();
        $baskets = $this->shares->countActiveBasketEquivalents();

        return [
            'baskets' => $baskets,
            'basketPrice' => $baskets > 0 ? round($fees['baskets'] / $baskets, 2) : 0.0,
            'eggShares' => (float) $fees['eggShares'],
            'eggPrice' => $fees['eggShares'] > 0 ? round($fees['eggs'] / $fees['eggShares'], 2) : 0.0,
            'transport' => $fees['transport'],
        ];
    }

    /**
     * La palanca de cuotas, si se ha tocado alguna casilla. Las que se dejan vacías
     * valen lo de hoy: subir el precio de la cesta no obliga a teclear también los
     * huevos.
     *
     * @param array<array-key, mixed>                                                                         $input
     * @param array{baskets: float, basketPrice: float, eggShares: float, eggPrice: float, transport: float} $hints
     */
    private function fees(array $input, array $hints): ?BasketFees
    {
        $fields = ['baskets' => 'baskets', 'basket_price' => 'basketPrice', 'eggs' => 'eggShares', 'egg_price' => 'eggPrice', 'transport' => 'transport'];
        $values = [];
        foreach ($fields as $param => $hint) {
            $values[$hint] = self::number($input[$param] ?? null);
        }
        if (array_filter($values, static fn (?float $v): bool => $v !== null) === []) {
            return null;
        }

        $value = static fn (string $key): float => $values[$key] ?? $hints[$key];

        return new BasketFees($value('baskets'), $value('basketPrice'), $value('eggShares'), $value('eggPrice'), $value('transport'));
    }

    /**
     * La palanca de mantenimiento: necesita las dos casillas, porque no hay de dónde
     * sacar la que falte.
     *
     * @param array<array-key, mixed> $input
     * @param list<string>         $warnings
     */
    private function maintenance(array $input, array &$warnings): ?MaintenanceFee
    {
        $families = self::number($input['families'] ?? null);
        $fee = self::number($input['fee'] ?? null);
        if ($families === null && $fee === null) {
            return null;
        }
        if ($families === null || $fee === null) {
            $warnings[] = 'Cuota de mantenimiento: hacen falta las familias y la cuota; con una sola se deja lo presupuestado.';

            return null;
        }

        return new MaintenanceFee($families, $fee);
    }

    /**
     * Una fila por trabajador con sueldo en el año: su último tramo, el que se puede
     * retocar (bruto y fecha de fin). La casilla viene rellena con lo de la ficha, así
     * que si llega vacía es que alguien la ha vaciado; si no llega, vale la ficha.
     *
     * @param list<WorkerSalary>   $salaries los tramos que tocan el año, por fecha
     * @param array<array-key, mixed> $input
     *
     * @return list<array{salary: WorkerSalary, gross: string, to: string}>
     */
    private function staffRows(array $salaries, array $input): array
    {
        $last = [];
        foreach ($salaries as $salary) {
            $last[(int) $salary->getWorker()?->getId()] = $salary; // Vienen por fecha: gana el último.
        }

        return array_values(array_map(static function (WorkerSalary $salary) use ($input): array {
            $row = self::child($input, (int) $salary->getId());

            return [
                'salary' => $salary,
                'gross' => (string) ($row['gross'] ?? $salary->getAnnualGross()),
                'to' => (string) ($row['to'] ?? $salary->getValidTo()?->format('Y-m-d') ?? ''),
            ];
        }, $last));
    }

    /**
     * Los sueldos del escenario: los tramos de las fichas, con el último de cada uno
     * retocado como diga la pantalla, más las personas imaginarias.
     *
     * Los tramos se copian: los de la base de datos no se tocan nunca, ni siquiera en
     * memoria.
     *
     * @param list<WorkerSalary>                                           $salaries  los tramos que tocan el año
     * @param list<array{salary: WorkerSalary, gross: string, to: string}> $staffRows
     * @param array<array-key, mixed>                                         $extraInput
     * @param list<string>                                                 $warnings
     *
     * @return list<non-empty-list<WorkerSalary>>
     */
    private function staff(array $salaries, array $staffRows, array $extraInput, int $year, array &$warnings): array
    {
        $byWorker = [];
        foreach ($salaries as $salary) {
            $byWorker[(int) $salary->getWorker()?->getId()][(int) $salary->getId()] = $salary;
        }

        $people = [];
        $lastRate = null;
        foreach ($staffRows as $row) {
            $edited = $row['salary'];
            $lastRate = $edited->getEmployerSsRate();
            $tramos = $byWorker[(int) $edited->getWorker()?->getId()] ?? [];
            unset($tramos[(int) $edited->getId()]);

            $gross = self::number($row['gross']) ?? (float) $edited->getAnnualGross();
            $copy = self::salary(
                $edited->getWorker(),
                $gross,
                $edited->getPayments(),
                (string) $edited->getEmployerSsRate(),
                $edited->getValidFrom(),
                self::date($row['to']),
            );
            $people[] = [...array_values($tramos), $copy];
        }

        for ($i = 0; $i < self::EXTRA_ROWS; ++$i) {
            $row = self::child($extraInput, $i);
            $gross = self::number($row['gross'] ?? null);
            if ($gross === null) {
                continue;
            }
            $rate = self::number($row['ss'] ?? null);
            if ($rate === null && $lastRate === null) {
                $warnings[] = sprintf('Trabajador imaginario %d: falta el %% de Seguridad Social; no se cuenta.', $i + 1);
                continue;
            }
            $payments = PaySchedule::tryFrom((int) ($row['payments'] ?? 14)) ?? PaySchedule::Fourteen;
            $from = self::date((string) ($row['from'] ?? '')) ?? new \DateTimeImmutable($year.'-01-01');
            $to = self::date((string) ($row['to'] ?? ''));
            if ($to !== null && $to < $from) {
                $warnings[] = sprintf('Trabajador imaginario %d: acaba antes de empezar; no se cuenta.', $i + 1);
                continue;
            }
            $people[] = [self::salary(null, $gross, $payments, $rate !== null ? (string) $rate : (string) $lastRate, $from, $to)];
        }

        return $people;
    }

    /**
     * Los puntuales bien rellenos: partida conocida, mes de verdad e importe.
     *
     * @param array<array-key, mixed>                $input
     * @param array<string, list<BudgetCategory>> $categories
     * @param list<string>                        $warnings
     *
     * @return list<OneOff>
     */
    private function oneOffs(array $input, array $categories, array &$warnings): array
    {
        $byId = [];
        foreach (array_merge(...array_values($categories)) as $category) {
            $byId[(int) $category->getId()] = $category;
        }

        $oneOffs = [];
        for ($i = 0; $i < self::ONE_OFF_ROWS; ++$i) {
            $row = self::child($input, $i);
            $amount = self::number($row['amount'] ?? null);
            if ($amount === null) {
                continue;
            }
            $category = $byId[(int) ($row['category'] ?? 0)] ?? null;
            $month = (int) ($row['month'] ?? 0);
            if ($category === null || $month < 1 || $month > 12) {
                $warnings[] = sprintf('Puntual %d: falta la partida o el mes; no se cuenta.', $i + 1);
                continue;
            }
            $oneOffs[] = new OneOff($category, $month, $amount);
        }

        return $oneOffs;
    }

    /**
     * Las partidas que admite un puntual, agrupadas por tipo (Ingresos, Gastos…) para
     * el desplegable. Sin traspasos: mover dinero entre cuentas no cambia la tesorería.
     *
     * @return array<string, list<BudgetCategory>>
     */
    private function oneOffCategories(): array
    {
        $grouped = [];
        foreach ($this->categories->findActive() as $category) {
            $group = $category->getGroup();
            if ($group === null || $group->isTransfer()) {
                continue;
            }
            $grouped[$group->getKindLabel()][] = $category;
        }

        return $grouped;
    }

    /**
     * Un tramo de sueldo que no se guarda.
     */
    private static function salary(?Worker $worker, float $gross, PaySchedule $payments, string $rate, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): WorkerSalary
    {
        $salary = (new WorkerSalary())
            ->setAnnualGross(number_format($gross, 2, '.', ''))
            ->setPayments($payments)
            ->setEmployerSsRate($rate)
            ->setValidFrom($from)
            ->setValidTo($to);

        return $worker !== null ? $salary->setWorker($worker) : $salary;
    }

    /**
     * Una sección del enlace (`fees[...]`, `extra[0][...]`…) como array; vacía si no
     * viene o si alguien ha escrito ahí un valor suelto.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function child(array $data, int|string $key): array
    {
        $value = $data[$key] ?? null;

        return \is_array($value) ? $value : [];
    }

    /** Un número tecleado («1.234,5» o «1234.5»), o null si la casilla está vacía o no es un número. */
    private static function number(mixed $raw): ?float
    {
        $parsed = \is_string($raw) ? Amount::parse($raw) : null;

        return $parsed !== null ? (float) $parsed : null;
    }

    /** Una fecha `Y-m-d` de un campo de fecha, o null si está vacío o no es una fecha. */
    private static function date(string $raw): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return $date !== false && $date->format('Y-m-d') === $raw ? $date : null;
    }
}
