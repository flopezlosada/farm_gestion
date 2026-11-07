<?php

namespace App\Tests\Entity;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use PHPUnit\Framework\TestCase;

/**
 * Juntar apuntes ya anotados en un solo cargo del banco ({@see AccountEntry::absorb}):
 * la liquidación de la tarjeta del día 5 cobra de una vez las compras que el libro
 * apuntó una a una. Cada compra conserva su importe y su partida; el movimiento
 * entero es la suma.
 */
class AccountEntryAbsorbTest extends TestCase
{
    private FinancialAccount $bank;

    protected function setUp(): void
    {
        $this->bank = $this->account('Fiare');
    }

    /** El caso real de junio: cuatro compras que suman la liquidación de 175,62 €. */
    public function testLasComprasDelDiaSeJuntanEnElCargoDeLaTarjeta(): void
    {
        $pharmacy = $this->entry('-20.00', 'Farmacia');
        $parts = [$this->entry('-32.80', 'Ferretería'), $this->entry('-88.33', 'Taller'), $this->entry('-34.49', 'Taller')];

        array_walk($parts, static fn (AccountEntry $p) => $pharmacy->absorb($p));

        $this->assertSame('-175.62', $pharmacy->getMovementAmount());
        $this->assertSame('-20.00', $pharmacy->getAmount(), 'cada compra conserva su importe');
        foreach ($parts as $part) {
            $this->assertSame($pharmacy, $part->getSplitFrom());
        }
    }

    /**
     * Otra cuenta, otro día u otro sentido no son el mismo cargo; ni el mismo apunte
     * consigo mismo.
     *
     * @dataProvider notTheSameCharge
     */
    public function testSoloSeJuntaElMismoCargo(callable $other): void
    {
        $head = $this->entry('-20.00');

        $this->expectException(\InvalidArgumentException::class);
        $head->absorb($other($this, $head));
    }

    /** @return iterable<string, array{callable}> */
    public static function notTheSameCharge(): iterable
    {
        yield 'otra cuenta' => [static fn (self $t): AccountEntry => $t->entry('-5.00')->setAccount($t->account('La Caixa'))];
        yield 'otro día' => [static fn (self $t): AccountEntry => $t->entry('-5.00')->setDate(new \DateTimeImmutable('2026-06-06'))];
        yield 'otro sentido' => [static fn (self $t): AccountEntry => $t->entry('5.00')];
        yield 'él mismo' => [static fn (self $t, AccountEntry $head): AccountEntry => $head];
    }

    /** Un traspaso no se junta: su pareja de la otra cuenta dejaría de casar. */
    public function testUnTraspasoNoSeJunta(): void
    {
        $transfer = $this->category('Traspaso entre cuentas', BudgetCategoryGroup::KIND_TRANSFER);

        $this->expectException(\InvalidArgumentException::class);
        $this->entry('-20.00')->absorb($this->entry('-5.00')->setCategory($transfer));
    }

    /** Lo ya partido, o lo que ya es parte de otro, no se vuelve a juntar. */
    public function testNoSeJuntaLoYaPartido(): void
    {
        $split = $this->entry('-158.95');
        $part = $split->splitOff('50.00', $this->category('Suministros'), 'Suministros');

        foreach ([[$this->entry('-1.00'), $split], [$this->entry('-1.00'), $part], [$part, $this->entry('-1.00')]] as [$head, $other]) {
            try {
                $head->absorb($other);
                $this->fail('No debería juntarse.');
            } catch (\InvalidArgumentException) {
                $this->assertNotSame($head, $other->getSplitFrom());
            }
        }
    }

    public function account(string $name): FinancialAccount
    {
        return (new FinancialAccount())->setName($name)->setKind(FinancialAccount::KIND_BANK)
            ->setOpeningBalance('0.00')->setOpeningDate(new \DateTimeImmutable('2026-01-01'));
    }

    public function entry(string $amount, string $concept = 'Compra con tarjeta'): AccountEntry
    {
        return (new AccountEntry())->setAccount($this->bank)->setCategory($this->category('Material'))
            ->setDate(new \DateTimeImmutable('2026-06-05'))->setConcept($concept)->setAmount($amount);
    }

    private function category(string $name, int $kind = BudgetCategoryGroup::KIND_EXPENSE): BudgetCategory
    {
        $group = (new BudgetCategoryGroup())->setName('Grupo '.$name)->setKind($kind);

        return (new BudgetCategory())->setGroup($group)->setName($name);
    }
}
