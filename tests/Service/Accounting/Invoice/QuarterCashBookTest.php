<?php

namespace App\Tests\Service\Accounting\Invoice;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Entity\ReceivedInvoice;
use App\Service\Accounting\Invoice\InvoiceQuarter;
use App\Service\Accounting\Invoice\QuarterCashBook;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El libro de caja del trimestre para la gestoría: todos los movimientos de cada
 * cuenta, con o sin factura, entre el saldo al empezar y el saldo al cerrar.
 *
 * Autocontenido: una cuenta que abre en 2093, con nombre propio.
 */
class QuarterCashBookTest extends KernelTestCase
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

    public function testSaldosYFacturasDeCadaMovimiento(): void
    {
        $name = 'Caja libro '.bin2hex(random_bytes(3));
        $account = $this->persist((new FinancialAccount())->setName($name)->setKind(FinancialAccount::KIND_CASH)
            ->setOpeningBalance('100.00')->setOpeningDate(new \DateTimeImmutable('2093-01-01'))->setActive(true)->setSortOrder(99));
        $group = $this->persist((new BudgetCategoryGroup())->setName('GASTOS LIBRO '.bin2hex(random_bytes(3)))
            ->setKind(BudgetCategoryGroup::KIND_EXPENSE)->setSortOrder(99));
        $category = $this->persist((new BudgetCategory())->setGroup($group)->setName('Varios')->setSortOrder(1));

        $this->entry($account, $category, '2093-02-10', '50.00', 'Venta de huevos');
        $paid = $this->entry($account, $category, '2093-03-02', '-30.00', 'Melaza');
        $this->entry($account, $category, '2093-04-02', '-5.00', 'Del trimestre siguiente');
        $this->em->flush();
        $invoice = new ReceivedInvoice('melaza.pdf', 'melaza.pdf', 'application/pdf', ReceivedInvoice::SOURCE_WEB, null);
        $invoice->confirm($paid, null);
        $this->persist($invoice);
        $this->em->flush();

        $book = (new QuarterCashBook(
            $this->em->getRepository(AccountEntry::class),
            $this->em->getRepository(FinancialAccount::class),
            $this->em->getRepository(ReceivedInvoice::class),
        ))->build(InvoiceQuarter::of(2093, 1), [$invoice->getId() => 'Melaza Fact 1.pdf']);
        $sheet = $book->getSheetByName($name);
        $this->assertNotNull($sheet, 'Una hoja por cuenta, con su nombre.');

        $this->assertEquals(100, $sheet->getCell('H1')->getValue(), 'Saldo al empezar: la apertura.');
        $this->assertSame('Venta de huevos', $sheet->getCell('C3')->getValue());
        $this->assertEquals(50, $sheet->getCell('F3')->getValue(), 'Lo que entra va en ingresos.');
        $this->assertEquals(30, $sheet->getCell('G4')->getValue(), 'Lo que sale, en gastos y en positivo.');
        $this->assertSame('Melaza Fact 1.pdf', $sheet->getCell('I4')->getValue(), 'Cada pago dice con qué fichero va su factura.');
        $this->assertNull($sheet->getCell('I3')->getValue(), 'Sin factura, en blanco: se ve de un vistazo.');
        $this->assertEquals(120, $sheet->getCell('H5')->getValue(), 'Saldo al cerrar: sin lo de abril, que es del trimestre siguiente.');
    }

    private function entry(FinancialAccount $account, BudgetCategory $category, string $date, string $amount, string $concept): AccountEntry
    {
        return $this->persist((new AccountEntry())->setAccount($account)->setCategory($category)
            ->setDate(new \DateTimeImmutable($date))->setConcept($concept)->setAmount($amount));
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
