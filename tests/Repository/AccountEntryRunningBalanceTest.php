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
 * El saldo tras cada movimiento en «Movimientos» con una cuenta elegida
 * ({@see AccountEntryRepository::runningBalances}), la columna que la tesorera
 * compara con la del extracto del banco. Se calcula con una consulta para la primera
 * fila y se arrastra al resto, así que lo que se blinda es que el arrastre siga el
 * mismo orden que la tabla en los dos sentidos, también dentro de un mismo día.
 *
 * Autocontenido: cuenta propia abierta en 2099.
 */
class AccountEntryRunningBalanceTest extends KernelTestCase
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

    /**
     * Lo último primero (como abre la pantalla): la primera fila da el saldo de hoy
     * y cada fila de abajo es el saldo antes del movimiento de arriba. De antiguo a
     * nuevo, el mismo saldo para cada movimiento. Dentro de un día, en el orden en que
     * se anotaron.
     */
    public function testElSaldoDeCadaFilaEsElDelExtractoEnLosDosSentidos(): void
    {
        $account = $this->account('1000.00', '2099-01-01');
        $first = $this->entry($account, '2099-01-05', '-100.00');
        $second = $this->entry($account, '2099-01-05', '250.50');
        $third = $this->entry($account, '2099-02-01', '-0.50');
        $this->em->flush();

        $expected = [
            $first->getId() => '900.00',
            $second->getId() => '1150.50',
            $third->getId() => '1150.00',
        ];

        $newestFirst = $this->listed($account, false);
        $this->assertSame([$third->getId(), $second->getId(), $first->getId()], array_map(static fn (AccountEntry $e) => $e->getId(), $newestFirst));
        $this->assertEquals($expected, $this->repo->runningBalances($account, $newestFirst, false), 'Lo último primero.');
        $this->assertSame($this->repo->balanceFor($account), $this->repo->runningBalances($account, $newestFirst, false)[$third->getId()], 'La primera fila es el saldo de la cuenta.');

        $oldestFirst = $this->listed($account, true);
        $this->assertSame([$first->getId(), $second->getId(), $third->getId()], array_map(static fn (AccountEntry $e) => $e->getId(), $oldestFirst), 'De antiguo a nuevo, también dentro del día.');
        $this->assertEquals($expected, $this->repo->runningBalances($account, $oldestFirst, true));
    }

    /** Una página que no es la primera arranca del saldo real, no de cero. */
    public function testUnaPaginaIntermediaParteDelSaldoReal(): void
    {
        $account = $this->account('1000.00', '2099-01-01');
        $this->entry($account, '2099-01-05', '-100.00');
        $middle = $this->entry($account, '2099-01-06', '-200.00');
        $this->entry($account, '2099-01-07', '-300.00');
        $this->em->flush();

        $this->assertSame([$middle->getId() => '700.00'], $this->repo->runningBalances($account, [$middle], false));
    }

    /** Lo que esté antes de la apertura (los datos viejos) no entra en el saldo: sin cifra, y no descuadra las demás. */
    public function testUnApunteAnteriorALaAperturaSeQuedaSinSaldo(): void
    {
        $account = $this->account('1000.00', '2099-01-01');
        $old = $this->entry($account, '2098-12-20', '-160.00');
        $new = $this->entry($account, '2099-01-05', '45.00');
        $this->em->flush();

        $this->assertSame([$new->getId() => '1045.00'], $this->repo->runningBalances($account, [$old, $new], true));
        $this->assertSame([$new->getId() => '1045.00'], $this->repo->runningBalances($account, [$new, $old], false));
    }

    /**
     * @return AccountEntry[]
     */
    private function listed(FinancialAccount $account, bool $ascending): array
    {
        return $this->repo->listingQueryBuilder(['account' => $account], $ascending)->getQuery()->getResult();
    }

    private function account(string $opening, string $date): FinancialAccount
    {
        $account = (new FinancialAccount())
            ->setName('Banco prueba '.bin2hex(random_bytes(3)))
            ->setKind(FinancialAccount::KIND_BANK)
            ->setOpeningBalance($opening)
            ->setOpeningDate(new \DateTimeImmutable($date))
            ->setActive(true)
            ->setSortOrder(99);
        $this->em->persist($account);
        $this->created[] = $account;

        return $account;
    }

    private function entry(FinancialAccount $account, string $date, string $amount): AccountEntry
    {
        $entry = (new AccountEntry())
            ->setAccount($account)
            ->setDate(new \DateTimeImmutable($date))
            ->setCategory($this->category)
            ->setConcept('Prueba de saldo')
            ->setAmount($amount);
        $this->em->persist($entry);
        $this->created[] = $entry;

        return $entry;
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
