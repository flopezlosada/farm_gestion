<?php

namespace App\Tests\Service\Accounting\Scenario;

use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\PaySchedule;
use App\Entity\Worker;
use App\Entity\WorkerSalary;
use App\Repository\BudgetCategoryRepository;
use App\Repository\PartnerBasketShareRepository;
use App\Repository\WorkerSalaryRepository;
use App\Service\Accounting\Scenario\ScenarioLeverReader;
use App\Service\Partner\Fee\FeeTotals;
use App\Service\Partner\Fee\MonthlyFeeCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Del enlace a las palancas: una casilla vacía es «lo que haya», no un cero; los
 * sueldos sólo existen para quien puede verlos, y las fichas nunca se tocan.
 *
 * Los contratos de hoy, con la forma de los de verdad: 61 cestas equivalentes que pagan
 * 5.740 €, 78 contratos con huevos que pagan 1.110 € y 45 € de transporte.
 */
class ScenarioLeverReaderTest extends TestCase
{
    private const YEAR = 2026;

    /** @var list<WorkerSalary> */
    private array $salaries = [];
    /** @var list<BudgetCategory> */
    private array $categories = [];

    /**
     * Sin tocar nada, ninguna palanca: el escenario es el presupuesto.
     */
    public function testEmptyQueryHasNoLevers(): void
    {
        $form = $this->reader()->read([], self::YEAR, true);

        $this->assertTrue($form['levers']->isEmpty());
        $this->assertSame([], $form['warnings']);
    }

    /**
     * Las pistas son lo de hoy, con los precios como media.
     */
    public function testHintsAreTodaysContracts(): void
    {
        $hints = $this->reader()->read([], self::YEAR, false)['hints'];

        $this->assertSame(61.0, $hints['baskets']);
        $this->assertSame(94.1, $hints['basketPrice']);
        $this->assertSame(78.0, $hints['eggShares']);
        $this->assertSame(14.23, $hints['eggPrice']);
        $this->assertSame(45.0, $hints['transport']);
    }

    /**
     * Subir el precio de la cesta no obliga a teclear lo demás: las casillas vacías
     * valen lo de hoy. Y la coma decimal vale.
     */
    public function testFeesFillTheBlanksWithToday(): void
    {
        $fees = $this->reader()->read(['fees' => ['basket_price' => '110,5', 'eggs' => '']], self::YEAR, false)['levers']->fees;

        $this->assertNotNull($fees);
        $this->assertSame(61.0, $fees->baskets);
        $this->assertSame(110.5, $fees->basketPrice);
        $this->assertSame(78.0, $fees->eggShares);
        $this->assertSame(14.23, $fees->eggPrice);
    }

    /**
     * El mantenimiento necesita las dos casillas: con una sola se avisa y se deja lo
     * presupuestado.
     */
    public function testMaintenanceNeedsBothFields(): void
    {
        $form = $this->reader()->read(['maintenance' => ['families' => '160']], self::YEAR, false);

        $this->assertNull($form['levers']->maintenance);
        $this->assertCount(1, $form['warnings']);

        $both = $this->reader()->read(['maintenance' => ['families' => '160', 'fee' => '25']], self::YEAR, false)['levers'];
        $this->assertSame(4000.0, $both->maintenance?->perCharge());
    }

    /**
     * Quien no es admin no tiene palanca de sueldos aunque el enlace la traiga: ni se
     * consultan.
     */
    public function testSalariesAreInvisibleWithoutAdmin(): void
    {
        $salaries = $this->createMock(WorkerSalaryRepository::class);
        $salaries->expects($this->never())->method('findTouchingYear');

        $form = $this->reader($salaries)->read(['recalc' => '1', 'extra' => [['gross' => '20000', 'ss' => '31']]], self::YEAR, false);

        $this->assertNull($form['levers']->staff);
        $this->assertSame([], $form['staffRows']);
    }

    /**
     * Sin la casilla marcada, las fichas se enseñan pero no cuentan.
     */
    public function testStaffOnlyCountsWhenRecalculating(): void
    {
        $this->addSalary(1, 10, '2025-01-01', null, '21000.00');

        $form = $this->reader()->read([], self::YEAR, true);

        $this->assertNull($form['levers']->staff);
        $this->assertCount(1, $form['staffRows']);
        $this->assertSame('21000.00', $form['staffRows'][0]['gross']);
    }

    /**
     * Se retoca el último tramo de cada uno; los anteriores siguen tal cual. Las
     * fichas de la base de datos no cambian.
     */
    public function testRecalculatingEditsACopyOfTheLastTramo(): void
    {
        // Subida de convenio en abril: los dos tramos tocan el año.
        $old = $this->addSalary(1, 10, '2025-01-01', '2026-03-31', '20000.00');
        $last = $this->addSalary(1, 11, '2026-04-01', null, '21000.00');

        $staff = $this->reader()->read([
            'recalc' => '1',
            'staff' => ['11' => ['gross' => '24.000', 'to' => '2026-11-30']],
        ], self::YEAR, true)['levers']->staff;

        $this->assertNotNull($staff);
        $this->assertCount(1, $staff);
        [$first, $copy] = $staff[0];
        $this->assertSame($old, $first);
        $this->assertNotSame($last, $copy);
        $this->assertSame('24000.00', $copy->getAnnualGross());
        $this->assertSame('2026-11-30', $copy->getValidTo()?->format('Y-m-d'));
        $this->assertSame($last->getWorker(), $copy->getWorker());
        $this->assertSame('21000.00', $last->getAnnualGross());
        $this->assertNull($last->getValidTo());
    }

    /**
     * Un imaginario sin % de Seguridad Social toma el de la última ficha, y sin
     * «desde» empieza el 1 de enero.
     */
    public function testExtraWorkerBorrowsTheLastRate(): void
    {
        $this->addSalary(1, 10, '2025-01-01', null, '21000.00', '31.40');

        $staff = $this->reader()->read([
            'recalc' => '1',
            'extra' => [['gross' => '18000', 'payments' => '12']],
        ], self::YEAR, true)['levers']->staff;

        $this->assertCount(2, $staff ?? []);
        $extra = $staff[1][0];
        $this->assertNull($extra->getWorker());
        $this->assertSame('31.40', $extra->getEmployerSsRate());
        $this->assertSame(PaySchedule::Twelve, $extra->getPayments());
        $this->assertSame('2026-01-01', $extra->getValidFrom()?->format('Y-m-d'));
    }

    /**
     * Un imaginario que no se puede calcular avisa y no cuenta: sin % de Seguridad
     * Social de dónde sacarlo, o acabando antes de empezar.
     */
    public function testBrokenExtraWorkersWarn(): void
    {
        $form = $this->reader()->read([
            'recalc' => '1',
            'extra' => [
                ['gross' => '18000'],
                ['gross' => '18000', 'ss' => '30', 'from' => '2026-06-01', 'to' => '2026-05-01'],
            ],
        ], self::YEAR, true);

        $this->assertSame([], $form['levers']->staff);
        $this->assertCount(2, $form['warnings']);
    }

    /**
     * Un puntual cuenta con partida, mes e importe; a medias, avisa. Los traspasos no
     * se ofrecen: no cambian la tesorería.
     */
    public function testOneOffsNeedCategoryAndMonth(): void
    {
        $tractor = $this->category(BudgetCategoryGroup::KIND_INVESTMENT, 'INVERSIÓN', 'Inversión', 36);
        $transfer = $this->category(BudgetCategoryGroup::KIND_TRANSFER, 'TRASPASOS', 'Traspaso entre cuentas', 39);

        $form = $this->reader()->read(['oneoff' => [
            ['category' => '36', 'month' => '12', 'amount' => '3.000'],
            ['category' => '36', 'month' => '', 'amount' => '500'],
            ['category' => '39', 'month' => '11', 'amount' => '500'],
            ['category' => '36', 'month' => '11', 'amount' => ''],
        ]], self::YEAR, false);

        $this->assertCount(1, $form['levers']->oneOffs);
        $this->assertSame($tractor, $form['levers']->oneOffs[0]->category);
        $this->assertSame(-3000.0, $form['levers']->oneOffs[0]->signedAmount());
        $this->assertCount(2, $form['warnings']);
        $this->assertNotContains($transfer, array_merge(...array_values($form['categories'])));
    }

    /**
     * Un enlace manipulado con un valor suelto donde va una sección no rompe nada.
     */
    public function testScalarSectionsAreIgnored(): void
    {
        $form = $this->reader()->read(['fees' => 'x', 'maintenance' => '1', 'oneoff' => 'y'], self::YEAR, true);

        $this->assertTrue($form['levers']->isEmpty());
    }

    private function reader(?WorkerSalaryRepository $salaries = null): ScenarioLeverReader
    {
        $shares = $this->createMock(PartnerBasketShareRepository::class);
        $shares->method('countActiveBasketEquivalents')->willReturn(61.0);
        $fees = $this->createMock(MonthlyFeeCalculator::class);
        $fees->method('basketTotalsFor')->willReturn(new FeeTotals(574000, 111000, 78, 4500));

        if ($salaries === null) {
            $salaries = $this->createMock(WorkerSalaryRepository::class);
            $salaries->method('findTouchingYear')->willReturn($this->salaries);
        }

        $categories = $this->createMock(BudgetCategoryRepository::class);
        $categories->method('findActive')->willReturn($this->categories);

        return new ScenarioLeverReader($shares, $fees, $salaries, $categories);
    }

    /** Un tramo guardado de un trabajador, con sus ids como si vinieran de la base. */
    private function addSalary(int $workerId, int $salaryId, string $from, ?string $to, string $gross, string $rate = '31.40'): WorkerSalary
    {
        $worker = null;
        foreach ($this->salaries as $existing) {
            if ($existing->getWorker()?->getId() === $workerId) {
                $worker = $existing->getWorker();
            }
        }
        if ($worker === null) {
            $worker = (new Worker())->setName('Trabajador '.$workerId);
            (new \ReflectionProperty(Worker::class, 'id'))->setValue($worker, $workerId);
        }

        $salary = (new WorkerSalary())
            ->setWorker($worker)
            ->setAnnualGross($gross)
            ->setPayments(PaySchedule::Fourteen)
            ->setEmployerSsRate($rate)
            ->setValidFrom(new \DateTimeImmutable($from))
            ->setValidTo($to !== null ? new \DateTimeImmutable($to) : null);
        (new \ReflectionProperty(WorkerSalary::class, 'id'))->setValue($salary, $salaryId);
        $this->salaries[] = $salary;

        return $salary;
    }

    private function category(int $kind, string $groupName, string $name, int $id): BudgetCategory
    {
        $group = (new BudgetCategoryGroup())->setName($groupName)->setKind($kind);
        $category = (new BudgetCategory())->setName($name)->setGroup($group);
        (new \ReflectionProperty(BudgetCategory::class, 'id'))->setValue($category, $id);
        $this->categories[] = $category;

        return $category;
    }
}
