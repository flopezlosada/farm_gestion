<?php

namespace App\Tests\Controller;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\Customer;
use App\Entity\FinancialAccount;
use App\Entity\IssuedInvoice;
use App\Entity\IssuedInvoiceLine;
use App\Entity\Setting;
use App\Service\Accounting\IssuedInvoice\IssuedInvoiceArchive;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Las facturas emitidas por HTTP: quién entra, hacer un borrador con el
 * formulario, emitirlo (número y PDF guardado), que una emitida no se toca, y
 * rectificarla.
 *
 * Las fechas van en 2091 para que la numeración del año empiece en 1 aunque la
 * base de tests tenga otras facturas.
 */
class IssuedInvoiceControllerTest extends AbstractAuthenticatedTest
{
    private const TAX_ID = 'Q2891001F';

    /** Ajustes que tocan estos tests; se quitan al acabar. */
    private const SETTINGS = [
        AppSettings::FEATURE_CONTABILIDAD,
        AppSettings::ISSUED_INVOICE_ISSUER,
        AppSettings::ISSUED_INVOICE_PAYMENT,
    ];

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $archive = static::getContainer()->get(IssuedInvoiceArchive::class);

        foreach ($em->getRepository(Customer::class)->findBy(['taxId' => self::TAX_ID]) as $customer) {
            // Las rectificativas antes que la factura que rectifican.
            foreach (array_reverse($em->getRepository(IssuedInvoice::class)->findBy(['customer' => $customer], ['id' => 'ASC'])) as $invoice) {
                if (($path = $archive->pathTo($invoice)) !== null) {
                    unlink($path);
                }
                $em->remove($invoice);
                $em->flush();
            }
            $em->remove($customer);
        }
        foreach ($em->getRepository(Setting::class)->findBy(['name' => self::SETTINGS]) as $setting) {
            $em->remove($setting);
        }
        $em->flush();

        parent::tearDown();
    }

    public function testConElFlagApagadoNoExiste(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/accounting/issued-invoices');

        $this->assertResponseStatusCodeSame(403);
    }

    /** Con lectura se ven; hacerlas pide el permiso de edición. */
    public function testConLecturaSeVenPeroNoSeHacen(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_GESTION_CONTABILIDAD']));
        $this->enableModule();

        $client->request('GET', '/gestion/accounting/issued-invoices');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('a[href="/gestion/accounting/issued-invoices/new"]');

        $client->request('GET', '/gestion/accounting/issued-invoices/new');
        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * El recorrido entero: el cliente y el borrador con sus formularios, emitirlo
     * (recibe el 1 del año y su PDF queda guardado) y el PDF servido.
     */
    public function testHacerUnBorradorEmitirloYVerSuPdf(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $this->configureIssuer();

        $crawler = $client->request('GET', '/gestion/accounting/customers/new');
        $client->submit($crawler->filter('form[name="customer"]')->form([
            'customer[name]' => 'Ayuntamiento de Pruebas',
            'customer[taxId]' => 'q-2891001-f',
            'customer[postalCode]' => '28189',
            'customer[town]' => 'Torremocha de Jarama',
        ]));
        $customer = $this->em()->getRepository(Customer::class)->findOneBy(['taxId' => self::TAX_ID]);
        $this->assertNotNull($customer, 'el CIF se guarda normalizado');

        $crawler = $client->request('GET', '/gestion/accounting/issued-invoices/new');
        $values = $crawler->filter('form[name="issued_invoice"]')->form()->getPhpValues();
        $values['issued_invoice'] = array_replace($values['issued_invoice'], [
            'customer' => (string) $customer->getId(),
            'issueDate' => '2091-06-30',
            'series' => IssuedInvoice::SERIES_ORDINARY,
            'description' => 'Visita formativa',
            'exemptionNote' => '',
            // Las líneas las añade el JavaScript: van a mano.
            'lines' => [
                ['concept' => 'Visita guiada', 'amount' => '300', 'vatRate' => '0'],
                ['concept' => 'Comida', 'amount' => '100.50', 'vatRate' => '21'],
            ],
        ]);
        $client->request('POST', '/gestion/accounting/issued-invoices/new', $values);
        $this->assertResponseRedirects();

        $invoice = $this->onlyInvoiceOf($customer);
        $this->assertTrue($invoice->isDraft());
        $this->assertNull($invoice->getNumber(), 'un borrador no tiene número');
        // 300 exentos + 100,50 + 21,11 de IVA (21,105 redondea hacia arriba).
        $this->assertSame('421.61', $invoice->getTotal());
        $this->assertNotNull($invoice->getExemptionNote(), 'con una línea exenta se pone el texto de siempre');

        $crawler = $client->request('GET', sprintf('/gestion/accounting/issued-invoices/%d', $invoice->getId()));
        $client->submit($crawler->filter(sprintf('form[action="/gestion/accounting/issued-invoices/%d/issue"]', $invoice->getId()))->form());
        $this->assertResponseRedirects();

        $invoice = $this->onlyInvoiceOf($customer);
        $this->assertTrue($invoice->isIssued());
        $this->assertSame('1/2091', $invoice->getNumberLabel());
        $this->assertSame('Ayuntamiento de Pruebas', $invoice->getCustomerName());
        $this->assertNotNull(static::getContainer()->get(IssuedInvoiceArchive::class)->pathTo($invoice), 'el PDF se guarda al emitirla');

        $client->request('GET', sprintf('/gestion/accounting/issued-invoices/%d/pdf', $invoice->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
    }

    /** Sin los datos de la asociación no se emite: el borrador sigue siéndolo. */
    public function testSinLosDatosDeLaAsociacionNoSeEmite(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $invoice = $this->draft();

        $crawler = $client->request('GET', sprintf('/gestion/accounting/issued-invoices/%d', $invoice->getId()));
        $this->assertSelectorTextContains('.iss-problems', 'datos de la asociación');
        $client->request('POST', sprintf('/gestion/accounting/issued-invoices/%d/issue', $invoice->getId()), [
            '_token' => $crawler->filter('form[action$="/issue"] input[name="_token"]')->attr('value'),
        ]);
        $client->followRedirect();

        $this->assertSelectorTextContains('body', 'Faltan los datos de la asociación');
        $this->assertTrue($this->onlyInvoiceOf($invoice->getCustomer())->isDraft());
    }

    /**
     * El número es correlativo: un borrador con fecha anterior a la última emitida
     * de su serie no se emite, porque su número iría por delante de su fecha. La
     * ficha lo dice antes de pulsar y, con la misma fecha, sí se emite.
     */
    public function testNoSeEmiteConFechaAnteriorALaUltimaDeSuSerie(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $this->configureIssuer();
        $september = $this->draft('2091-09-15');
        $crawler = $client->request('GET', sprintf('/gestion/accounting/issued-invoices/%d', $september->getId()));
        $client->submit($crawler->filter('form[action$="/issue"]')->form());

        $june = $this->draft('2091-06-30');
        $crawler = $client->request('GET', sprintf('/gestion/accounting/issued-invoices/%d', $june->getId()));
        $this->assertSelectorTextContains('.iss-problems', 'es del 15/09/2091');
        $this->assertSelectorExists('form[action$="/issue"] button[disabled]');
        $client->request('POST', sprintf('/gestion/accounting/issued-invoices/%d/issue', $june->getId()), [
            '_token' => $crawler->filter('form[action$="/issue"] input[name="_token"]')->attr('value'),
        ]);
        $this->em()->clear();
        $this->assertTrue($this->em()->find(IssuedInvoice::class, $june->getId())->isDraft(), 'se manda a mano y tampoco');

        $sameDay = $this->draft('2091-09-15');
        $crawler = $client->request('GET', sprintf('/gestion/accounting/issued-invoices/%d', $sameDay->getId()));
        $client->submit($crawler->filter('form[action$="/issue"]')->form());
        $this->em()->clear();
        $this->assertSame(2, $this->em()->find(IssuedInvoice::class, $sameDay->getId())->getNumber());
    }

    /** Una emitida ni se cambia ni se borra; se rectifica, y la rectificativa nace en borrador en su serie. */
    public function testUnaEmitidaSoloSeRectifica(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $this->configureIssuer();
        $invoice = $this->draft();
        $crawler = $client->request('GET', sprintf('/gestion/accounting/issued-invoices/%d', $invoice->getId()));
        $client->submit($crawler->filter('form[action$="/issue"]')->form());

        $client->request('GET', sprintf('/gestion/accounting/issued-invoices/%d/edit', $invoice->getId()));
        $this->assertResponseRedirects(sprintf('/gestion/accounting/issued-invoices/%d', $invoice->getId()));

        $crawler = $client->request('GET', sprintf('/gestion/accounting/issued-invoices/%d', $invoice->getId()));
        $this->assertSelectorNotExists('form[action$="/delete"]', 'una emitida no ofrece borrarse');
        $this->assertSelectorNotExists('form[action$="/issue"]');

        $client->submit($crawler->filter('form[action$="/rectify"]')->form());
        $this->assertResponseRedirects();

        $rectification = $this->em()->getRepository(IssuedInvoice::class)->findOneBy(['rectifies' => $invoice->getId()]);
        $this->assertNotNull($rectification);
        $this->assertTrue($rectification->isDraft());
        $this->assertSame(IssuedInvoice::SERIES_RECTIFYING, $rectification->getSeries());
        $this->assertSame('-121.00', $rectification->getTotal(), 'tal cual, anula la original entera');
    }

    /**
     * Una emitida se liga al apunte del banco en que se cobró: la ficha lo propone
     * por su importe exacto, se liga, deja de proponerse y se puede desligar. Uno
     * de otro importe no se acepta aunque se mande a mano.
     */
    public function testUnaEmitidaSeLigaASuCobro(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $this->configureIssuer();
        $invoice = $this->draft();
        $crawler = $client->request('GET', sprintf('/gestion/accounting/issued-invoices/%d', $invoice->getId()));
        $client->submit($crawler->filter('form[action$="/issue"]')->form());

        $em = $this->em();
        $suffix = bin2hex(random_bytes(3));
        $account = (new FinancialAccount())->setName('Banco de prueba ' . $suffix)->setKind(FinancialAccount::KIND_BANK)
            ->setOpeningBalance('0.00')->setOpeningDate(new \DateTimeImmutable('2091-01-01'))->setActive(true)->setSortOrder(99);
        $group = (new BudgetCategoryGroup())->setName('INGRESOS PRUEBA ' . $suffix)->setKind(BudgetCategoryGroup::KIND_INCOME)->setSortOrder(99);
        $category = (new BudgetCategory())->setGroup($group)->setName('Formación')->setSortOrder(1);
        // El cobro llega tres semanas después; el otro es de un céntimo menos.
        $payment = (new AccountEntry())->setAccount($account)->setCategory($category)
            ->setDate(new \DateTimeImmutable('2091-03-26'))->setConcept('trf. cooperativa')->setAmount('121.00');
        $other = (new AccountEntry())->setAccount($account)->setCategory($category)
            ->setDate(new \DateTimeImmutable('2091-03-26'))->setConcept('trf. otra')->setAmount('120.99');
        array_map([$em, 'persist'], [$account, $group, $category, $payment, $other]);
        $em->flush();
        [$paymentId, $otherId] = [$payment->getId(), $other->getId()];

        try {
            $crawler = $client->request('GET', sprintf('/gestion/accounting/issued-invoices/%d', $invoice->getId()));
            $this->assertCount(1, $crawler->filter('form[action$="/payment"]'), 'sólo el apunte de su importe exacto');
            $form = $crawler->filter('form[action$="/payment"]')->form();

            $client->request('POST', $form->getUri(), ['_token' => $form['_token']->getValue(), 'entry' => $otherId]);
            $this->assertFalse($this->em()->find(IssuedInvoice::class, $invoice->getId())->isPaid(), 'otro importe no se liga');

            $client->submit($form);
            $this->assertResponseRedirects();
            $this->em()->clear();
            $this->assertSame($paymentId, $this->em()->find(IssuedInvoice::class, $invoice->getId())->getAccountEntry()?->getId());

            $crawler = $client->request('GET', sprintf('/gestion/accounting/issued-invoices/%d', $invoice->getId()));
            $this->assertSelectorTextContains('.csa-alert--success', 'Cobrada el 26/03/2091');
            $this->assertCount(0, $crawler->filter('form[action$="/payment"]'));

            $client->submit($crawler->filter('form[action$="/payment/remove"]')->form());
            $this->em()->clear();
            $this->assertFalse($this->em()->find(IssuedInvoice::class, $invoice->getId())->isPaid());
            $this->assertNotNull($this->em()->find(AccountEntry::class, $paymentId), 'el apunte sigue en el libro');
        } finally {
            $em = $this->em();
            $em->clear();
            foreach ([[AccountEntry::class, $paymentId], [AccountEntry::class, $otherId], [BudgetCategory::class, $category->getId()],
                      [BudgetCategoryGroup::class, $group->getId()], [FinancialAccount::class, $account->getId()]] as [$class, $id]) {
                if (($row = $em->find($class, $id)) !== null) {
                    $em->remove($row);
                    $em->flush();
                }
            }
        }
    }

    /**
     * El apunte de un cobro dice de qué factura es, y borrarlo no la deja «cobrada»
     * por un movimiento que ya no existe: vuelve a sin cobrar, y se avisa.
     */
    public function testElApunteDelCobroLlevaASuFacturaYBorrarloLaDejaSinCobrar(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $this->configureIssuer();
        $invoice = $this->draft();
        $crawler = $client->request('GET', sprintf('/gestion/accounting/issued-invoices/%d', $invoice->getId()));
        $client->submit($crawler->filter('form[action$="/issue"]')->form());

        $em = $this->em();
        $suffix = bin2hex(random_bytes(3));
        $account = (new FinancialAccount())->setName('Banco de prueba ' . $suffix)->setKind(FinancialAccount::KIND_BANK)
            ->setOpeningBalance('0.00')->setOpeningDate(new \DateTimeImmutable('2091-01-01'))->setActive(true)->setSortOrder(99);
        $group = (new BudgetCategoryGroup())->setName('INGRESOS PRUEBA ' . $suffix)->setKind(BudgetCategoryGroup::KIND_INCOME)->setSortOrder(99);
        $category = (new BudgetCategory())->setGroup($group)->setName('Formación')->setSortOrder(1);
        $payment = (new AccountEntry())->setAccount($account)->setCategory($category)
            ->setDate(new \DateTimeImmutable('2091-03-26'))->setConcept('trf. cooperativa')->setAmount('121.00');
        array_map([$em, 'persist'], [$account, $group, $category, $payment]);
        $em->flush();
        $paymentId = $payment->getId();

        try {
            $crawler = $client->request('GET', sprintf('/gestion/accounting/issued-invoices/%d', $invoice->getId()));
            $client->submit($crawler->filter('form[action$="/payment"]')->form());
            $this->em()->clear();
            $label = $this->em()->find(IssuedInvoice::class, $invoice->getId())->getNumberLabel();

            $crawler = $client->request('GET', sprintf('/gestion/accounting/entry/%d', $paymentId));
            $this->assertResponseIsSuccessful();
            $this->assertSelectorTextContains('.ent-facts', 'Cobro de');
            $this->assertCount(1, $crawler->filter(sprintf('.ent-facts a[href="/gestion/accounting/issued-invoices/%d"]', $invoice->getId())));

            $client->submit($crawler->filter('form[action$="/delete"]')->form());
            $client->followRedirect();
            $this->assertSelectorTextContains('body', sprintf('La factura %s vuelve a estar sin cobrar', $label));
            $this->em()->clear();
            $this->assertFalse($this->em()->find(IssuedInvoice::class, $invoice->getId())->isPaid());
            $this->assertNull($this->em()->find(AccountEntry::class, $paymentId));
        } finally {
            $em = $this->em();
            $em->clear();
            foreach ([[AccountEntry::class, $paymentId], [BudgetCategory::class, $category->getId()],
                      [BudgetCategoryGroup::class, $group->getId()], [FinancialAccount::class, $account->getId()]] as [$class, $id]) {
                if (($row = $em->find($class, $id)) !== null) {
                    $em->remove($row);
                    $em->flush();
                }
            }
        }
    }

    /** Un borrador se puede tirar: no tiene número, no deja hueco. */
    public function testUnBorradorSeTira(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $invoice = $this->draft();
        $id = $invoice->getId();

        $crawler = $client->request('GET', sprintf('/gestion/accounting/issued-invoices/%d', $id));
        $client->submit($crawler->filter('form[action$="/delete"]')->form());

        $this->assertResponseRedirects('/gestion/accounting/issued-invoices');
        $this->assertNull($this->em()->find(IssuedInvoice::class, $id));
    }

    private function enableModule(): void
    {
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
    }

    private function configureIssuer(): void
    {
        $settings = static::getContainer()->get(AppSettings::class);
        $settings->setString(AppSettings::ISSUED_INVOICE_ISSUER, "Asociación de pruebas\nCIF: G00000000\nC/ Ejemplo 1");
        $settings->setString(AppSettings::ISSUED_INVOICE_PAYMENT, 'Por transferencia a la cuenta ES00 0000 0000 0000 0000 0000');
    }

    /** Un borrador listo para emitir: 100 € al 21 %. */
    private function draft(string $date = '2091-03-05'): IssuedInvoice
    {
        $em = $this->em();
        $customer = (new Customer())->setName('Cooperativa de pruebas')->setTaxId(self::TAX_ID);
        $invoice = (new IssuedInvoice())
            ->setCustomer($customer)
            ->setIssueDate(new \DateTimeImmutable($date))
            ->addLine(new IssuedInvoiceLine('Colaboración', '100.00', '21'));
        $em->persist($customer);
        $em->persist($invoice);
        $em->flush();

        return $invoice;
    }

    private function onlyInvoiceOf(Customer $customer): IssuedInvoice
    {
        $em = $this->em();
        $em->clear();
        $invoices = $em->getRepository(IssuedInvoice::class)->findBy(['customer' => $customer->getId()]);
        $this->assertCount(1, $invoices);

        return $invoices[0];
    }

    /** El de ahora: cada petición del cliente de pruebas reinicia el kernel. */
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
