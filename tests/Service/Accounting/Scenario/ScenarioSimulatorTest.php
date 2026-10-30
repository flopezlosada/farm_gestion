<?php

namespace App\Tests\Service\Accounting\Scenario;

use App\Entity\Budget;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\PaySchedule;
use App\Entity\WorkerSalary;
use App\Repository\AccountEntryRepository;
use App\Repository\BudgetCategoryGroupRepository;
use App\Repository\BudgetLineRepository;
use App\Service\Accounting\BudgetTracker;
use App\Service\Accounting\PayrollCost;
use App\Service\Accounting\Scenario\BasketFees;
use App\Service\Accounting\Scenario\MaintenanceFee;
use App\Service\Accounting\Scenario\OneOff;
use App\Service\Accounting\Scenario\ScenarioLevers;
use App\Service\Accounting\Scenario\ScenarioSimulator;
use PHPUnit\Framework\TestCase;

/**
 * Un escenario sólo cambia los meses que faltan y sólo lo que tocan sus palancas: la
 * diferencia con la base es exactamente lo cambiado.
 *
 * Cifras con la forma del presupuesto 2026 aprobado: cuotas de 7.880 € al mes (65
 * cestas a 100, 63 contratos de huevos a 20 y 120 de transporte), mantenimiento de
 * 3.925 € en junio y diciembre y nóminas de 5.118,75 € en otoño. El libro llega a
 * octubre, así que el escenario sólo mueve noviembre y diciembre.
 */
class ScenarioSimulatorTest extends TestCase
{
    private const YEAR = 2026;

    private int $nextId = 1;
    /** @var array<string, BudgetCategoryGroup> */
    private array $groups = [];
    /** @var array<int, array<int, string>> */
    private array $lines = [];
    /** @var array<int, array<int, string>> */
    private array $actuals = [];
    private BudgetCategory $fees;
    private BudgetCategory $maintenance;
    private BudgetCategory $payroll;
    private BudgetCategory $investment;
    private BudgetCategory $loan;

    protected function setUp(): void
    {
        $this->fees = $this->category(BudgetCategoryGroup::KIND_INCOME, 'INGRESOS', 'Cuotas de socixs', array_fill(1, 12, '7880.00'));
        $this->maintenance = $this->category(BudgetCategoryGroup::KIND_INCOME, 'INGRESOS', 'Cuota de mantenimiento', [6 => '3925.00', 12 => '3925.00']);
        $this->payroll = $this->category(BudgetCategoryGroup::KIND_EXPENSE, 'SUELDOS', 'Nóminas', [9 => '-5118.75', 10 => '-5118.75', 11 => '-5118.75', 12 => '-10237.50']);
        // El libro abre los sueldos en tres partidas; el presupuesto no. Cuenta el grupo.
        $this->category(BudgetCategoryGroup::KIND_EXPENSE, 'SUELDOS', 'Seguridad Social', []);
        $this->investment = $this->category(BudgetCategoryGroup::KIND_INVESTMENT, 'INVERSIÓN', 'Inversión', []);
        $this->loan = $this->category(BudgetCategoryGroup::KIND_FINANCING, 'FINANCIACIÓN', 'Préstamos recibidos', []);
        $this->actuals[$this->fees->getId()] = array_fill(1, 10, '7600.00');
    }

    /**
     * Sin palancas el escenario es la base, mes a mes, y no hay nada que explicar.
     */
    public function testWithoutLeversTheScenarioIsTheBase(): void
    {
        $result = $this->simulate(new ScenarioLevers());

        $this->assertSame(10, $result['throughMonth']);
        foreach ($result['months'] as $month) {
            $this->assertSame($month['base'], $month['scenario']);
            $this->assertSame(0.0, $month['delta']);
            $this->assertSame([], $month['parts']);
        }
        $this->assertSame($result['base'], $result['scenario']);
    }

    /**
     * Las cifras de la hoja del presupuesto dan lo presupuestado: cero de diferencia.
     * Con cinco cestas más, 500 € más cada mes que falta y nada en los ya anotados.
     */
    public function testFeesReplaceOnlyTheMonthsToCome(): void
    {
        $same = $this->simulate(new ScenarioLevers(fees: new BasketFees(65, 100, 63, 20, 120)));
        $this->assertSame(0.0, $same['months'][11]['delta']);

        $result = $this->simulate(new ScenarioLevers(fees: new BasketFees(70, 100, 63, 20, 120)));

        $this->assertSame(0.0, $result['months'][10]['parts']['fees']);
        $this->assertSame(500.0, $result['months'][11]['parts']['fees']);
        $this->assertSame(500.0, $result['months'][12]['parts']['fees']);
        $this->assertSame(round($result['base']['end'] + 1000, 2), $result['scenario']['end']);
    }

    /**
     * El mantenimiento se cobra cuando lo cobra el presupuesto: la palanca cambia
     * cuánto, no cuándo. Junio ya pasó; sólo cambia diciembre.
     */
    public function testMaintenanceOnlyInTheMonthsItIsCharged(): void
    {
        $result = $this->simulate(new ScenarioLevers(maintenance: new MaintenanceFee(160, 25)));

        $this->assertSame(0.0, $result['months'][6]['delta']);
        $this->assertSame(0.0, $result['months'][11]['delta']);
        $this->assertSame(75.0, $result['months'][12]['parts']['maintenance']);
    }

    /**
     * Los sueldos sustituyen al grupo entero. Una persona de 21.000 € en 14 pagas al
     * 31,40 % cuesta 1.500 + 549,50 al mes y 3.000 + 549,50 en diciembre, con su extra;
     * frente a lo presupuestado, la diferencia de cada mes.
     */
    public function testStaffReplacesTheWholePayrollGroup(): void
    {
        $person = (new WorkerSalary())
            ->setAnnualGross('21000.00')
            ->setPayments(PaySchedule::Fourteen)
            ->setEmployerSsRate('31.40')
            ->setValidFrom(new \DateTimeImmutable('2025-01-01'));

        $result = $this->simulate(new ScenarioLevers(staff: [[$person]]));

        $this->assertSame(0.0, $result['months'][10]['delta']);
        $this->assertSame(round(5118.75 - 2049.50, 2), $result['months'][11]['parts']['staff']);
        $this->assertSame(round(10237.50 - 3549.50, 2), $result['months'][12]['parts']['staff']);
    }

    /**
     * Sin nadie en plantilla, los sueldos del escenario son cero: se ahorra todo lo
     * presupuestado de los meses que faltan.
     */
    public function testStaffWithNobodySavesThePlannedPayroll(): void
    {
        $result = $this->simulate(new ScenarioLevers(staff: []));

        $this->assertSame(5118.75, $result['months'][11]['parts']['staff']);
        $this->assertSame(10237.5, $result['months'][12]['parts']['staff']);
    }

    /**
     * Un puntual se suma a su mes con el signo de su partida: una inversión sale
     * aunque se teclee en positivo, y en financiación vale el signo escrito. Un mes ya
     * anotado no se toca.
     */
    public function testOneOffsAddWithTheSignOfTheirCategory(): void
    {
        $result = $this->simulate(new ScenarioLevers(oneOffs: [
            new OneOff($this->investment, 12, 3000),
            new OneOff($this->loan, 11, 10000),
            new OneOff($this->loan, 12, -400),
            new OneOff($this->investment, 3, 9999),
        ]));

        $this->assertSame(0.0, $result['months'][3]['delta']);
        $this->assertSame(10000.0, $result['months'][11]['parts']['oneOffs']);
        $this->assertSame(-3400.0, $result['months'][12]['parts']['oneOffs']);
    }

    /**
     * El peor mes es el del escenario: un tractor en noviembre lo lleva allí.
     */
    public function testLowestMonthFollowsTheScenario(): void
    {
        $result = $this->simulate(new ScenarioLevers(oneOffs: [new OneOff($this->investment, 11, 200000)]));

        $this->assertSame(11, $result['scenario']['lowest']['month']);
        $this->assertSame($result['months'][11]['scenario'], $result['scenario']['lowest']['cumulative']);
        $this->assertNotSame(11, $result['base']['lowest']['month']);
    }

    /**
     * Si el presupuesto no tiene la partida (otro catálogo, otro año), la palanca no
     * tiene meses de cobro y no inventa ninguno.
     */
    public function testMaintenanceWithoutThePlannedCategoryChangesNothing(): void
    {
        unset($this->lines[$this->maintenance->getId()]);

        $result = $this->simulate(new ScenarioLevers(maintenance: new MaintenanceFee(160, 25)));

        $this->assertSame(0.0, array_sum(array_column($result['months'], 'delta')));
    }

    /**
     * @param ScenarioLevers $levers
     *
     * @return array<string, mixed>
     */
    private function simulate(ScenarioLevers $levers): array
    {
        $budget = (new Budget())->setYear(self::YEAR)->setName('Aprobado')->setOpeningCash('12225.00');

        return (new ScenarioSimulator($this->tracker(), new PayrollCost()))->simulate($budget, $levers);
    }

    /**
     * Una partida con lo presupuestado de cada mes. Los grupos se reutilizan por nombre.
     *
     * @param array<int, string> $months
     */
    private function category(int $kind, string $groupName, string $name, array $months): BudgetCategory
    {
        $group = $this->groups[$groupName] ??= (new BudgetCategoryGroup())->setName($groupName)->setKind($kind);
        $category = (new BudgetCategory())->setName($name)->setGroup($group);
        $id = $this->nextId++;
        (new \ReflectionProperty(BudgetCategory::class, 'id'))->setValue($category, $id);
        if ($months !== []) {
            $this->lines[$id] = $months;
        }

        return $category;
    }

    private function tracker(): BudgetTracker
    {
        $entries = $this->createMock(AccountEntryRepository::class);
        $entries->method('totalsByCategoryAndMonth')->willReturn($this->actuals);
        // Sin cuentas abiertas: arranca de la tesorería del presupuesto.
        $entries->method('cashByMonthEnd')->willReturn(null);
        $lines = $this->createMock(BudgetLineRepository::class);
        $lines->method('totalsByCategoryAndMonth')->willReturn($this->lines);
        $groups = $this->createMock(BudgetCategoryGroupRepository::class);
        $groups->method('findAllWithCategories')->willReturn(array_values($this->groups));

        return new BudgetTracker($entries, $lines, $groups);
    }
}
