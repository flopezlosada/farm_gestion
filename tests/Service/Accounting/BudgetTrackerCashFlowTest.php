<?php

namespace App\Tests\Service\Accounting;

use App\Entity\Budget;
use App\Repository\AccountEntryRepository;
use App\Repository\BudgetCategoryGroupRepository;
use App\Repository\BudgetLineRepository;
use App\Service\Accounting\BudgetTracker;
use PHPUnit\Framework\TestCase;

/**
 * De dónde arranca la tesorería del año: del saldo real de las cuentas mientras lo
 * haya, y de la del presupuesto cuando no.
 */
class BudgetTrackerCashFlowTest extends TestCase
{
    /**
     * Un año que aún no ha empezado arranca de la tesorería que teclea su presupuesto.
     * El saldo de las cuentas «el 1 de enero» sería el de hoy, sin los meses que faltan.
     */
    public function testFutureYearStartsFromTheBudgetOpeningCash(): void
    {
        $entries = $this->createMock(AccountEntryRepository::class);
        $entries->method('totalsByCategoryAndMonth')->willReturn([]);
        $entries->expects($this->never())->method('cashByMonthEnd');

        $cash = $this->tracker($entries)->cashFlow((int) date('Y') + 1, (new Budget())->setOpeningCash('8500.00'));

        $this->assertFalse($cash['fromAccounts']);
        $this->assertSame(8500.0, $cash['opening']);
        $this->assertTrue($cash['months'][1]['projected']);
    }

    /**
     * El año en curso arranca del saldo real de las cuentas, diga lo que diga el
     * presupuesto.
     */
    public function testCurrentYearStartsFromTheAccounts(): void
    {
        $entries = $this->createMock(AccountEntryRepository::class);
        $entries->method('totalsByCategoryAndMonth')->willReturn([]);
        $entries->method('cashByMonthEnd')->willReturn(array_fill(0, 13, 33460.05));

        $cash = $this->tracker($entries)->cashFlow((int) date('Y'), (new Budget())->setOpeningCash('12225.00'));

        $this->assertTrue($cash['fromAccounts']);
        $this->assertSame(33460.05, $cash['opening']);
    }

    private function tracker(AccountEntryRepository $entries): BudgetTracker
    {
        $lines = $this->createMock(BudgetLineRepository::class);
        $lines->method('totalsByCategoryAndMonth')->willReturn([]);
        $groups = $this->createMock(BudgetCategoryGroupRepository::class);
        $groups->method('findAllWithCategories')->willReturn([]);

        return new BudgetTracker($entries, $lines, $groups);
    }
}
