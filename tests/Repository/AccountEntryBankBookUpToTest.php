<?php

namespace App\Tests\Repository;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Repository\AccountEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Hasta dónde está al día el libro de los bancos
 * ({@see AccountEntryRepository::bankBookUpTo}): manda el banco más atrasado, y no
 * cuentan las cajas, las tarjetas ni los bancos cerrados.
 *
 * Autocontenido: los apuntes son de 1990, más antiguos que cualquiera de db_test, así
 * que el banco más atrasado es siempre el del test.
 */
class AccountEntryBankBookUpToTest extends KernelTestCase
{
    /** @var list<AccountEntry|FinancialAccount|BudgetCategory|BudgetCategoryGroup> Se borran al revés: primero los apuntes. */
    private array $created = [];

    private EntityManagerInterface $em;
    private AccountEntryRepository $repo;
    private BudgetCategory $category;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->repo = $this->em->getRepository(AccountEntry::class);
        $this->category = $this->category();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $row) {
            $this->em->remove($row);
        }
        $this->em->flush();

        parent::tearDown();
    }

    /** De dos bancos, manda el último apunte del que va más atrasado. */
    public function testMandaElBancoMasAtrasado(): void
    {
        $ahead = $this->account(FinancialAccount::KIND_BANK, true);
        $behind = $this->account(FinancialAccount::KIND_BANK, true);
        $this->entry($ahead, '1990-10-14');
        $this->entry($behind, '1990-08-20');
        $this->entry($behind, '1990-09-08');
        $this->em->flush();

        $this->assertSame('1990-09-08', $this->repo->bankBookUpTo()?->format('Y-m-d'));
    }

    /** Las cajas, las tarjetas y los bancos cerrados se apuntan a su ritmo: no frenan el libro. */
    public function testNoCuentanCajasTarjetasNiBancosCerrados(): void
    {
        $bank = $this->account(FinancialAccount::KIND_BANK, true);
        $this->entry($bank, '1990-09-08');
        $this->entry($this->account(FinancialAccount::KIND_CASH, true), '1990-02-01');
        $this->entry($this->account(FinancialAccount::KIND_CARD, true), '1990-03-01');
        $this->entry($this->account(FinancialAccount::KIND_BANK, false), '1990-04-01');
        $this->em->flush();

        $this->assertSame('1990-09-08', $this->repo->bankBookUpTo()?->format('Y-m-d'));
    }

    private function account(int $kind, bool $active): FinancialAccount
    {
        $account = (new FinancialAccount())
            ->setName('Cuenta prueba '.bin2hex(random_bytes(3)))
            ->setKind($kind)
            ->setOpeningBalance('0.00')
            ->setOpeningDate(new \DateTimeImmutable('1990-01-01'))
            ->setActive($active)
            ->setSortOrder(99);
        $this->em->persist($account);
        $this->created[] = $account;

        return $account;
    }

    private function entry(FinancialAccount $account, string $date): void
    {
        $entry = (new AccountEntry())
            ->setAccount($account)
            ->setDate(new \DateTimeImmutable($date))
            ->setCategory($this->category)
            ->setConcept('Prueba de libro al día')
            ->setAmount('-10.00');
        $this->em->persist($entry);
        $this->created[] = $entry;
    }

    /** Partida propia del test: el apunte la exige y no depende del catálogo de db_test. */
    private function category(): BudgetCategory
    {
        $group = (new BudgetCategoryGroup())
            ->setName('Grupo prueba '.bin2hex(random_bytes(3)))
            ->setKind(BudgetCategoryGroup::KIND_EXPENSE);
        $category = (new BudgetCategory())
            ->setGroup($group)
            ->setName('Partida prueba');
        $this->em->persist($group);
        $this->em->persist($category);
        $this->created[] = $group;
        $this->created[] = $category;

        return $category;
    }
}
