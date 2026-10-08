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
 * Qué apuntes del libro pueden ser el pago de una factura
 * ({@see AccountEntryRepository::findPaymentCandidates}): mismo importe exacto, de
 * una semana antes a mes y medio después, sin factura ya, el más cercano primero.
 *
 * Los casos reales que lo motivan: transferencias de 3, 5 y 20 días después de la
 * fecha de la factura, que acabaron contadas dos veces.
 *
 * Autocontenido: fechas de 2092 y un importe que no usa nadie.
 */
class AccountEntryPaymentCandidatesTest extends KernelTestCase
{
    private const AMOUNT = '-876.54';

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

    public function testElPagoCercanoSinFacturaYDelMismoImporte(): void
    {
        $account = $this->persist((new FinancialAccount())->setName('Banco candidatos '.bin2hex(random_bytes(3)))
            ->setKind(FinancialAccount::KIND_BANK)->setOpeningBalance('0')->setOpeningDate(new \DateTimeImmutable('2092-01-01'))
            ->setActive(true)->setSortOrder(99));
        $group = $this->persist((new BudgetCategoryGroup())->setName('GASTOS CANDIDATOS '.bin2hex(random_bytes(3)))
            ->setKind(BudgetCategoryGroup::KIND_EXPENSE)->setSortOrder(99));
        $category = $this->persist((new BudgetCategory())->setGroup($group)->setName('Varios')->setSortOrder(1));

        $near = $this->entry($account, $category, '2092-05-08', self::AMOUNT);   // +3 días: el bueno
        $far = $this->entry($account, $category, '2092-05-25', self::AMOUNT);    // +20 días: también vale, detrás
        $this->entry($account, $category, '2092-07-01', self::AMOUNT);           // +57 días: fuera de la ventana
        $this->entry($account, $category, '2092-04-20', self::AMOUNT);           // −15 días: fuera
        $this->entry($account, $category, '2092-05-06', '-876.55');              // otro importe
        $linked = $this->entry($account, $category, '2092-05-05', self::AMOUNT); // ya tiene factura
        $this->em->flush();

        $invoice = new ReceivedInvoice('candidatos.pdf', 'candidatos.pdf', 'application/pdf', ReceivedInvoice::SOURCE_WEB, null);
        $invoice->confirm($linked, null);
        $this->persist($invoice);
        $this->em->flush();

        $found = $this->em->getRepository(AccountEntry::class)->findPaymentCandidates(self::AMOUNT, new \DateTimeImmutable('2092-05-05'));

        $this->assertSame([$near->getId(), $far->getId()], array_map(static fn (AccountEntry $e) => $e->getId(), $found));
    }

    private function entry(FinancialAccount $account, BudgetCategory $category, string $date, string $amount): AccountEntry
    {
        return $this->persist((new AccountEntry())->setAccount($account)->setCategory($category)
            ->setDate(new \DateTimeImmutable($date))->setConcept('Prueba de candidatos')->setAmount($amount));
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
