<?php

namespace App\Tests\Controller;

use App\Entity\Customer;
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
    private function draft(): IssuedInvoice
    {
        $em = $this->em();
        $customer = (new Customer())->setName('Cooperativa de pruebas')->setTaxId(self::TAX_ID);
        $invoice = (new IssuedInvoice())
            ->setCustomer($customer)
            ->setIssueDate(new \DateTimeImmutable('2091-03-05'))
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
