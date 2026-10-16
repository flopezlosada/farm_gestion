<?php

namespace App\Tests\Service\Accounting;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Service\Accounting\TransferPairer;
use PHPUnit\Framework\TestCase;

/**
 * Casar las mitades sueltas de los traspasos: lo que casa sin duda se enlaza y lo
 * dudoso se queda suelto.
 */
class TransferPairerTest extends TestCase
{
    private BudgetCategory $transfer;
    private BudgetCategory $expense;
    /** @var array<string, FinancialAccount> */
    private array $accounts = [];

    protected function setUp(): void
    {
        $this->transfer = (new BudgetCategory())
            ->setName('Traspaso entre cuentas')
            ->setGroup((new BudgetCategoryGroup())->setName('TRASPASOS')->setKind(BudgetCategoryGroup::KIND_TRANSFER));
        $this->expense = (new BudgetCategory())
            ->setName('Gasoil')
            ->setGroup((new BudgetCategoryGroup())->setName('GASTOS')->setKind(BudgetCategoryGroup::KIND_EXPENSE));
    }

    /**
     * Los 20 traspasos del libro de enero-agosto de 2026 tal como los deja el Excel.
     * Casan 8 parejas (una con el abono al día siguiente) y quedan sueltas las cuatro
     * mitades de caja de febrero, cuyos importes no cuadran (−100/+115 y −185/+285).
     */
    public function testRealLedgerPairsWhatMatchesAndLeavesTheMismatchedCashLoose(): void
    {
        $rows = [
            ['2026-02-04', 'Caja local', '-100.00'], ['2026-02-04', 'Caja de la huerta', '115.00'],
            ['2026-02-05', 'Caja local', '-185.00'], ['2026-02-05', 'Caja de la huerta', '285.00'],
            ['2026-02-06', 'La Caixa', '-10000.00'], ['2026-02-06', 'Fiare', '10000.00'],
            ['2026-05-06', 'La Caixa', '-10000.00'], ['2026-05-06', 'Fiare', '10000.00'],
            ['2026-05-24', 'Caja local', '-400.00'], ['2026-05-24', 'Caja de la huerta', '400.00'],
            ['2026-06-16', 'La Caixa', '-5000.00'], ['2026-06-16', 'Fiare', '5000.00'],
            ['2026-07-09', 'La Caixa', '-3000.00'], ['2026-07-09', 'Fiare', '3000.00'],
            ['2026-07-15', 'Caja local', '-500.00'], ['2026-07-15', 'La Caixa', '500.00'],
            ['2026-07-22', 'La Caixa', '-500.00'], ['2026-07-23', 'Fiare', '500.00'],
            ['2026-08-03', 'La Caixa', '-1500.00'], ['2026-08-03', 'Fiare', '1500.00'],
        ];
        $entries = array_map(fn (array $r): AccountEntry => $this->entry(...$r), $rows);

        $unpaired = (new TransferPairer())->pair($entries);

        self::assertSame([$entries[0], $entries[1], $entries[2], $entries[3]], $unpaired);
        for ($i = 4; $i < 20; $i += 2) {
            self::assertSame($entries[$i + 1], $entries[$i]->getTransferPeer(), 'fila '.$i);
            self::assertSame($entries[$i], $entries[$i + 1]->getTransferPeer(), 'fila '.($i + 1));
        }
        // La salida de 500 de caja (15-jul) no se lleva el abono de Fiare del 23-jul.
        self::assertSame('La Caixa', $entries[14]->getTransferPeer()->getAccount()->getName());
    }

    public function testMoreThanTwoDaysApartIsNotPaired(): void
    {
        $out = $this->entry('2026-03-02', 'La Caixa', '-200.00');
        $in = $this->entry('2026-03-05', 'Fiare', '200.00');

        self::assertSame([$out, $in], (new TransferPairer())->pair([$out, $in]));
        self::assertNull($out->getTransferPeer());
    }

    public function testSameAccountIsNotPaired(): void
    {
        $out = $this->entry('2026-03-02', 'Fiare', '-200.00');
        $in = $this->entry('2026-03-02', 'Fiare', '200.00');

        self::assertCount(2, (new TransferPairer())->pair([$out, $in]));
    }

    public function testClosestDateWins(): void
    {
        $out = $this->entry('2026-03-02', 'La Caixa', '-200.00');
        $far = $this->entry('2026-03-04', 'Fiare', '200.00');
        $near = $this->entry('2026-03-03', 'Caja local', '200.00');

        $unpaired = (new TransferPairer())->pair([$out, $far, $near]);

        self::assertSame($near, $out->getTransferPeer());
        self::assertSame([$far], $unpaired);
    }

    /** Dos entradas igual de buenas: no hay forma de saber cuál es, se deja suelta. */
    public function testTieIsLeftLoose(): void
    {
        $out = $this->entry('2026-03-02', 'La Caixa', '-200.00');
        $a = $this->entry('2026-03-02', 'Fiare', '200.00');
        $b = $this->entry('2026-03-02', 'Caja local', '200.00');

        self::assertCount(3, (new TransferPairer())->pair([$out, $a, $b]));
        self::assertNull($out->getTransferPeer());
    }

    public function testIgnoresNonTransfersAndAlreadyPairedHalves(): void
    {
        $expense = $this->entry('2026-03-02', 'Fiare', '200.00', $this->expense);
        $out = $this->entry('2026-03-02', 'La Caixa', '-200.00');
        $pairedIn = $this->entry('2026-03-02', 'Fiare', '200.00');
        $pairedOut = $this->entry('2026-03-02', 'Caja local', '-200.00');
        $pairedOut->pairWith($pairedIn);

        $unpaired = (new TransferPairer())->pair([$expense, $out, $pairedIn, $pairedOut]);

        self::assertSame([$out], $unpaired);
        self::assertNull($expense->getTransferPeer());
        self::assertSame($pairedOut, $pairedIn->getTransferPeer());
    }

    /** Céntimos: 0,1 + 0,2 en float no es 0,3, en céntimos sí. */
    public function testComparesAmountsInCents(): void
    {
        $out = $this->entry('2026-03-02', 'La Caixa', '-1234.30');
        $in = $this->entry('2026-03-02', 'Fiare', '1234.3');

        self::assertSame([], (new TransferPairer())->pair([$out, $in]));
    }

    private function entry(string $date, string $account, string $amount, ?BudgetCategory $category = null): AccountEntry
    {
        $this->accounts[$account] ??= (new FinancialAccount())->setName($account);

        return (new AccountEntry())
            ->setDate(new \DateTimeImmutable($date))
            ->setAccount($this->accounts[$account])
            ->setCategory($category ?? $this->transfer)
            ->setConcept('Traspaso')
            ->setAmount($amount);
    }
}
