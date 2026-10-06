<?php

namespace App\Tests\Controller;

use App\Entity\AccountEntry;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\FinancialAccount;
use App\Entity\ReceivedInvoice;
use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * La bandeja de facturas por HTTP, sin lectura automática (en los tests no hay
 * clave): que el módulo está detrás de su flag, que una factura subida se guarda y
 * espera, que se puede completar a mano y queda convertida en un apunte de gasto, y
 * que el documento sólo se sirve con permiso.
 *
 * La lectura con Gemini se prueba aparte, sin red ({@see \App\Tests\Service\Accounting\Invoice\InvoiceReadQueueTest}).
 */
class ReceivedInvoiceControllerTest extends AbstractAuthenticatedTest
{
    private ?FinancialAccount $account = null;
    private ?BudgetCategoryGroup $group = null;
    private ?BudgetCategory $category = null;

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
        $this->assertSame('51454945-n', $invoice->getProviderTaxId(), 'El CIF tecleado se guarda en la factura.');

        // Ya anotada, no vuelve a abrirse para crear un segundo apunte.
        $client->request('GET', sprintf('/gestion/accounting/invoices/%d', $invoice->getId()));
        $this->assertResponseRedirects('/gestion/accounting/invoices');
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
