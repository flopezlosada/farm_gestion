<?php

namespace App\Tests\Service\Accounting;

use App\Entity\Budget;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Repository\AccountEntryRepository;
use App\Repository\BudgetCategoryGroupRepository;
use App\Repository\BudgetLineRepository;
use App\Service\Accounting\BudgetTracker;
use PHPUnit\Framework\TestCase;

/**
 * Cuánto tiene que costar la cesta: todo lo que sale en el año, menos los huevos,
 * repartido entre las cestas.
 */
class BudgetTrackerBasketCostTest extends TestCase
{
    private int $nextId = 1;
    /** @var list<BudgetCategoryGroup> */
    private array $groups = [];
    /** @var array<int, array<int, string>> */
    private array $lines = [];

    /**
     * El presupuesto 2026 aprobado en asamblea, agregado por grupo. La hoja de la
     * asamblea cierra su cuenta en 114,54 cestas a 100 €: sólo sale si las cuotas del
     * préstamo (2.400 €) cuentan como dinero que tienen que pagar las cestas.
     */
    public function testApprovedBudgetCountsLoanInstalmentsLikeTheAssemblySheet(): void
    {
        $this->category(BudgetCategoryGroup::KIND_INCOME, 'INGRESOS', 'Cuotas de socixs', '146921.30');
        $this->category(BudgetCategoryGroup::KIND_EXPENSE, 'ADMINISTRACIÓN', 'Local', '-9415.00');
        $this->category(BudgetCategoryGroup::KIND_EXPENSE, 'HUERTA', 'Semillas y plantel', '-16500.00');
        $this->category(BudgetCategoryGroup::KIND_EXPENSE, 'HUEVOS', 'Grano y huevos comprados', '-15600.00');
        $this->category(BudgetCategoryGroup::KIND_EXPENSE, 'SUELDOS', 'Nóminas', '-94494.50');
        $this->category(BudgetCategoryGroup::KIND_EXPENSE, 'VARIOS', 'Transporte', '-11280.00');
        $this->category(BudgetCategoryGroup::KIND_INVESTMENT, 'INVERSIÓN', 'Inversión', '-3358.50');
        $financing = $this->category(BudgetCategoryGroup::KIND_FINANCING, 'FINANCIACIÓN', 'Cuotas de préstamo', '-2400.00');
        $this->category(BudgetCategoryGroup::KIND_FINANCING, 'FINANCIACIÓN', 'Préstamos recibidos', null, $financing->getGroup());

        $cost = $this->tracker()->basketCost(2026, new Budget(), 63.76, 100.0);

        $this->assertSame(153048.0, $cost['expenses']);
        $this->assertSame(15600.0, $cost['eggExpenses']);
        $this->assertSame(137448.0, $cost['toCover']);
        $this->assertSame(114.54, $cost['basketsNeeded']);
        $this->assertSame(179.64, $cost['pricePerBasketNeeded']);
    }

    /**
     * Un préstamo que entra no abarata la cesta: financiación sólo suma cuando su
     * neto sale. Si el año recibe más préstamo del que devuelve, no cuenta nada.
     */
    public function testFinancingThatBringsMoneyInAddsNothing(): void
    {
        $this->category(BudgetCategoryGroup::KIND_EXPENSE, 'HUERTA', 'Gasolina', '-3000.00');
        $loan = $this->category(BudgetCategoryGroup::KIND_FINANCING, 'FINANCIACIÓN', 'Préstamos recibidos', '10000.00');
        $this->category(BudgetCategoryGroup::KIND_FINANCING, 'FINANCIACIÓN', 'Cuotas de préstamo', '-2400.00', $loan->getGroup());

        $cost = $this->tracker()->basketCost(2026, new Budget(), 10.0, 100.0);

        $this->assertSame(3000.0, $cost['expenses']);
        $this->assertSame(3000.0, $cost['toCover']);
    }

    /**
     * Sin presupuesto no hay nada que repartir, y no se divide por cero cestas.
     */
    public function testWithoutBudgetNothingToCover(): void
    {
        $this->category(BudgetCategoryGroup::KIND_EXPENSE, 'HUERTA', 'Gasolina', null);

        $cost = $this->tracker()->basketCost(2026, null, 0.0, 100.0);

        $this->assertSame(0.0, $cost['expenses']);
        $this->assertSame(0.0, $cost['basketsNeeded']);
        $this->assertNull($cost['pricePerBasketNeeded']);
    }

    /**
     * Una partida con su importe anual previsto en enero (el reparto por meses no
     * cambia la cuenta del año). Con `$amount` null la partida existe pero no lleva
     * nada previsto, como las muchas partidas vacías del presupuesto real.
     */
    private function category(int $kind, string $groupName, string $name, ?string $amount, ?BudgetCategoryGroup $group = null): BudgetCategory
    {
        if ($group === null) {
            $group = (new BudgetCategoryGroup())->setName($groupName)->setKind($kind);
            $this->groups[] = $group;
        }

        $category = (new BudgetCategory())->setName($name)->setGroup($group);
        $id = $this->nextId++;
        (new \ReflectionProperty(BudgetCategory::class, 'id'))->setValue($category, $id);
        $group->getCategories()->add($category);

        if ($amount !== null) {
            $this->lines[$id] = [1 => $amount];
        }

        return $category;
    }

    private function tracker(): BudgetTracker
    {
        $entries = $this->createMock(AccountEntryRepository::class);
        $entries->method('totalsByCategoryAndMonth')->willReturn([]);
        $lines = $this->createMock(BudgetLineRepository::class);
        $lines->method('totalsByCategoryAndMonth')->willReturn($this->lines);
        $groups = $this->createMock(BudgetCategoryGroupRepository::class);
        $groups->method('findAllWithCategories')->willReturn($this->groups);

        return new BudgetTracker($entries, $lines, $groups);
    }
}
