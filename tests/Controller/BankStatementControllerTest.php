<?php

namespace App\Tests\Controller;

use App\Entity\AccountEntry;
use App\Entity\BankStatement;
use App\Entity\BankStatementLine;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Entity\ReceivedInvoice;
use App\Entity\Setting;
use App\Service\Accounting\Invoice\ExtractedInvoice;
use App\Service\AppSettings;
use App\Tests\Service\Accounting\Norma43\BankSheet;
use App\Tests\Service\Accounting\Norma43\N43;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Las pantallas de extractos: subir uno, ver el cuadre, «Anotar» desde un movimiento
 * (llega prerrellenado y queda ligado) y el rechazo de un fichero roto.
 */
class BankStatementControllerTest extends AbstractAuthenticatedTest
{
    private ?FinancialAccount $account = null;

    private ?BudgetCategoryGroup $group = null;

    /** @var list<int> Facturas aceptadas que esperan su pago; tearDown las borra antes que su partida. */
    private array $invoiceIds = [];

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        array_map($em->remove(...), array_filter(array_map(
            static fn (int $id): ?ReceivedInvoice => $em->find(ReceivedInvoice::class, $id),
            $this->invoiceIds,
        )));
        $em->flush();
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
        $form = $this->uploadForm($crawler);
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

    /** En el listado, un extracto con movimientos sin anotar lleva directo a lo que le falta. */
    public function testElListadoLlevaALoQueFaltaEnLaFicha(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $number = sprintf('%010d', random_int(0, 9_999_999_999));
        $this->account = $this->bankAccount($number);
        $crawler = $client->request('GET', '/gestion/accounting/bank-statements');
        $form = $crawler->filter('form.inv-upload')->form();
        $form['file']->upload($this->tempFile(N43::file(N43::account('9999', '0001', $number, '910401', '910430', 50000, [
            ['910403', -2500, ['COMPRA SEMILLAS']],
        ]))));
        $client->submit($form);
        $statement = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(BankStatement::class)->findOneBy(['account' => $this->account->getId()]);

        $crawler = $client->request('GET', '/gestion/accounting/bank-statements');

        $this->assertSame(
            sprintf('/gestion/accounting/bank-statements/%d#sin-apunte', $statement->getId()),
            $crawler->selectLink('Ver lo que falta')->attr('href'),
        );
    }

    /**
     * «Anotar» en la fila del movimiento: sólo se elige la partida. La cuenta, la fecha,
     * el importe y el concepto son los del banco, y queda ligado.
     */
    public function testAnotarDesdeElExtractoSoloPideLaPartidaYLoLiga(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $number = sprintf('%010d', random_int(0, 9_999_999_999));
        $this->account = $this->bankAccount($number);
        $category = $this->category();
        $crawler = $this->upload($client, $number, 'COMPRA SEMILLAS');

        $form = $crawler->filter('form.bst-book')->form();
        $form['category']->select((string) $category->getId());
        $client->submit($form);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('.csa-alerts', 'Anotado en «Semillas»: COMPRA SEMILLAS.');
        $this->assertSelectorTextContains('#sin-apunte', 'Todos los movimientos del banco de estas fechas tienen su apunte');
        $entry = $this->lineEntry();
        $this->assertSame('-25.00', $entry->getAmount());
        $this->assertSame('2091-04-03', $entry->getDate()?->format('Y-m-d'));
        $this->assertSame('COMPRA SEMILLAS', $entry->getConcept());
        $this->assertSame($category->getId(), $entry->getCategory()?->getId());
        $this->assertFalse($entry->isAutoBooked());
    }

    /** Sin partida no se anota; y un movimiento ya anotado no se anota dos veces. */
    public function testAnotarSinPartidaOYaAnotadoNoHaceNada(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $number = sprintf('%010d', random_int(0, 9_999_999_999));
        $this->account = $this->bankAccount($number);
        $category = $this->category();
        $crawler = $this->upload($client, $number, 'COMPRA SEMILLAS');
        $form = $crawler->filter('form.bst-book')->form();

        $client->submit($form);
        $client->followRedirect();
        $this->assertSelectorTextContains('.csa-alerts', 'Elige la partida del movimiento.');
        $this->assertNull($this->lineEntry());

        $form['category']->select((string) $category->getId());
        $client->submit($form);
        $client->submit($form);
        $client->followRedirect();
        $this->assertSelectorTextContains('.csa-alerts', 'Ese movimiento ya estaba anotado.');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->assertSame(1, $em->getRepository(AccountEntry::class)->count(['account' => $this->account->getId()]));
    }

    /**
     * Una factura aceptada que se paga por banco espera su pago. Si es la única que
     * espera ese importe, subir el extracto anota solo el movimiento con los datos de
     * la factura y la deja enganchada a él: no hay nada que «Anotar».
     */
    public function testSubirElExtractoAnotaSoloElPagoDeLaUnicaFacturaQueLoEspera(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $number = sprintf('%010d', random_int(0, 9_999_999_999));
        $this->account = $this->bankAccount($number);
        $category = $this->category();
        $invoiceId = $this->awaitingInvoice($category, 'Semillas del Jarama', 'S-7', 'semillas de calabaza');

        $crawler = $this->upload($client, $number, 'TRF SEMILLAS DEL JARAMA');

        $this->assertSelectorTextContains('.csa-alerts', '1 anotado solo por la app');
        $this->assertCount(0, $crawler->filter('form.bst-book'), 'No queda nada que anotar a mano.');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $line = $em->getRepository(BankStatementLine::class)->findOneBy(['account' => $this->account->getId()]);
        $invoice = $em->find(ReceivedInvoice::class, $invoiceId);
        $this->assertNotNull($line->getEntry(), 'El movimiento queda ligado a su apunte.');
        $this->assertTrue($line->getEntry()->isAutoBooked());
        $this->assertSame($line->getEntry()->getId(), $invoice->getAccountEntry()?->getId(), 'Y la factura, enganchada a ese mismo apunte.');
        $this->assertFalse($invoice->isAwaitingPayment());
        $this->assertSame($category->getId(), $line->getEntry()->getCategory()?->getId());
    }

    /**
     * Con dos facturas esperando el mismo importe, elegir es de una persona: el
     * movimiento se queda sin anotar. Su fila dice de qué factura es el pago (la que
     * nombra el banco) y trae su partida puesta; al anotar, esa factura queda
     * enganchada y la otra sigue esperando.
     */
    public function testAnotarElPagoDeUnaFacturaQueLoEsperaLaEngancha(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $number = sprintf('%010d', random_int(0, 9_999_999_999));
        $this->account = $this->bankAccount($number);
        $category = $this->category();
        $otherId = $this->awaitingInvoice($category, 'Ferretería Talamanca', 'F-3', 'tornillería');
        $invoiceId = $this->awaitingInvoice($category, 'Semillas del Jarama', 'S-7', 'semillas de calabaza');

        $crawler = $this->upload($client, $number, 'TRF SEMILLAS DEL JARAMA');
        $this->assertSelectorTextContains('#sin-apunte', 'es el pago de la factura Semillas del Jarama · fra. S-7');
        $this->assertSame((string) $category->getId(), $crawler->filter('form.bst-book option[selected]')->attr('value'), 'La partida es la de la factura.');
        $client->submit($crawler->filter('form.bst-book')->form());

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('.csa-alerts', 'Es el pago de la factura S-7: ya está enganchada.');
        $entry = $this->lineEntry();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->assertSame('Semillas del Jarama · semillas de calabaza', $entry->getConcept());
        $this->assertSame('S-7', $entry->getInvoiceNumber());
        $this->assertSame($entry->getId(), $em->find(ReceivedInvoice::class, $invoiceId)->getAccountEntry()?->getId());
        $this->assertTrue($em->find(ReceivedInvoice::class, $otherId)->isAwaitingPayment(), 'La otra sigue esperando su pago.');
    }

    /** «No es esa factura»: se anota como un movimiento cualquiera y la factura sigue esperando. */
    public function testAnotarDiciendoQueNoEsEsaFactura(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $number = sprintf('%010d', random_int(0, 9_999_999_999));
        $this->account = $this->bankAccount($number);
        $category = $this->category();
        $this->awaitingInvoice($category, 'Ferretería Talamanca', 'F-3', 'tornillería');
        $invoiceId = $this->awaitingInvoice($category, 'Semillas del Jarama', 'S-7', 'semillas de calabaza');

        $crawler = $this->upload($client, $number, 'TRF SEMILLAS DEL JARAMA');
        $form = $crawler->filter('form.bst-book')->form();
        $form['no_invoice']->tick();
        $client->submit($form);

        $client->followRedirect();
        $this->assertSelectorTextNotContains('.csa-alerts', 'Es el pago de la factura');
        $this->assertSame('TRF SEMILLAS DEL JARAMA', $this->lineEntry()->getConcept());
        $this->assertTrue(static::getContainer()->get(EntityManagerInterface::class)->find(ReceivedInvoice::class, $invoiceId)->isAwaitingPayment());
    }

    /**
     * Si el mes anterior el mismo concepto se anotó en una partida, el extracto lo dice
     * y la fila llega con esa partida puesta.
     */
    public function testAnotarProponeLaPartidaDeLasUltimasVeces(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $number = sprintf('%010d', random_int(0, 9_999_999_999));
        $this->account = $this->bankAccount($number);
        $category = $this->category();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new AccountEntry())->setAccount($em->find(FinancialAccount::class, $this->account->getId()))
            ->setCategory($em->find(BudgetCategory::class, $category->getId()))
            ->setDate(new \DateTimeImmutable('2091-03-05'))->setConcept('rcbo.caymuz, s.l.')->setAmount('-170.00'));
        $em->flush();
        $crawler = $client->request('GET', '/gestion/accounting/bank-statements');
        $form = $this->uploadForm($crawler);
        $form['file']->upload($this->tempFile(N43::file(N43::account('9999', '0001', $number, '910401', '910430', 50000, [
            ['910405', -17000, ['RCBO.CAYMUZ SL']],
        ]))));
        $client->submit($form);
        $crawler = $client->followRedirect();
        $this->assertSelectorTextContains('#sin-apunte', sprintf('en %s, como las últimas veces', $category->getName()));
        $this->assertSame((string) $category->getId(), $crawler->filter('form.bst-book option[selected]')->attr('value'), 'La partida llega puesta.');
    }

    /**
     * Un movimiento cuyo único apunte posible se anotó a 5 días (no se liga solo) sale
     * en la portada como pregunta; «Sí, es ése» los liga y vuelve a la portada.
     */
    public function testLigarDesdeLaPortada(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $number = sprintf('%010d', random_int(0, 9_999_999_999));
        $this->account = $this->bankAccount($number);
        $crawler = $client->request('GET', '/gestion/accounting/bank-statements');
        $form = $this->uploadForm($crawler);
        $form['file']->upload($this->tempFile(N43::file(N43::account('9999', '0001', $number, '910401', '910430', 50000, [
            ['910403', -2500, ['COMPRA SEMILLAS']],
        ]))));
        $client->submit($form);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $entry = (new AccountEntry())->setAccount($em->find(FinancialAccount::class, $this->account->getId()))
            ->setCategory($em->find(BudgetCategory::class, $this->category()->getId()))
            ->setDate(new \DateTimeImmutable('2091-04-08'))->setAmount('-25.00')->setConcept('Semillas de otoño');
        $em->persist($entry);
        $em->flush();
        $line = $em->getRepository(BankStatementLine::class)->findOneBy(['account' => $this->account->getId()]);

        $crawler = $client->request('GET', '/gestion/accounting/');
        $this->assertSelectorTextContains('.acc-todo', '«Semillas de otoño» del 08/04/2091');
        $client->submit($crawler->filter(sprintf('.acc-todo form[action="/gestion/accounting/bank-statements/lines/%d/link"]', $line->getId()))->form());

        $this->assertResponseRedirects('/gestion/accounting/');
        $em->clear();
        $this->assertSame($entry->getId(), $em->find(BankStatementLine::class, $line->getId())->getEntry()?->getId());
    }

    /**
     * La liquidación de la tarjeta subida antes de apuntar las compras: al volver a
     * cruzar el extracto, las compras del día que suman el cargo se juntan y se liga.
     */
    public function testVolverACruzarJuntaLasComprasDeLaTarjeta(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $number = sprintf('%010d', random_int(0, 9_999_999_999));
        $this->account = $this->bankAccount($number);
        $crawler = $client->request('GET', '/gestion/accounting/bank-statements');
        $form = $this->uploadForm($crawler);
        $form['file']->upload($this->tempFile(N43::file(N43::account('9999', '0001', $number, '910601', '910630', 50000, [
            ['910605', -17562, ['TARJETA TJ MIXTA PERSON']],
        ]))));
        $client->submit($form);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $account = $em->find(FinancialAccount::class, $this->account->getId());
        $category = $em->find(BudgetCategory::class, $this->category()->getId());
        $purchases = array_map(static fn (string $amount): AccountEntry => (new AccountEntry())->setAccount($account)->setCategory($category)
            ->setDate(new \DateTimeImmutable('2091-06-05'))->setAmount($amount)->setConcept('Compra con tarjeta'), ['-20.00', '-32.80', '-88.33', '-34.49']);
        array_walk($purchases, $em->persist(...));
        $em->flush();
        $line = $em->getRepository(BankStatementLine::class)->findOneBy(['account' => $this->account->getId()]);
        $statement = $em->getRepository(BankStatement::class)->findOneBy(['account' => $this->account->getId()]);

        $crawler = $client->request('GET', sprintf('/gestion/accounting/bank-statements/%d', $statement->getId()));
        $client->submit($crawler->filter('form.bst-relink')->form());

        $client->followRedirect();
        $this->assertSelectorTextContains('.csa-alerts', '1 ligado al apunte que ya lo tenía.');
        $em->clear();
        $this->assertSame($purchases[0]->getId(), $em->find(BankStatementLine::class, $line->getId())->getEntry()?->getId());
        $this->assertSame($purchases[0]->getId(), $em->find(AccountEntry::class, $purchases[3]->getId())->getSplitFrom()?->getId());
    }

    /** Un fichero roto no guarda nada y dice por qué. */
    public function testUnFicheroRotoNoGuardaNada(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $this->account = $this->bankAccount(sprintf('%010d', random_int(0, 9_999_999_999)));
        $crawler = $client->request('GET', '/gestion/accounting/bank-statements');
        $form = $this->uploadForm($crawler);
        $form['file']->upload($this->tempFile("esto no es un extracto\n"));
        $client->submit($form);

        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'No se ha guardado nada');
    }

    /**
     * Se elige la cuenta antes que el fichero: la hoja de Excel de Fiare, que no trae
     * IBAN, se guarda sin preguntar nada más en la cuenta elegida, aunque ésta tenga
     * su IBAN (el fichero no lo contradice).
     */
    public function testHojaDeExcelSinIbanSeGuardaEnLaCuentaElegida(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $this->account = $this->bankAccount(sprintf('%010d', random_int(0, 9_999_999_999)));

        $crawler = $client->request('GET', '/gestion/accounting/bank-statements');
        $form = $this->uploadForm($crawler);
        $form['file']->upload($this->tempFile(BankSheet::fiareLike(500.00, [
            ['2091-04-03', -25.00, 'COMPRA SEMILLAS'],
            ['2091-04-09', 40.00, 'TRF. CUOTA INVENTADA'],
        ]), 'movimientos.xls'));
        $client->submit($form);

        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertSelectorTextContains('h1', $this->account->getName());
        $this->assertSelectorTextContains('#sin-apunte', 'COMPRA SEMILLAS');
    }

    /** Sin cuenta elegida (o una que no se puede elegir) no se sube nada. */
    public function testSinCuentaNoSeSubeNada(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $number = sprintf('%010d', random_int(0, 9_999_999_999));
        $this->account = $this->bankAccount($number);

        $crawler = $client->request('GET', '/gestion/accounting/bank-statements');
        $form = $this->uploadForm($crawler);
        $form['account']->setValue('999999999');
        $form['file']->upload($this->tempFile(N43::file(N43::account('9999', '0001', $number, '910401', '910430', 50000, [['910403', -2500, ['COMPRA']]]))));
        $client->submit($form);

        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Elige primero de qué cuenta es el extracto');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->assertSame(0, $em->getRepository(BankStatement::class)->count(['account' => $this->account->getId()]));
    }

    /** El formulario de subida de la cuenta del test, entre las de todas las cuentas. */
    private function uploadForm(Crawler $crawler): Form
    {
        return $crawler->filter(sprintf('form.inv-upload input[name="account"][value="%d"]', $this->account->getId()))->closest('form')->form();
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

    /**
     * Una factura aceptada de 25 € que espera su pago por banco, para que tearDown la borre.
     *
     * @return int Su id.
     */
    private function awaitingInvoice(BudgetCategory $category, string $provider, string $number, string $concept): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $invoice = new ReceivedInvoice('pago-esperado.pdf', 'pago-esperado.pdf', 'application/pdf', ReceivedInvoice::SOURCE_WEB, null);
        $invoice->markRead(ExtractedInvoice::fromArray([
            'fecha' => '2091-03-28', 'total' => 25, 'proveedor' => $provider, 'numero_factura' => $number, 'concepto' => $concept,
        ]), 'test', null, new \DateTimeImmutable());
        $invoice->acceptUnpaid($category, '25.00', null, $provider, $number);
        $em->persist($invoice);
        $em->flush();
        $this->invoiceIds[] = $invoice->getId();

        return $invoice->getId();
    }

    /**
     * Sube un extracto de la cuenta con un solo cargo de 25 € y sigue la redirección.
     *
     * @return Crawler La pantalla del extracto.
     */
    private function upload(KernelBrowser $client, string $number, string $text): Crawler
    {
        $crawler = $client->request('GET', '/gestion/accounting/bank-statements');
        $form = $this->uploadForm($crawler);
        $form['file']->upload($this->tempFile(N43::file(N43::account('9999', '0001', $number, '910401', '910430', 50000, [
            ['910403', -2500, [$text]],
        ]))));
        $client->submit($form);

        return $client->followRedirect();
    }

    /** El apunte del (único) movimiento de la cuenta de la prueba, recién leído. */
    private function lineEntry(): ?AccountEntry
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(BankStatementLine::class)->findOneBy(['account' => $this->account->getId()])?->getEntry();
    }

    private function tempFile(string $content, string $name = 'extracto.n43'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'n43');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }
}
