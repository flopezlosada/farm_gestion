<?php

namespace App\Tests\Entity;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use PHPUnit\Framework\TestCase;

/**
 * Partir un apunte ({@see AccountEntry::splitOff}): el cargo mensual del Ayuntamiento
 * (158,95 €) junta el alquiler con factura (108,95) y 50 € de suministros sin ella.
 * Se cuenta en dos partidas sin que el saldo de la cuenta cambie.
 */
class AccountEntrySplitTest extends TestCase
{
    /** El caso real: lo separado sale en su apunte y el original baja; la suma es la del banco. */
    public function testElCargoDelAyuntamientoSeParteSinCambiarElSaldo(): void
    {
        $entry = $this->entry('-158.95')->setProviderName('Ayuntamiento')->setInvoiceNumber('A-20');
        $supplies = $this->category('Suministros');

        $sibling = $entry->splitOff('50.00', $supplies, 'Suministros del local, acuerdo con el Ayto');

        $this->assertSame('-108.95', $entry->getAmount());
        $this->assertSame('-50.00', $sibling->getAmount(), 'Un gasto partido sigue siendo gasto.');
        $this->assertSame($entry->getAccount(), $sibling->getAccount());
        $this->assertSame($entry->getDate(), $sibling->getDate());
        $this->assertSame($supplies, $sibling->getCategory());
        $this->assertSame('Suministros del local, acuerdo con el Ayto', $sibling->getConcept());
        $this->assertSame('Ayuntamiento', $sibling->getProviderName(), 'A quién se pagó es lo mismo.');
        $this->assertNull($sibling->getInvoiceNumber(), 'La factura es del alquiler: se queda en el original.');
    }

    /** Un ingreso también se parte, y en céntimos: con float, 0,30 − 0,10 no da 0,20. */
    public function testUnIngresoSeParteAlCentimo(): void
    {
        $entry = $this->entry('0.30');

        $sibling = $entry->splitOff('0.10', $this->category('Donaciones'), 'Donativo');

        $this->assertSame('0.20', $entry->getAmount());
        $this->assertSame('0.10', $sibling->getAmount());
    }

    /**
     * Ni cero, ni negativo, ni el apunte entero (eso es cambiarle la partida), ni más.
     * El original queda intacto cuando no se puede.
     *
     * @dataProvider partesQueNoCaben
     */
    public function testLaParteTieneQueCaberDentro(string $part): void
    {
        $entry = $this->entry('-158.95');

        try {
            $entry->splitOff($part, $this->category('Suministros'), 'Suministros');
            $this->fail('Una parte que no cabe no debería partir el apunte.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('menos de 158,95 €', $e->getMessage());
        }
        $this->assertSame('-158.95', $entry->getAmount());
    }

    /** @return array<string, array{string}> */
    public static function partesQueNoCaben(): array
    {
        return [
            'cero' => ['0.00'],
            'negativa' => ['-50.00'],
            'el apunte entero' => ['158.95'],
            'más que el apunte' => ['200.00'],
            'menos de un céntimo' => ['0.004'],
        ];
    }

    /** Un traspaso no se parte: su pareja de la otra cuenta dejaría de casar. */
    public function testUnTraspasoNoSeParte(): void
    {
        $transfer = $this->category('Traspaso entre cuentas', BudgetCategoryGroup::KIND_TRANSFER);

        $this->expectException(\InvalidArgumentException::class);
        $this->entry('-100.00', $transfer)->splitOff('40.00', $this->category('Suministros'), 'Suministros');
    }

    /** Ni se separa hacia la partida de traspasos: crearía media mitad sin la otra. */
    public function testNoSeSeparaAUnTraspaso(): void
    {
        $entry = $this->entry('-100.00');

        try {
            $entry->splitOff('40.00', $this->category('Traspaso entre cuentas', BudgetCategoryGroup::KIND_TRANSFER), 'A la caja');
            $this->fail('No debería separar hacia un traspaso.');
        } catch (\InvalidArgumentException) {
            $this->assertSame('-100.00', $entry->getAmount());
        }
    }

    private function entry(string $amount, ?BudgetCategory $category = null): AccountEntry
    {
        $account = (new FinancialAccount())->setName('Fiare')->setKind(FinancialAccount::KIND_BANK)
            ->setOpeningBalance('0.00')->setOpeningDate(new \DateTimeImmutable('2026-01-01'));

        return (new AccountEntry())->setAccount($account)->setCategory($category ?? $this->category('Local'))
            ->setDate(new \DateTimeImmutable('2026-10-01'))->setConcept('RECIBO AYUNTAMIENTO TORREMOCHA')->setAmount($amount);
    }

    private function category(string $name, int $kind = BudgetCategoryGroup::KIND_EXPENSE): BudgetCategory
    {
        $group = (new BudgetCategoryGroup())->setName('Grupo '.$name)->setKind($kind);

        return (new BudgetCategory())->setGroup($group)->setName($name);
    }
}
