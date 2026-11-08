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
        $import = $this->import($account, $march);

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
        $again = $this->import($account, $march);
        $this->assertSame(0, $again->added);
        $this->assertSame(3, $again->alreadyThere);
        $this->assertSame($statement->getId(), $again->statements[0]->getId());

        // Uno que se solapa (del 12 al 15): sólo entra el movimiento nuevo.
        $overlap = $this->import($account, N43::file(N43::account('9999', '0001', $number, '910312', '910315', 120000 - 4550, [
            ['910312', 3000, ['TRANSFERENCIA']],
            ['910312', 3000, ['TRANSFERENCIA']],
            ['910314', -120, ['COMISION']],
        ])));
        $this->assertSame(1, $overlap->added);
        $this->assertSame(2, $overlap->alreadyThere);
        $this->assertSame(4, $this->em->getRepository(BankStatementLine::class)->count(['account' => $account]));
    }

    /**
     * El cargo del Ayuntamiento se partió (alquiler + suministros) antes de subir el
     * extracto. El banco trae el movimiento entero: se liga solo al apunte partido, y
     * la parte no sale como «en el libro y no en el banco».
     */
    public function testUnApuntePartidoSeLigaPorElMovimientoEntero(): void
    {
        [$account, $number] = $this->bankAccount();
        $category = $this->category();
        $rent = $this->entry($account, $category, '2091-10-09', '-158.95', 'TRF. AYUNTAMIENTO');
        $this->em->persist($rent->splitOff('50.00', $category, 'Suministros del local'));
        $this->em->flush();
        $this->em->clear();

        $import = $this->import($account, N43::file(N43::account('9999', '0001', $number, '911001', '911031', 0, [
            ['911009', -15895, ['TRF. AYUNTAMIENTO']],
        ])));

        $this->assertSame(1, $import->linked);
        $line = $this->em->getRepository(BankStatementLine::class)->findOneBy(['account' => $account->getId()]);
        $this->assertSame($rent->getId(), $line->getEntry()->getId());
        $review = static::getContainer()->get(StatementReviewer::class)->review($import->statements[0]);
        $this->assertSame([], $review->unbanked);
        $this->assertSame(0, $review->closingDiffCents());
    }

    /**
     * El mismo mes en Norma 43 y, después, dentro de la hoja de Excel del banco (que no
     * trae documento ni referencias): sólo entra lo que la hoja trae de más.
     */
    public function testNorma43YHojaDelMismoMesNoDuplican(): void
    {
        [$account, $number] = $this->bankAccount(openingBalance: '1200.00');
        $this->import($account, N43::file(N43::account('9999', '0001', $number, '910301', '910331', 120000, [
            ['910305', -4550, ['RECIBO LUZ']],
            ['910312', 3000, ['TRANSFERENCIA']],
            ['910312', 3000, ['TRANSFERENCIA']],
        ])));

        $sheet = $this->import($account, BankSheet::caixaLike('ES00'.'9999'.'0001'.'00'.$number, 1200.00, [
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
            $this->import($account, N43::file(N43::account('9999', '0001', $number, '910301', '910331', 2000, [['910305', 3000]])))->statements[0],
        );

        $this->assertNull($review->bookOpeningCents);
        $this->assertSame(5000, $review->bookClosingCents);
        $this->assertSame(0, $review->closingDiffCents());
        $this->assertSame([], $review->unlinked, 'lo anterior a la apertura ya está en el saldo de apertura');
    }

    /** Mismas fechas con otros saldos: uno de los dos está mal, se rechaza. */
    public function testMismoPeriodoConOtrosSaldosSeRechaza(): void
    {
        [$account, $number] = $this->bankAccount();
        $this->import($account, N43::file(N43::account('9999', '0001', $number, '910301', '910331', 1000, [])));

        $this->expectException(Norma43Exception::class);
        $this->expectExceptionMessage('con otros saldos');
        $this->import($account, N43::file(N43::account('9999', '0001', $number, '910301', '910331', 2000, [])));
    }

    /** A una cuenta retirada o que no es de banco no se sube nada, ni se ofrece. */
    public function testCuentaRetiradaONoBancariaSeRechaza(): void
    {
        [$retired, $number] = $this->bankAccount();
        $retired->setActive(false);
        [$cash, $cashNumber] = $this->bankAccount();
        $cash->setKind(FinancialAccount::KIND_CASH);
        $this->em->flush();

        foreach ([[$retired, $number], [$cash, $cashNumber]] as [$account, $n]) {
            try {
                $this->import($account, N43::file(N43::account('9999', '0001', $n, '910301', '910331', 0, [])));
                $this->fail('Tenía que rechazarse.');
            } catch (Norma43Exception $e) {
                $this->assertStringContainsString('no es una cuenta de banco activa', $e->getMessage());
            }
        }
        $offered = array_map(static fn (FinancialAccount $a): ?int => $a->getId(), $this->importer()->uploadableAccounts());
        $this->assertNotContains($retired->getId(), $offered);
        $this->assertNotContains($cash->getId(), $offered);
    }

    /** El fichero trae el IBAN de otra cuenta del libro: se dice cuál y no se guarda. */
    public function testElIbanDeOtraCuentaSeRechaza(): void
    {
        [$owner, $number] = $this->bankAccount();
        [$chosen] = $this->bankAccount();
        $chosen->setIban(null);
        $this->em->flush();

        $this->expectException(Norma43Exception::class);
        $this->expectExceptionMessage('que en el libro es «'.$owner->getName().'»');
        $this->import($chosen, N43::file(N43::account('9999', '0001', $number, '910301', '910331', 0, [])));
    }

    /**
     * Un IBAN que no es de ninguna cuenta del libro: si la elegida tiene otro, se
     * rechaza; si no tiene ninguno, nada lo contradice y se guarda.
     */
    public function testUnIbanQueNoEsElDeLaCuenta(): void
    {
        [$withIban] = $this->bankAccount();
        [$noIban] = $this->bankAccount();
        $noIban->setIban(null);
        $this->em->flush();
        $file = N43::file(N43::account('9999', '0001', $this->randomNumber(), '910301', '910331', 0, [['910305', -100, ['COMISION']]]));

        try {
            $this->import($withIban, $file);
            $this->fail('Tenía que rechazarse.');
        } catch (Norma43Exception $e) {
            $this->assertStringContainsString('tiene otro IBAN', $e->getMessage());
        }
        $this->assertSame(1, $this->import($noIban, $file)->added);
    }

    /**
     * El saldo inicial sigue al final del último extracto. Si no, falta algo entre los
     * dos (o el fichero es de otra cuenta) y no se guarda; si sigue, sí.
     */
    public function testElSaldoInicialSigueAlDelUltimoExtracto(): void
    {
        [$account, $number] = $this->bankAccount();
        $this->import($account, N43::file(N43::account('9999', '0001', $number, '910301', '910331', 10000, [['910305', -2500, ['RECIBO']]])));

        try {
            $this->import($account, N43::file(N43::account('9999', '0001', $number, '910401', '910430', 9000, [])));
            $this->fail('Tenía que rechazarse.');
        } catch (Norma43Exception $e) {
            $this->assertStringContainsString('Falta algún movimiento entre los dos', $e->getMessage());
            $this->assertStringContainsString('75,00 €', $e->getMessage());
        }
        $this->assertCount(1, $this->import($account, N43::file(N43::account('9999', '0001', $number, '910401', '910430', 7500, [])))->statements);
    }

    /**
     * El cargo con el concepto de la liquidación de una tarjeta se anota solo como
     * traspaso del banco a la tarjeta, con sus dos mitades, y queda ligado.
     */
    public function testLaLiquidacionDeLaTarjetaSeAnotaSolaComoTraspaso(): void
    {
        [$bank, $number] = $this->bankAccount();
        $card = $this->card('TARJETA TJ MIXTA PERSON');
        $this->transferCategory();

        $import = $this->import($bank, N43::file(N43::account('9999', '0001', $number, '911001', '911031', 100000, [
            ['911005', -24690, ['TARJETA TJ MIXTA PERSON']],
        ])));

        $this->assertSame(1, $import->booked);
        $this->em->clear();
        $out = $this->em->getRepository(BankStatementLine::class)->findOneBy(['account' => $bank->getId()])->getEntry();
        $this->assertNotNull($out);
        $this->assertSame('-246.90', $out->getAmount());
        $this->assertTrue($out->isTransfer());
        $this->assertTrue($out->isAutoBooked());
        $this->assertSame($card->getId(), $out->getTransferPeer()?->getAccount()?->getId());
        $this->assertSame('246.90', $out->getTransferPeer()?->getAmount());
        $this->assertSame('2091-10-05', $out->getTransferPeer()?->getDate()?->format('Y-m-d'));
    }

    /**
     * Si ese día el banco ya tiene gastos sueltos (las compras apuntadas en el banco,
     * a la antigua), un traspaso las contaría dos veces: no se anota.
     */
    public function testConLasComprasApuntadasEnElBancoNoSeHaceElTraspaso(): void
    {
        [$bank, $number] = $this->bankAccount();
        $this->card('TARJETA TJ MIXTA PERSON');
        $this->transferCategory();
        $this->entry($bank, $this->category(), '2091-10-05', '-100.00', 'FERRETERIA PAGO CON TARJETA');
        $this->em->flush();

        $import = $this->import($bank, N43::file(N43::account('9999', '0001', $number, '911001', '911031', 100000, [
            ['911005', -24690, ['TARJETA TJ MIXTA PERSON']],
        ])));

        $this->assertSame(0, $import->booked);
        $this->em->clear();
        $this->assertNull($this->em->getRepository(BankStatementLine::class)->findOneBy(['account' => $bank->getId()])->getEntry());
    }

    /** Una tarjeta con el concepto con que el banco la cobra. */
    private function card(string $settlementConcept): FinancialAccount
    {
        $card = (new FinancialAccount())->setName('Tarjeta extracto '.bin2hex(random_bytes(3)))->setKind(FinancialAccount::KIND_CARD)
            ->setSettlementConcept($settlementConcept)->setOpeningBalance('0')
            ->setOpeningDate(new \DateTimeImmutable('2091-01-01'))->setActive(true)->setSortOrder(99);
        $this->em->persist($card);
        $this->em->flush();
        $this->accounts[] = $card;

        return $card;
    }

    /** Una partida de traspasos, por si la base de pruebas no la tiene sembrada. */
    private function transferCategory(): void
    {
        $group = (new BudgetCategoryGroup())->setName('TRASPASOS '.bin2hex(random_bytes(3)))->setKind(BudgetCategoryGroup::KIND_TRANSFER)->setSortOrder(99);
        $category = (new BudgetCategory())->setGroup($group)->setName('Traspaso entre cuentas')->setSortOrder(1);
        $this->em->persist($group);
        $this->em->persist($category);
        $this->em->flush();
        $this->created[] = $group;
        $this->created[] = $category;
    }

    /**
     * El fichero del año, bajado otra vez más largo, empieza el mismo día: su saldo
     * inicial tiene que ser el mismo. Uno que se solapa a medias no se compara.
     */
    public function testElMismoFicheroMasLargoEmpiezaConElMismoSaldo(): void
    {
        [$account, $number] = $this->bankAccount();
        $this->import($account, N43::file(N43::account('9999', '0001', $number, '910101', '910331', 10000, [])));

        try {
            $this->import($account, N43::file(N43::account('9999', '0001', $number, '910101', '910430', 20000, [])));
            $this->fail('Tenía que rechazarse.');
        } catch (Norma43Exception $e) {
            $this->assertStringContainsString('empieza ese mismo día', $e->getMessage());
        }
        $this->import($account, N43::file(N43::account('9999', '0001', $number, '910101', '910430', 10000, [])));
        $this->import($account, N43::file(N43::account('9999', '0001', $number, '910315', '910415', 55555, [])));
        $this->assertSame(3, $this->em->getRepository(BankStatement::class)->count(['account' => $account->getId()]));
    }

    /** La hoja sin IBAN ya subida a otra cuenta: es el extracto de aquélla, no se guarda. */
    public function testLosMovimientosYaSubidosAOtraCuentaSeRechazan(): void
    {
        [$fiare] = $this->bankAccount();
        [$other] = $this->bankAccount();
        $sheet = BankSheet::fiareLike(500.00, [['2091-03-05', -10.00, 'COMPRA INVENTADA'], ['2091-03-07', 25.00, 'TRF INVENTADA']]);
        $this->import($fiare, $sheet);

        $this->expectException(Norma43Exception::class);
        $this->expectExceptionMessage('2 de los 2 movimientos del fichero ya se subieron a «'.$fiare->getName().'»');
        $this->import($other, $sheet);
    }

    /** Un fichero Norma 43 con dos cuentas: no se sabe cuál es la elegida. */
    public function testUnFicheroConDosCuentasSeRechaza(): void
    {
        [$account, $number] = $this->bankAccount();
        $two = N43::file([
            ...N43::account('9999', '0001', $number, '910301', '910331', 0, []),
            ...N43::account('9999', '0001', $this->randomNumber(), '910301', '910331', 0, []),
        ]);

        try {
            $this->import($account, $two);
            $this->fail('Tenía que rechazarse.');
        } catch (Norma43Exception $e) {
            $this->assertStringContainsString('El fichero trae 2 cuentas', $e->getMessage());
        }
        $this->assertSame(0, $this->em->getRepository(BankStatement::class)->count(['account' => $account->getId()]));
    }

    private function importer(): BankStatementImporter
    {
        return static::getContainer()->get(BankStatementImporter::class);
    }

    /** Sube el extracto a la cuenta, como quien la elige antes del fichero. */
    private function import(FinancialAccount $account, string $raw): BankStatementImport
    {
        return $this->importer()->import(
            static::getContainer()->get(BankStatementReader::class)->read($raw),
            $this->em->find(FinancialAccount::class, $account->getId()),
            'extracto',
            null,
        );
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
