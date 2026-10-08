<?php

namespace App\Tests\Controller;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Entity\Provider;
use App\Entity\ReceivedInvoice;
use App\Entity\Setting;
use App\Service\Accounting\Invoice\ExtractedInvoice;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * La bandeja de facturas por HTTP, sin lectura automática (en los tests no hay
 * clave): que el módulo está detrás de su flag, que una factura subida se guarda y
 * espera, que se puede completar a mano y queda convertida en un apunte de gasto, y
 * que el documento sólo se sirve con permiso. Al confirmar, el proveedor se
 * reconoce por su CIF (o se da de alta) y el desglose de IVA queda en sus líneas.
 *
 * La lectura con Gemini se prueba aparte, sin red ({@see \App\Tests\Service\Accounting\Invoice\InvoiceReadQueueTest}).
 */
class ReceivedInvoiceControllerTest extends AbstractAuthenticatedTest
{
    /** CIF de la ferretería de las pruebas; tearDown borra el proveedor que cree. */
    private const TAX_ID = '51454945N';

    private ?FinancialAccount $account = null;
    private ?BudgetCategoryGroup $group = null;
    private ?BudgetCategory $category = null;

    /** @var list<int> Apuntes creados a mano que no cuelgan de ninguna factura. */
    private array $extraEntries = [];

    /** @var list<int> Proveedores creados a mano, también los que no llevan CIF. */
    private array $providersToRemove = [];

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        foreach ($em->getRepository(ReceivedInvoice::class)->findBy(['originalName' => ['factura-prueba.pdf', 'apuntes.txt']]) as $invoice) {
            $entry = $invoice->getAccountEntry();
            $em->remove($invoice);
            if ($entry !== null) {
                $em->remove($entry);
            }
        }
        $em->flush();

        foreach ($this->extraEntries as $id) {
            if (($entry = $em->find(AccountEntry::class, $id)) !== null) {
                $em->remove($entry);
            }
        }
        $em->flush();

        foreach ($em->getRepository(Provider::class)->findBy(['taxId' => self::TAX_ID]) as $provider) {
            $em->remove($provider);
        }
        foreach ($this->providersToRemove as $id) {
            if (($provider = $em->find(Provider::class, $id)) !== null) {
                $em->remove($provider);
            }
        }
        $em->flush();

        foreach ([$this->category ? [BudgetCategory::class, $this->category->getId()] : null,
                  $this->group ? [BudgetCategoryGroup::class, $this->group->getId()] : null,
                  $this->account ? [FinancialAccount::class, $this->account->getId()] : null] as $ref) {
            if ($ref !== null && ($row = $em->find($ref[0], $ref[1])) !== null) {
                $em->remove($row);
                $em->flush();
            }
        }

        foreach ($em->getRepository(Setting::class)->findAll() as $setting) {
            $em->remove($setting);
        }
        $em->flush();

        parent::tearDown();
    }

    public function testConElFlagApagadoLaBandejaNoExiste(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/gestion/accounting/invoices');

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    /**
     * El ciclo entero sin lectura: se sube, espera en cola, se completa a mano y
     * acaba siendo un apunte de gasto (importe NEGATIVO) con el CIF en la factura,
     * que es lo que verá la gestoría.
     */
    public function testSubirCompletarAManoYConfirmarCreaElApunteDeGasto(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $this->createCatalogue();

        $crawler = $client->request('GET', '/gestion/accounting/invoices');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="received_invoice_upload"]')->form();
        $form['received_invoice_upload[files]'][0]->upload($this->samplePdf());
        $client->submit($form);
        $this->assertResponseRedirects('/gestion/accounting/invoices');

        $invoice = $this->findInvoice('factura-prueba.pdf');
        $this->assertNotNull($invoice, 'La subida no guardó la factura.');
        $this->assertSame(ReceivedInvoice::STATUS_PENDING, $invoice->getStatus(), 'Sin clave, la factura tiene que esperar en cola.');
        $this->assertSame(0, $invoice->getAttempts(), 'Sin clave no se debe gastar ningún intento.');

        $crawler = $client->request('GET', sprintf('/gestion/accounting/invoices/%d', $invoice->getId()));
        $this->assertResponseIsSuccessful();

        $confirm = $crawler->filter('form[name="received_invoice_confirm"]')->form([
            'received_invoice_confirm[entry][date]' => '2026-03-05',
            'received_invoice_confirm[entry][account]' => (string) $this->account->getId(),
            'received_invoice_confirm[entry][category]' => (string) $this->category->getId(),
            'received_invoice_confirm[entry][concept]' => 'Ferretería Torrelaguna · tornillería',
            'received_invoice_confirm[entry][direction]' => 'out',
            'received_invoice_confirm[entry][magnitude]' => '26.75',
            'received_invoice_confirm[entry][providerName]' => 'Ferretería Torrelaguna',
            'received_invoice_confirm[entry][invoiceNumber]' => '2232',
            'received_invoice_confirm[providerTaxId]' => ' 51454945-n ',
        ]);
        $client->submit($confirm);
        $this->assertResponseRedirects();

        $invoice = $this->findInvoice('factura-prueba.pdf');
        $this->assertSame(ReceivedInvoice::STATUS_CONFIRMED, $invoice->getStatus());
        $entry = $invoice->getAccountEntry();
        $this->assertInstanceOf(AccountEntry::class, $entry);
        $this->assertSame('-26.75', $entry->getAmount(), 'Una factura recibida es dinero que sale.');
        $this->assertSame('2232', $invoice->getInvoiceNumber());
        $this->assertSame(self::TAX_ID, $invoice->getProviderTaxId(), 'El CIF tecleado se guarda normalizado: así se compara.');
        $this->assertNotNull($invoice->getProvider(), 'Con CIF, la factura queda enlazada a su proveedor.');
        $this->assertSame('Ferretería Torrelaguna', $invoice->getProvider()->getName(), 'Un proveedor nuevo nace con el nombre del apunte.');
        $this->assertSame(self::TAX_ID, $invoice->getProvider()->getTaxId());

        // Ya anotada se enseña tal como quedó, sin formulario con que crear otro apunte.
        $crawler = $client->request('GET', sprintf('/gestion/accounting/invoices/%d', $invoice->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $crawler->filter('form[name="received_invoice_confirm"]')->count(), 'Una factura anotada no se vuelve a confirmar.');
        $this->assertSelectorTextContains('body', 'Anotada en el libro');

        // Y un envío del formulario a destiempo no crea nada.
        $client->request('POST', sprintf('/gestion/accounting/invoices/%d', $invoice->getId()), ['received_invoice_confirm' => []]);
        $this->assertResponseRedirects('/gestion/accounting/invoices');
        $this->assertSame($entry->getId(), $this->findInvoice('factura-prueba.pdf')->getAccountEntry()?->getId());
    }

    /**
     * Una factura de profesional: dos tipos de IVA y retención. Las líneas y la
     * retención se guardan, y el proveedor nuevo toma la dirección de la factura.
     */
    public function testConfirmarGuardaElDesgloseLaRetencionYLaDireccionDelProveedor(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $this->createCatalogue();
        $invoice = $this->uploadOne($client);

        $this->confirm($client, $invoice, [
            'providerAddress' => 'C/ Mayor 12',
            'providerPostalCode' => '28180',
            'providerTown' => 'Torrelaguna',
            'providerProvince' => 'Madrid',
            'taxLines' => [
                ['base' => '300.00', 'rate' => '21', 'taxAmount' => '63.00'],
                ['base' => '100.00', 'rate' => '10', 'taxAmount' => '10.00'],
            ],
            'withholding' => '45.00',
            'withholdingRate' => '15',
        ], '428.00');
        $this->assertResponseRedirects();

        $invoice = $this->findInvoice('factura-prueba.pdf');
        $this->assertSame(ReceivedInvoice::STATUS_CONFIRMED, $invoice->getStatus());
        $this->assertCount(2, $invoice->getTaxLines());
        $this->assertSame('63.00', $invoice->getTaxLines()->first()->getTaxAmount());
        $this->assertSame('45.00', $invoice->getWithholding());
        $this->assertSame(428.0, $invoice->totalFromBreakdown(), '300 + 63 + 100 + 10 − 45 de retención.');
        $this->assertSame('C/ Mayor 12', $invoice->getProvider()?->getAddress());
        $this->assertSame('28180', $invoice->getProvider()?->getPostalCode());
        $this->assertSame('Madrid', $invoice->getProvider()?->getProvince());
    }

    /**
     * Un proveedor que ya existe se elige en la lista y su ficha sólo se completa:
     * lo que tenga no lo pisa una factura (que puede traer la dirección de una
     * sucursal), y el apunte toma el nombre de la ficha, no el tecleado.
     */
    public function testUnProveedorElegidoSoloSeCompletaNuncaSePisa(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $this->createCatalogue();
        $known = $this->createProvider('Ferretería de siempre', self::TAX_ID, 'Plaza Mayor 1');

        $invoice = $this->uploadOne($client);
        $this->confirm($client, $invoice, [
            'provider' => (string) $known->getId(),
            'providerAddress' => 'Polígono Sur, nave 3',
            'providerTown' => 'Torrelaguna',
        ], '26.75');
        $this->assertResponseRedirects();

        $providers = $this->findProviders(self::TAX_ID);
        $this->assertCount(1, $providers, 'El mismo CIF no da de alta un segundo proveedor.');
        $this->assertSame('Ferretería de siempre', $providers[0]->getName(), 'El nombre de la ficha no se pisa.');
        $this->assertSame('Plaza Mayor 1', $providers[0]->getAddress(), 'La dirección de la ficha no se pisa.');
        $this->assertSame('Torrelaguna', $providers[0]->getTown(), 'Lo que faltaba sí se completa.');
        $invoice = $this->findInvoice('factura-prueba.pdf');
        $this->assertSame($providers[0]->getId(), $invoice->getProvider()?->getId());
        $this->assertSame('Ferretería de siempre', $invoice->getAccountEntry()?->getProviderName(), 'El apunte lleva el nombre de la ficha elegida.');
    }

    /**
     * Si el CIF leído es de un proveedor conocido, la revisión llega con él ya
     * elegido: lo normal es que baste con guardar.
     */
    public function testElProveedorDelCifLeidoLlegaElegido(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $this->createCatalogue();
        $known = $this->createProvider('Ferretería de siempre', self::TAX_ID, null);

        $invoice = $this->uploadOne($client);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $invoice = $em->find(ReceivedInvoice::class, $invoice->getId());
        $invoice->setProviderTaxId('ES' . self::TAX_ID);
        $em->flush();

        $crawler = $client->request('GET', sprintf('/gestion/accounting/invoices/%d', $invoice->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSame(
            (string) $known->getId(),
            $crawler->filter('select[name="received_invoice_confirm[provider]"] option[selected]')->attr('value'),
            'Leído con el prefijo ES del NIF-IVA, tiene que reconocerse igual.',
        );
    }

    /**
     * «Nuevo proveedor» con un CIF que ya es de otro no se guarda: ni duplica el
     * proveedor ni se enlaza a escondidas al que existe. Se pide elegirlo.
     */
    public function testUnNuevoConElCifDeOtroNoSeConfirma(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $this->createCatalogue();
        $this->createProvider('Ferretería de siempre', self::TAX_ID, null);

        $invoice = $this->uploadOne($client);
        $this->confirm($client, $invoice, ['provider' => ''], '26.75');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.csa-field__error', 'Ferretería de siempre');
        $this->assertSame(ReceivedInvoice::STATUS_PENDING, $this->findInvoice('factura-prueba.pdf')->getStatus());
        $this->assertCount(1, $this->findProviders(self::TAX_ID));
    }

    /**
     * Un proveedor antiguo sin CIF (los del comercio de la granja) recibe el de la
     * factura al elegirlo: así la siguiente ya se le reconoce sola.
     */
    public function testElegirUnProveedorSinCifLePoneElDeLaFactura(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $this->createCatalogue();
        $old = $this->createProvider('Ferretería antigua', null, null);

        $invoice = $this->uploadOne($client);
        $this->confirm($client, $invoice, ['provider' => (string) $old->getId()], '26.75');
        $this->assertResponseRedirects();

        $providers = $this->findProviders(self::TAX_ID);
        $this->assertCount(1, $providers);
        $this->assertSame($old->getId(), $providers[0]->getId());
    }

    /**
     * Si el pago ya está en el libro (lo trajo el extracto), la revisión lo propone ya
     * elegido y confirmar engancha la factura a ese apunte: no se crea otro y el gasto
     * no cuenta dos veces. El apunte del banco gana el proveedor y el nº de factura.
     */
    public function testUnPagoQueYaEstaEnElLibroSeEngancha(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $invoice = $this->uploadOne($client);
        // Después de subir: la subida limpia el EntityManager y dejaría la cuenta y
        // la partida desconectadas para el apunte del banco.
        $this->createCatalogue();
        $invoice = $em->find(ReceivedInvoice::class, $invoice->getId());
        $invoice->markRead(ExtractedInvoice::fromArray([
            'fecha' => '2091-03-05', 'total' => 987.65, 'proveedor' => 'Ferretería Torrelaguna', 'numero_factura' => '2232',
        ]), 'test', $this->category, new \DateTimeImmutable());
        // Lo que trajo el banco cuatro días después: mismo importe, sin proveedor ni nº.
        $bank = (new AccountEntry())->setAccount($this->account)->setCategory($this->category)
            ->setDate(new \DateTimeImmutable('2091-03-09'))->setConcept('trf. ferreteria torrelaguna')->setAmount('-987.65');
        $em->persist($bank);
        $em->flush();
        $before = $em->getRepository(AccountEntry::class)->count([]);

        $crawler = $client->request('GET', sprintf('/gestion/accounting/invoices/%d', $invoice->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSame((string) $bank->getId(), $crawler->filter('input[name="received_invoice_confirm[match]"][checked]')->attr('value'), 'El pago del banco llega elegido.');

        $client->submit($crawler->filter('form[name="received_invoice_confirm"]')->form());
        $this->assertResponseRedirects();

        $invoice = $this->findInvoice('factura-prueba.pdf');
        $this->assertSame(ReceivedInvoice::STATUS_CONFIRMED, $invoice->getStatus());
        $this->assertSame($bank->getId(), $invoice->getAccountEntry()?->getId(), 'Enganchada al apunte del banco.');
        $this->assertSame($before, $em->getRepository(AccountEntry::class)->count([]), 'No se crea un segundo apunte.');
        $this->assertSame('-987.65', $invoice->getAccountEntry()->getAmount());
        $this->assertSame('trf. ferreteria torrelaguna', $invoice->getAccountEntry()->getConcept(), 'El concepto es del banco.');
        $this->assertSame('2232', $invoice->getAccountEntry()->getInvoiceNumber(), 'Lo que le faltaba lo completa la factura.');
    }

    /** Si quien revisa dice que es otro pago, se anota uno nuevo como siempre. */
    public function testOtroPagoCreaSuApunte(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $invoice = $this->uploadOne($client);
        // Después de subir: la subida limpia el EntityManager y dejaría la cuenta y
        // la partida desconectadas para el apunte del banco.
        $this->createCatalogue();
        $invoice = $em->find(ReceivedInvoice::class, $invoice->getId());
        $invoice->markRead(ExtractedInvoice::fromArray(['fecha' => '2091-03-05', 'total' => 987.65]), 'test', $this->category, new \DateTimeImmutable());
        $bank = (new AccountEntry())->setAccount($this->account)->setCategory($this->category)
            ->setDate(new \DateTimeImmutable('2091-03-09'))->setConcept('otro pago igual')->setAmount('-987.65');
        $em->persist($bank);
        $em->flush();
        $this->extraEntries[] = $bank->getId();

        $crawler = $client->request('GET', sprintf('/gestion/accounting/invoices/%d', $invoice->getId()));
        $form = $crawler->filter('form[name="received_invoice_confirm"]')->form();
        $form['received_invoice_confirm[match]']->select('');
        $form['received_invoice_confirm[entry][account]']->select((string) $this->account->getId());
        $client->submit($form);
        $this->assertResponseRedirects();

        $invoice = $this->findInvoice('factura-prueba.pdf');
        $this->assertNotNull($invoice->getAccountEntry());
        $this->assertNotSame($bank->getId(), $invoice->getAccountEntry()->getId(), 'Un apunte nuevo, no el del banco.');
    }

    /** Una línea de IVA a medias no deja confirmar: la gestoría la necesita entera. */
    public function testUnaLineaDeIvaIncompletaNoSeConfirma(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $this->createCatalogue();
        $invoice = $this->uploadOne($client);

        $this->confirm($client, $invoice, ['taxLines' => [['base' => '22.11', 'rate' => '', 'taxAmount' => '4.64']]], '26.75');

        $this->assertResponseIsSuccessful();
        $this->assertSame(ReceivedInvoice::STATUS_PENDING, $this->findInvoice('factura-prueba.pdf')->getStatus());
    }

    /**
     * El apunte de una factura enseña el documento en su ficha y lleva el clip en el
     * libro. Si se borra, la factura no se pierde: vuelve a la bandeja.
     */
    public function testElApunteEnseñaSuFacturaYAlBorrarloVuelveALaBandeja(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $this->createCatalogue();
        $invoice = $this->uploadOne($client);
        $this->confirm($client, $invoice, [], '26.75');

        $entry = $this->findInvoice('factura-prueba.pdf')->getAccountEntry();
        $crawler = $client->request('GET', sprintf('/gestion/accounting/entry/%d', $entry->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter(sprintf('iframe[src$="/invoices/%d/file"]', $invoice->getId())), 'La ficha del apunte enseña la factura.');

        $crawler = $client->request('GET', '/gestion/accounting/ledger?account=' . $this->account->getId());
        $this->assertCount(1, $crawler->filter('.fa-paperclip'), 'En el libro, el apunte con factura lleva el clip.');

        $crawler = $client->request('GET', sprintf('/gestion/accounting/entry/%d', $entry->getId()));
        $client->submit($crawler->filter(sprintf('form[action$="/entry/%d/delete"]', $entry->getId()))->form());

        $invoice = $this->findInvoice('factura-prueba.pdf');
        $this->assertNull($invoice->getAccountEntry());
        $this->assertTrue($invoice->isOpen(), 'Sin apunte, la factura vuelve a la bandeja en vez de quedarse confirmada y escondida.');
    }

    /** Descartar aparta la factura sin anotar nada, y sin borrar el documento. */
    public function testDescartarApartaLaFacturaSinCrearApunte(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $invoice = $this->uploadOne($client);

        $crawler = $client->request('GET', sprintf('/gestion/accounting/invoices/%d', $invoice->getId()));
        $client->submit($crawler->filter(sprintf('form[action$="/invoices/%d/discard"]', $invoice->getId()))->form());

        $invoice = $this->findInvoice('factura-prueba.pdf');
        $this->assertSame(ReceivedInvoice::STATUS_DISCARDED, $invoice->getStatus());
        $this->assertNull($invoice->getAccountEntry());

        $client->request('GET', sprintf('/gestion/accounting/invoices/%d/file', $invoice->getId()));
        $this->assertResponseIsSuccessful();

        // Descartada no desaparece: sale entre las últimas resueltas y tiene su ficha.
        $crawler = $client->request('GET', '/gestion/accounting/invoices');
        $this->assertSame(1, $crawler->filter(sprintf('a[href$="/invoices/%d"]', $invoice->getId()))->count());
        $client->request('GET', sprintf('/gestion/accounting/invoices/%d', $invoice->getId()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Descartada');
    }

    /**
     * El documento se enseña dentro de la página de revisión: tiene que permitir el
     * marco del mismo sitio (con el DENY general, el navegador lo dejaría en blanco).
     */
    public function testElDocumentoSeSirveParaEnseñarloEnLaPropiaPagina(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $invoice = $this->uploadOne($client);

        $client->request('GET', sprintf('/gestion/accounting/invoices/%d/file', $invoice->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSame('SAMEORIGIN', $client->getResponse()->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('inline', (string) $client->getResponse()->headers->get('Content-Disposition'));
    }

    /** Lo que no es una factura (un .txt) no se guarda. */
    public function testUnFormatoNoAdmitidoSeRechaza(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        $path = sys_get_temp_dir() . '/apuntes.txt';
        file_put_contents($path, "esto no es una factura\n");

        $crawler = $client->request('GET', '/gestion/accounting/invoices');
        $form = $crawler->filter('form[name="received_invoice_upload"]')->form();
        $form['received_invoice_upload[files]'][0]->upload($path);
        $client->submit($form);

        $this->assertResponseRedirects('/gestion/accounting/invoices');
        $this->assertNull($this->findInvoice('apuntes.txt'));
    }

    /** Quien sólo puede ver la contabilidad no puede subir facturas. */
    public function testSinPermisoDeEscrituraNoSePuedeSubir(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_GESTION_CONTABILIDAD']));
        $this->enableModule();

        $client->request('POST', '/gestion/accounting/invoices/upload');

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    private function uploadOne($client): ReceivedInvoice
    {
        $crawler = $client->request('GET', '/gestion/accounting/invoices');
        $form = $crawler->filter('form[name="received_invoice_upload"]')->form();
        $form['received_invoice_upload[files]'][0]->upload($this->samplePdf());
        $client->submit($form);

        return $this->findInvoice('factura-prueba.pdf');
    }

    /**
     * Confirma una factura enviando el formulario de revisión con un apunte de gasto
     * válido y los campos de factura que se pidan. Va por petición directa y no por
     * el formulario del crawler porque las líneas de IVA nuevas las añade el
     * JavaScript y el crawler sólo conoce los campos pintados.
     *
     * @param array<string, mixed> $invoiceFields Campos de la factura (taxLines, withholding…).
     * @param string               $amount        Importe pagado.
     */
    private function confirm($client, ReceivedInvoice $invoice, array $invoiceFields, string $amount): void
    {
        $crawler = $client->request('GET', sprintf('/gestion/accounting/invoices/%d', $invoice->getId()));
        $form = $crawler->filter('form[name="received_invoice_confirm"]')->form();
        $values = $form->getPhpValues();

        $values['received_invoice_confirm'] = array_replace($values['received_invoice_confirm'], [
            'providerTaxId' => self::TAX_ID,
        ], $invoiceFields);
        $values['received_invoice_confirm']['entry'] = array_replace($values['received_invoice_confirm']['entry'], [
            'date' => '2026-03-05',
            'account' => (string) $this->account->getId(),
            'category' => (string) $this->category->getId(),
            'concept' => 'Ferretería Torrelaguna · tornillería',
            'direction' => 'out',
            'magnitude' => $amount,
            'providerName' => 'Ferretería Torrelaguna',
            'invoiceNumber' => '2232',
        ]);

        $client->request('POST', $form->getUri(), $values);
    }

    private function createProvider(string $name, ?string $taxId, ?string $address): Provider
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $provider = (new Provider())->setName($name)->setTaxId($taxId)->setAddress($address);
        $em->persist($provider);
        $em->flush();
        $this->providersToRemove[] = $provider->getId();

        return $provider;
    }

    /**
     * @return list<Provider>
     */
    private function findProviders(string $taxId): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(Provider::class)->findBy(['taxId' => $taxId]);
    }

    private function createCatalogue(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(3));

        $this->account = (new FinancialAccount())
            ->setName('Banco de prueba ' . $suffix)
            ->setKind(FinancialAccount::KIND_BANK)
            ->setOpeningBalance('0.00')
            ->setOpeningDate(new \DateTimeImmutable('2026-01-01'))
            ->setActive(true)
            ->setSortOrder(99);
        $this->group = (new BudgetCategoryGroup())->setName('GASTOS PRUEBA ' . $suffix)->setKind(BudgetCategoryGroup::KIND_EXPENSE)->setSortOrder(99);
        $this->category = (new BudgetCategory())->setGroup($this->group)->setName('Ferretería')->setSortOrder(1);

        $em->persist($this->account);
        $em->persist($this->group);
        $em->persist($this->category);
        $em->flush();
    }

    private function findInvoice(string $originalName): ?ReceivedInvoice
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(ReceivedInvoice::class)->findOneBy(['originalName' => $originalName], ['id' => 'DESC']);
    }

    /** Un PDF mínimo pero real: el formato se comprueba por el contenido. */
    private function samplePdf(): string
    {
        $path = sys_get_temp_dir() . '/factura-prueba.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        return $path;
    }

    private function enableModule(): void
    {
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
    }
}
