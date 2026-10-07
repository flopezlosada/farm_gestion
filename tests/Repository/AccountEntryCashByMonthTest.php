<?php

namespace App\Tests\Repository;

use App\Entity\AccountEntry;
use App\Entity\FinancialAccount;
use App\Repository\AccountEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El dinero que había en las cuentas al cerrar cada mes
 * ({@see AccountEntryRepository::cashByMonthEnd}), que es de donde parte la caja de
 * la portada. Antes partía del saldo que apuntaba el presupuesto y sumaba sólo los
 * apuntes con partida: en 2026 se dejaba fuera una subvención cobrada en diciembre y
 * daba un cierre de año con veinte mil euros de agujero que no existían.
 *
 * Autocontenido: las cuentas abren en 2099 y lo que se comprueba es la DIFERENCIA con
 * lo que hubiera antes en db_test, así que no le afecta lo que dejen otros tests.
 */
class AccountEntryCashByMonthTest extends KernelTestCase
{
    private const YEAR = 2099;

    /** @var list<AccountEntry|FinancialAccount> Se borran al revés: primero los apuntes. */
    private array $created = [];

    private EntityManagerInterface $em;
    private AccountEntryRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
        $this->repo = $this->em->getRepository(AccountEntry::class);
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
     * Cuenta lo que mueve el banco aunque no tenga partida, no cuenta lo anterior a la
     * apertura, una cuenta abierta a mitad de año suma desde su mes, y un traspaso
     * entre cuentas propias no cambia el total.
     */
    public function testElSaldoDeCadaMesEsElDineroDeVerdad(): void
    {
        $baseline = $this->repo->cashByMonthEnd(self::YEAR) ?? array_fill(0, 13, 0.0);

        $bank = $this->account('Banco', '1000.00', '2099-01-01');
        $box = $this->account('Caja', '200.00', '2099-03-15');
        $this->entry($bank, '2098-12-20', '-50.00');  // antes de abrir: histórico, no cuenta
        $this->entry($bank, '2099-01-10', '-100.00'); // sin partida: el banco lo movió igual
        $this->entry($box, '2099-03-20', '40.00');
        $this->entry($bank, '2099-04-02', '-300.00'); // traspaso del banco…
        $this->entry($box, '2099-04-02', '300.00');   // …a la caja
        $this->em->flush();

        $cash = $this->repo->cashByMonthEnd(self::YEAR);
        $delta = array_map(static fn (float $now, float $before): float => round($now - $before, 2), $cash, $baseline);

        $this->assertSame(1000.0, $delta[0], 'Abierta el 1 de enero, su saldo ya estaba al empezar el año.');
        $this->assertSame(900.0, $delta[1], 'Un apunte sin partida también es dinero que salió.');
        $this->assertSame(900.0, $delta[2]);
        $this->assertSame(1140.0, $delta[3], 'La caja abierta en marzo suma desde marzo, con su apertura.');
        $this->assertSame(1140.0, $delta[4], 'Un traspaso entre cuentas propias no cambia el total.');
        $this->assertSame(1140.0, $delta[12]);
    }

    /** Un año en que ninguna cuenta estaba abierta no tiene saldo real del que partir. */
    public function testAntesDeAbrirLasCuentasNoHaySaldo(): void
    {
        $before = $this->repo->cashByMonthEnd(self::YEAR - 1);

        $this->account('Banco', '1000.00', '2099-01-01');
        $this->em->flush();

        $this->assertSame($before, $this->repo->cashByMonthEnd(self::YEAR - 1), 'Una cuenta que abre en 2099 no existe en 2098.');
    }

    private function account(string $name, string $opening, string $date): FinancialAccount
    {
        $account = (new FinancialAccount())
            ->setName($name.' prueba '.bin2hex(random_bytes(3)))
            ->setKind(FinancialAccount::KIND_BANK)
            ->setOpeningBalance($opening)
            ->setOpeningDate(new \DateTimeImmutable($date))
            ->setActive(true)
            ->setSortOrder(99);
        $this->em->persist($account);
        $this->created[] = $account;

        return $account;
    }

    private function entry(FinancialAccount $account, string $date, string $amount): void
    {
        $entry = (new AccountEntry())
            ->setAccount($account)
            ->setDate(new \DateTimeImmutable($date))
            ->setConcept('Prueba de saldo')
            ->setAmount($amount);
        $this->em->persist($entry);
        $this->created[] = $entry;
    }
}
