<?php

namespace App\Tests\Service\Accounting\Norma43;

use App\Entity\AccountEntry;
use App\Entity\BankStatement;
use App\Entity\BankStatementLine;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Service\Accounting\Norma43\BankStatementImport;
use App\Service\Accounting\Norma43\BankStatementImporter;
use App\Service\Accounting\Norma43\BankStatementReader;
use App\Service\Accounting\Norma43\Norma43Exception;
use App\Service\Accounting\Norma43\Norma43Reader;
use App\Service\Accounting\Norma43\StatementReviewer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guardar un extracto contra db_test y cuadrarlo con el libro. Autocontenido: cuentas
 * con un IBAN inventado al azar y fechas de 2091, que no usa nadie.
 */
class BankStatementImporterTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    /** @var list<FinancialAccount> */
    private array $accounts = [];

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach ($this->accounts as $account) {
            foreach ([BankStatementLine::class, BankStatement::class, AccountEntry::class] as $class) {
                $this->em->createQueryBuilder()->delete($class, 'x')->where('x.account = :a')->setParameter('a', $account->getId())->getQuery()->execute();
            }
        }
        foreach (array_reverse([...$this->created, ...$this->accounts]) as $row) {
            $this->em->remove($this->em->find($row::class, $row->getId()));
            $this->em->flush();
        }

        parent::tearDown();
    }

    /**
     * Sube un extracto, liga solo el apunte claro, cuadra los saldos y, al volver a
     * subirlo (y uno que se solapa), no duplica nada.
     */
    public function testGuardaLigaCuadraYNoDuplica(): void
    {
        [$account, $number] = $this->bankAccount(openingBalance: '1000.00');
        $category = $this->category();
        // Apunte del cargo de la luz, dos días después que el banco: se liga solo.
        $light = $this->entry($account, $category, '2091-03-07', '-45.50', 'Luz marzo');
        // Apunte que el banco no trae (¿duplicado?).
        $ghost = $this->entry($account, $category, '2091-03-20', '-9.99', 'Algo que el banco no trae');
        // Apunte anterior al extracto: entra en el saldo inicial del libro.
        $this->entry($account, $category, '2091-02-10', '200.00', 'Cuotas de febrero');
        $this->em->flush();

        $march = N43::file(N43::account('9999', '0001', $number, '910301', '910331', 120000, [
            ['910305', -4550, ['RECIBO LUZ']],
            ['910312', 3000, ['TRANSFERENCIA']],
            ['910312', 3000, ['TRANSFERENCIA']],
        ]));
        $import = $this->import($march);

        $this->assertSame(3, $import->added);
        $this->assertSame(0, $import->alreadyThere);
        $this->assertSame(1, $import->linked);
        $statement = $import->statements[0];

        $review = static::getContainer()->get(StatementReviewer::class)->review($statement);
        $this->assertSame(120000, $review->bookOpeningCents, 'apertura 1000 + 200 de febrero');
        $this->assertSame(0, $review->openingDiffCents());
        $this->assertSame(120000 - 4550 - 999, $review->bookClosingCents);
        $this->assertSame(-999 - 6000, $review->closingDiffCents(), 'faltan las dos transferencias y sobra el apunte fantasma');
        $this->assertCount(2, $review->unlinked, 'las dos transferencias idénticas del mismo día, sin fundir');
        $this->assertSame([$ghost->getId()], array_map(static fn (AccountEntry $e) => $e->getId(), $review->unbanked));
        $this->assertSame(1, $review->linkedCount);
        $this->assertSame($light->getId(), $this->em->getRepository(BankStatementLine::class)->findOneBy(['account' => $account, 'amount' => '-45.50'])->getEntry()->getId());

        // El mismo fichero otra vez: ni extracto nuevo ni movimientos nuevos.
        $again = $this->import($march);
        $this->assertSame(0, $again->added);
        $this->assertSame(3, $again->alreadyThere);
        $this->assertSame($statement->getId(), $again->statements[0]->getId());

        // Uno que se solapa (del 12 al 15): sólo entra el movimiento nuevo.
        $overlap = $this->import(N43::file(N43::account('9999', '0001', $number, '910312', '910315', 120000 - 4550, [
            ['910312', 3000, ['TRANSFERENCIA']],
            ['910312', 3000, ['TRANSFERENCIA']],
            ['910314', -120, ['COMISION']],
        ])));
        $this->assertSame(1, $overlap->added);
        $this->assertSame(2, $overlap->alreadyThere);
        $this->assertSame(4, $this->em->getRepository(BankStatementLine::class)->count(['account' => $account]));
    }

    /**
     * El mismo mes en Norma 43 y, después, dentro de la hoja de Excel del banco (que no
     * trae documento ni referencias): sólo entra lo que la hoja trae de más.
     */
    public function testNorma43YHojaDelMismoMesNoDuplican(): void
    {
        [$account, $number] = $this->bankAccount(openingBalance: '1200.00');
        $this->import(N43::file(N43::account('9999', '0001', $number, '910301', '910331', 120000, [
            ['910305', -4550, ['RECIBO LUZ']],
            ['910312', 3000, ['TRANSFERENCIA']],
            ['910312', 3000, ['TRANSFERENCIA']],
        ])));

        $sheet = $this->import(BankSheet::caixaLike('ES00'.'9999'.'0001'.'00'.$number, 1200.00, [
            ['2091-03-05', -45.50, 'RECIBO LUZ'],
            ['2091-03-12', 30.00, 'TRANSFERENCIA'],
            ['2091-03-12', 30.00, 'TRANSFERENCIA'],
            ['2091-04-02', -12.00, 'COMISION'],
        ]));

        $this->assertSame(1, $sheet->added);
        $this->assertSame(3, $sheet->alreadyThere);
        $this->assertSame(4, $this->em->getRepository(BankStatementLine::class)->count(['account' => $account]));
    }

    /** Un extracto que empieza antes que el libro de la cuenta no compara el saldo inicial. */
    public function testElLibroEmpiezaDespues(): void
    {
        [$account, $number] = $this->bankAccount(openingBalance: '50.00', openingDate: '2091-03-10');

        $review = static::getContainer()->get(StatementReviewer::class)->review(
            $this->import(N43::file(N43::account('9999', '0001', $number, '910301', '910331', 2000, [['910305', 3000]])))->statements[0],
        );

        $this->assertNull($review->bookOpeningCents);
        $this->assertSame(5000, $review->bookClosingCents);
        $this->assertSame(0, $review->closingDiffCents());
        $this->assertSame([], $review->unlinked, 'lo anterior a la apertura ya está en el saldo de apertura');
    }

    /** Mismas fechas con otros saldos: uno de los dos está mal, se rechaza. */
    public function testMismoPeriodoConOtrosSaldosSeRechaza(): void
    {
        [, $number] = $this->bankAccount();
        $this->import(N43::file(N43::account('9999', '0001', $number, '910301', '910331', 1000, [])));

        $this->expectException(Norma43Exception::class);
        $this->expectExceptionMessage('con otros saldos');
        $this->import(N43::file(N43::account('9999', '0001', $number, '910301', '910331', 2000, [])));
    }

    /** Una cuenta retirada o que no es de banco: se rechaza el fichero entero. */
    public function testCuentaRetiradaONoBancariaSeRechaza(): void
    {
        [$retired, $number] = $this->bankAccount();
        $retired->setActive(false);
        [$cash, $cashNumber] = $this->bankAccount();
        $cash->setKind(FinancialAccount::KIND_CASH);
        $this->em->flush();
        $importer = static::getContainer()->get(BankStatementImporter::class);
        $reader = new Norma43Reader();

        foreach ([[$number, 'está retirada'], [$cashNumber, 'no es una cuenta de banco']] as [$n, $message]) {
            try {
                $importer->resolve($reader->read(N43::file(N43::account('9999', '0001', $n, '910301', '910331', 0, []))));
                $this->fail('Tenía que rechazarse.');
            } catch (Norma43Exception $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    /** Sin IBAN que case, se pide elegir; sólo vale una cuenta de banco activa sin IBAN. */
    public function testSinIbanSeEligeEntreLasCuentasSinIban(): void
    {
        [$withIban] = $this->bankAccount();
        [$noIban] = $this->bankAccount();
        $noIban->setIban(null);
        $this->em->flush();
        $file = (new Norma43Reader())->read(N43::file(N43::account('9999', '0001', $this->randomNumber(), '910301', '910331', 0, [])));
        $key = $file->accounts[0]->key();
        $importer = static::getContainer()->get(BankStatementImporter::class);

        $this->assertCount(1, $importer->resolve($file)['unresolved']);
        $this->assertContains($noIban, $importer->choosableAccounts());
        $this->assertNotContains($withIban, $importer->choosableAccounts());
        $this->assertSame($noIban, $importer->resolve($file, [$key => $noIban->getId()])['resolved'][$key]);

        $this->expectException(Norma43Exception::class);
        $importer->resolve($file, [$key => $withIban->getId()]);
    }

    private function import(string $raw): BankStatementImport
    {
        $importer = static::getContainer()->get(BankStatementImporter::class);
        $file = static::getContainer()->get(BankStatementReader::class)->read($raw);

        return $importer->import($file, $importer->resolve($file)['resolved'], 'extracto', null);
    }

    /**
     * Una cuenta de banco con un IBAN inventado al azar.
     *
     * @return array{0: FinancialAccount, 1: string} La cuenta y su nº de cuenta de 10 cifras.
     */
    private function bankAccount(string $openingBalance = '0', string $openingDate = '2091-01-01'): array
    {
        $number = $this->randomNumber();
        $account = (new FinancialAccount())->setName('Banco extracto '.bin2hex(random_bytes(3)))->setKind(FinancialAccount::KIND_BANK)
            ->setIban('ES00'.'9999'.'0001'.'00'.$number)->setOpeningBalance($openingBalance)
            ->setOpeningDate(new \DateTimeImmutable($openingDate))->setActive(true)->setSortOrder(99);
        $this->em->persist($account);
        $this->em->flush();
        $this->accounts[] = $account;

        return [$account, $number];
    }

    private function randomNumber(): string
    {
        return sprintf('%010d', random_int(0, 9_999_999_999));
    }

    private function category(): BudgetCategory
    {
        $group = (new BudgetCategoryGroup())->setName('EXTRACTO '.bin2hex(random_bytes(3)))->setKind(BudgetCategoryGroup::KIND_EXPENSE)->setSortOrder(99);
        $category = (new BudgetCategory())->setGroup($group)->setName('Varios')->setSortOrder(1);
        $this->em->persist($group);
        $this->em->persist($category);
        $this->em->flush();
        $this->created[] = $group;
        $this->created[] = $category;

        return $category;
    }

    private function entry(FinancialAccount $account, BudgetCategory $category, string $date, string $amount, string $concept): AccountEntry
    {
        $entry = (new AccountEntry())->setAccount($account)->setCategory($category)->setDate(new \DateTimeImmutable($date))->setConcept($concept)->setAmount($amount);
        $this->em->persist($entry);

        return $entry;
    }
}
