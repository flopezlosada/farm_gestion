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

    /**
     * La tesorería con que arranca la rejilla del presupuesto en el año en curso es la
     * del banco, aunque la tecleada diga otra cosa: en 2026 la revisión del
     * presupuesto partía de 41.496 € y las cuentas de 39.064 €, y las dos pantallas
     * daban un fin de año distinto.
     */
    public function testOpeningCashOfTheCurrentYearIsTheBanks(): void
    {
        $entries = $this->createMock(AccountEntryRepository::class);
        $entries->method('cashByMonthEnd')->willReturn([39064.47] + array_fill(1, 12, 0.0));

        $opening = $this->tracker($entries)->openingCash((int) date('Y'), (new Budget())->setOpeningCash('41496.30'));

        $this->assertSame(['amount' => 39064.47, 'fromAccounts' => true], $opening);
    }

    /**
     * Un año que aún no ha empezado no tiene saldo real: vale la tesorería tecleada.
     */
    public function testOpeningCashOfAFutureYearIsTheTypedOne(): void
    {
        $entries = $this->createMock(AccountEntryRepository::class);
        $entries->expects($this->never())->method('cashByMonthEnd');

        $opening = $this->tracker($entries)->openingCash((int) date('Y') + 1, (new Budget())->setOpeningCash('8500.00'));

        $this->assertSame(['amount' => 8500.0, 'fromAccounts' => false], $opening);
    }

    /**
     * Un año anterior a llevar las cuentas en la aplicación (sin ninguna cuenta
     * abierta) tampoco: vale la tecleada.
     */
    public function testOpeningCashWithoutAccountsIsTheTypedOne(): void
    {
        $entries = $this->createMock(AccountEntryRepository::class);
        $entries->method('cashByMonthEnd')->willReturn(null);

        $opening = $this->tracker($entries)->openingCash(2020, (new Budget())->setOpeningCash('12225.00'));

        $this->assertSame(['amount' => 12225.0, 'fromAccounts' => false], $opening);
    }

    /**
     * Sin presupuesto ni cuentas se arranca de cero, no de un error.
     */
    public function testOpeningCashWithNothingIsZero(): void
    {
        $entries = $this->createMock(AccountEntryRepository::class);
        $entries->method('cashByMonthEnd')->willReturn(null);

        $this->assertSame(['amount' => 0.0, 'fromAccounts' => false], $this->tracker($entries)->openingCash(2020, null));
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
