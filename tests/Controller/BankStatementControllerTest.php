<?php

namespace App\Tests\Controller;

use App\Entity\AccountEntry;
use App\Entity\BankStatement;
use App\Entity\BankStatementLine;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Entity\Setting;
use App\Service\AppSettings;
use App\Service\Accounting\Norma43\Norma43Account;
use App\Tests\Service\Accounting\Norma43\BankSheet;
use App\Tests\Service\Accounting\Norma43\N43;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Las pantallas de extractos: subir uno, ver el cuadre, «Anotar» desde un movimiento
 * (llega prerrellenado y queda ligado) y el rechazo de un fichero roto.
 */
class BankStatementControllerTest extends AbstractAuthenticatedTest
{
    private ?FinancialAccount $account = null;

    private ?BudgetCategoryGroup $group = null;

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        foreach ($em->getRepository(Setting::class)->findBy(['name' => AppSettings::FEATURE_CONTABILIDAD]) as $setting) {
            $em->remove($setting);
        }
        if ($this->account !== null) {
            foreach ([BankStatementLine::class, BankStatement::class, AccountEntry::class] as $class) {
                $em->createQueryBuilder()->delete($class, 'x')->where('x.account = :a')->setParameter('a', $this->account->getId())->getQuery()->execute();
            }
            $em->remove($em->find(FinancialAccount::class, $this->account->getId()));
        }
        $em->flush();
        if ($this->group !== null) {
            $group = $em->find(BudgetCategoryGroup::class, $this->group->getId());
            array_map($em->remove(...), $em->getRepository(BudgetCategory::class)->findBy(['group' => $group]));
            $em->flush();
            $em->remove($group);
            $em->flush();
        }

        parent::tearDown();
    }

    public function testSubirVerElCuadreYAnotarDesdeUnMovimiento(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $number = sprintf('%010d', random_int(0, 9_999_999_999));
        $this->account = $this->bankAccount($number);

        $crawler = $client->request('GET', '/gestion/accounting/bank-statements');
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('form.inv-upload')->form();
        $form['file']->upload($this->tempFile(N43::file(N43::account('9999', '0001', $number, '910401', '910430', 50000, [
            ['910403', -2500, ['COMPRA SEMILLAS']],
        ]))));
        $client->submit($form);

        $this->assertResponseRedirects();
        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('h1', $this->account->getName());
        $this->assertSelectorTextContains('#sin-apunte', 'COMPRA SEMILLAS');
        // Falta el apunte de la compra: el libro acaba con 25 € de más.
        $this->assertSelectorTextNotContains('body', 'El libro cuadra con el banco');
        $this->assertSelectorTextContains('body', '25,00 € de más');
    }

    /** «Anotar» llega con cuenta, fecha, importe y concepto, y al guardar queda ligado. */
    public function testAnotarDesdeElExtractoLoLiga(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $number = sprintf('%010d', random_int(0, 9_999_999_999));
        $this->account = $this->bankAccount($number);
        $category = $this->category();
        $crawler = $client->request('GET', '/gestion/accounting/bank-statements');
        $form = $crawler->filter('form.inv-upload')->form();
        $form['file']->upload($this->tempFile(N43::file(N43::account('9999', '0001', $number, '910401', '910430', 50000, [
            ['910403', -2500, ['COMPRA SEMILLAS']],
        ]))));
        $client->submit($form);
        $crawler = $client->followRedirect();

        $crawler = $client->click($crawler->selectLink('Anotar')->link());

        $this->assertResponseIsSuccessful();
        $this->assertSame('COMPRA SEMILLAS', $crawler->filter('#account_entry_concept')->attr('value'));
        $this->assertSame('2091-04-03', $crawler->filter('#account_entry_date')->attr('value'));
        $this->assertSame((string) $this->account->getId(), $crawler->filter('#account_entry_account option[selected]')->attr('value'));
        $form = $crawler->filter('form[name="account_entry"]')->form();
        $form['account_entry[category]']->select((string) $category->getId());
        $client->submit($form);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('#sin-apunte', 'Todos los movimientos del banco de estas fechas tienen su apunte');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $line = $em->getRepository(BankStatementLine::class)->findOneBy(['account' => $this->account->getId()]);
        $this->assertNotNull($line->getEntry());
        $this->assertSame('-25.00', $line->getEntry()->getAmount());
    }

    /** Un fichero roto no guarda nada y dice por qué. */
    public function testUnFicheroRotoNoGuardaNada(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $crawler = $client->request('GET', '/gestion/accounting/bank-statements');
        $form = $crawler->filter('form.inv-upload')->form();
        $form['file']->upload($this->tempFile("esto no es un extracto\n"));
        $client->submit($form);

        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'No se ha guardado nada');
    }

    /**
     * La hoja de Excel del banco sin IBAN: se pide la cuenta (la clave no puede ir vacía
     * en el formulario) y, elegida, el extracto se guarda con sus movimientos.
     */
    public function testHojaDeExcelSinIbanPideLaCuentaYSeGuarda(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $this->account = $this->bankAccount(sprintf('%010d', random_int(0, 9_999_999_999)));
        $this->account->setIban(null);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/gestion/accounting/bank-statements');
        $form = $crawler->filter('form.inv-upload')->form();
        $form['file']->upload($this->tempFile(BankSheet::fiareLike(500.00, [
            ['2091-04-03', -25.00, 'COMPRA SEMILLAS'],
            ['2091-04-09', 40.00, 'TRF. CUOTA INVENTADA'],
        ]), 'movimientos.xls'));
        $crawler = $client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'cuenta sin IBAN en el fichero');
        $choose = $crawler->filter('form.csa-form-narrow')->form();
        $choose['account['.Norma43Account::UNKNOWN_KEY.']']->select((string) $this->account->getId());
        $client->submit($choose);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('h1', $this->account->getName());
        $this->assertSelectorTextContains('#sin-apunte', 'COMPRA SEMILLAS');
    }

    private function bankAccount(string $number): FinancialAccount
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $account = (new FinancialAccount())->setName('Banco pantalla '.bin2hex(random_bytes(3)))->setKind(FinancialAccount::KIND_BANK)
            ->setIban('ES00999900010'.'0'.$number)->setOpeningBalance('500.00')
            ->setOpeningDate(new \DateTimeImmutable('2091-01-01'))->setActive(true)->setSortOrder(99);
        $em->persist($account);
        $em->flush();

        return $account;
    }

    private function category(): BudgetCategory
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->group = (new BudgetCategoryGroup())->setName('EXTRACTO PANTALLA '.bin2hex(random_bytes(3)))->setKind(BudgetCategoryGroup::KIND_EXPENSE)->setSortOrder(99);
        $category = (new BudgetCategory())->setGroup($this->group)->setName('Semillas')->setSortOrder(1);
        $em->persist($this->group);
        $em->persist($category);
        $em->flush();

        return $category;
    }

    private function tempFile(string $content, string $name = 'extracto.n43'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'n43');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }
}
