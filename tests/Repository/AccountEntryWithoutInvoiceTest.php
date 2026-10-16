<?php

namespace App\Tests\Repository;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Entity\ReceivedInvoice;
use App\Repository\AccountEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Los gastos de un trimestre a los que les falta la factura
 * ({@see AccountEntryRepository::findExpensesWithoutInvoice}): de gasto o inversión,
 * de una partida que la pide, sin factura recibida ligada y dentro de las fechas.
 *
 * Autocontenido: fechas de 2093.
 */
class AccountEntryWithoutInvoiceTest extends KernelTestCase
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
        foreach (array_reverse($this->created) as $row) {
            $this->em->remove($row);
        }
        $this->em->flush();

        parent::tearDown();
    }

    public function testSoloLosGastosQuePidenFacturaYNoLaTienen(): void
    {
        $account = $this->persist((new FinancialAccount())->setName('Banco sin factura '.bin2hex(random_bytes(3)))
            ->setKind(FinancialAccount::KIND_BANK)->setOpeningBalance('0')->setOpeningDate(new \DateTimeImmutable('2093-01-01'))
            ->setActive(true)->setSortOrder(99));
        $seeds = $this->category(BudgetCategoryGroup::KIND_EXPENSE, 'Semillas y plantel');
        $payroll = $this->category(BudgetCategoryGroup::KIND_EXPENSE, 'Nóminas')->setRequiresInvoice(false);
        $tractor = $this->category(BudgetCategoryGroup::KIND_INVESTMENT, 'Inversión');
        $fees = $this->category(BudgetCategoryGroup::KIND_INCOME, 'Cuotas de socixs');
        $loan = $this->category(BudgetCategoryGroup::KIND_FINANCING, 'Cuotas de préstamo');

        $missing = $this->entry($account, $seeds, '2093-07-01', '-120.00');           // gasto sin factura: sale
        $investment = $this->entry($account, $tractor, '2093-09-30', '-3000.00');     // inversión sin factura: sale
        $this->entry($account, $payroll, '2093-07-31', '-1400.00');                   // la partida no la pide
        $this->entry($account, $fees, '2093-08-01', '-100.00');                       // un ingreso devuelto no lleva factura
        $this->entry($account, $loan, '2093-08-05', '-500.00');                       // financiación tampoco
        $this->entry($account, $seeds, '2093-08-10', '30.00');                        // abono de un proveedor: no es gasto
        $this->entry($account, $seeds, '2093-06-30', '-80.00');                       // trimestre anterior
        $this->entry($account, $seeds, '2093-10-01', '-80.00');                       // trimestre siguiente
        $linked = $this->entry($account, $seeds, '2093-08-20', '-45.30');             // ya tiene factura
        $this->em->flush();

        $invoice = new ReceivedInvoice('sin-factura.pdf', 'sin-factura.pdf', 'application/pdf', ReceivedInvoice::SOURCE_WEB, null);
        $invoice->confirm($linked, null);
        $this->persist($invoice);
        $this->em->flush();

        $found = $this->em->getRepository(AccountEntry::class)
            ->findExpensesWithoutInvoice(new \DateTimeImmutable('2093-07-01'), new \DateTimeImmutable('2093-09-30'));

        $this->assertSame([$missing->getId(), $investment->getId()], array_map(static fn (AccountEntry $e) => $e->getId(), $found));
        $this->assertTrue($missing->getCategory()->expectsInvoice());
        $this->assertFalse($payroll->expectsInvoice());
        $this->assertFalse($fees->expectsInvoice(), 'en ingresos no cuenta, aunque la casilla siga marcada');
    }

    private function category(int $kind, string $name): BudgetCategory
    {
        $group = $this->persist((new BudgetCategoryGroup())->setName('GRUPO SIN FACTURA '.$kind.' '.bin2hex(random_bytes(3)))
            ->setKind($kind)->setSortOrder(99));

        return $this->persist((new BudgetCategory())->setGroup($group)->setName($name)->setSortOrder(1));
    }

    private function entry(FinancialAccount $account, BudgetCategory $category, string $date, string $amount): AccountEntry
    {
        return $this->persist((new AccountEntry())->setAccount($account)->setCategory($category)
            ->setDate(new \DateTimeImmutable($date))->setConcept('Prueba sin factura')->setAmount($amount));
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
