<?php

namespace App\Tests\Repository;

use App\Entity\AccountEntry;
use App\Entity\BankStatementLine;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Repository\AccountEntryRepository;
use App\Service\Accounting\Norma43\Norma43Movement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Los apuntes que parecen el mismo pago anotado dos veces
 * ({@see AccountEntryRepository::findLikelyDuplicates}): misma cuenta de banco, mismo
 * importe, a pocos días, no ligados los dos al banco, sin traspasos ni partes, y que
 * dicen lo mismo.
 *
 * Autocontenido: fechas de 2094.
 */
class AccountEntryLikelyDuplicatesTest extends KernelTestCase
{
    /** @var list<object> */
    private array $created = [];

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach (array_reverse($this->created) as $row) {
            $this->em->remove($this->em->find($row::class, $row->getId()));
            $this->em->flush();
        }

        parent::tearDown();
    }

    public function testSoloLosQueParecenElMismoPago(): void
    {
        $bank = $this->account(FinancialAccount::KIND_BANK);
        $cash = $this->account(FinancialAccount::KIND_CASH);
        $local = $this->category(BudgetCategoryGroup::KIND_EXPENSE);
        $transfer = $this->category(BudgetCategoryGroup::KIND_TRANSFER);

        // Anotado desde el libro y otra vez desde el extracto, un día después: sale.
        $first = $this->entry($bank, $local, '2094-10-09', '-108.95', 'trf. ayuntamiento de torremocha');
        $second = $this->entry($bank, $local, '2094-10-10', '-108.95', 'TRF. AYUNTAMIENTO DE TORREMOCHA');
        // Dos transferencias iguales que el banco trae las dos: dos pagos.
        $a = $this->entry($bank, $local, '2094-10-15', '-30.00', 'TRANSF. A SU FAVOR');
        $b = $this->entry($bank, $local, '2094-10-15', '-30.00', 'TRANSF. A SU FAVOR');
        // Mismo importe, conceptos distintos: dos pagos.
        $this->entry($bank, $local, '2094-10-20', '-33.00', 'REPRODUCCIONES CABRERA');
        $this->entry($bank, $local, '2094-10-21', '-33.00', 'HOSPITAL VETERINARIO ALTAI');
        // A una semana: el recibo de cada semana, no un duplicado.
        $this->entry($bank, $local, '2094-11-01', '-12.00', 'RECIBO SEMANAL');
        $this->entry($bank, $local, '2094-11-08', '-12.00', 'RECIBO SEMANAL');
        // En caja dos ventas iguales el mismo día son normales.
        $this->entry($cash, $local, '2094-10-09', '-24.00', 'Huevos');
        $this->entry($cash, $local, '2094-10-09', '-24.00', 'Huevos');
        // Traspasos: los cuadra su propia tarea.
        $this->entry($bank, $transfer, '2094-10-25', '-500.00', 'TRASPASO');
        $this->entry($bank, $transfer, '2094-10-25', '-500.00', 'TRASPASO');
        // Una parte de un apunte partido no se compara con nada.
        $rent = $this->entry($bank, $local, '2094-12-09', '-158.95', 'TRF. AYUNTAMIENTO DE TORREMOCHA');
        $this->persist($rent->splitOff('50.00', $local, 'TRF. AYUNTAMIENTO DE TORREMOCHA'));
        $this->entry($bank, $local, '2094-12-10', '-50.00', 'Otra cosa');
        $this->em->flush();
        $this->persist($this->line($bank, '2094-10-15', -3000, 1))->linkTo($a);
        $this->persist($this->line($bank, '2094-10-15', -3000, 2))->linkTo($b);
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->getRepository(AccountEntry::class)->findLikelyDuplicates(new \DateTimeImmutable('2094-01-01'));
        $mine = array_values(array_filter($found, static fn (array $p): bool => $p[0]->getAccount()?->getId() === $bank->getId()));

        $this->assertSame([[$first->getId(), $second->getId()]], array_map(static fn (array $p): array => [$p[0]->getId(), $p[1]->getId()], $mine));
    }

    private function account(int $kind): FinancialAccount
    {
        return $this->persist((new FinancialAccount())->setName('Duplicados '.bin2hex(random_bytes(3)))->setKind($kind)
            ->setOpeningBalance('0')->setOpeningDate(new \DateTimeImmutable('2094-01-01'))->setActive(true)->setSortOrder(99));
    }

    private function category(int $kind): BudgetCategory
    {
        $group = $this->persist((new BudgetCategoryGroup())->setName('DUPLICADOS '.$kind.' '.bin2hex(random_bytes(3)))
            ->setKind($kind)->setSortOrder(99));

        return $this->persist((new BudgetCategory())->setGroup($group)->setName('Local')->setSortOrder(1));
    }

    private function entry(FinancialAccount $account, BudgetCategory $category, string $date, string $amount, string $concept): AccountEntry
    {
        return $this->persist((new AccountEntry())->setAccount($account)->setCategory($category)
            ->setDate(new \DateTimeImmutable($date))->setConcept($concept)->setAmount($amount));
    }

    private function line(FinancialAccount $account, string $date, int $cents, int $ordinal): BankStatementLine
    {
        $day = new \DateTimeImmutable($date);

        return new BankStatementLine($account, new Norma43Movement($day, $day, '99', '000', $cents, '', '', '', ['TRANSF. A SU FAVOR']), $ordinal);
    }

    /**
     * @template T of object
     *
     * @param T $row
     *
     * @return T
     */
    private function persist(object $row): object
    {
        $this->em->persist($row);
        $this->created[] = $row;

        return $row;
    }
}
