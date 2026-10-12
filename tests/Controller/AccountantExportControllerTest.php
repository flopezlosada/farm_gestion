<?php

namespace App\Tests\Controller;

use App\Entity\Customer;
use App\Entity\IssuedInvoice;
use App\Entity\IssuedInvoiceLine;
use App\Entity\Setting;
use App\Service\Accounting\IssuedInvoice\InvoiceIssuer;
use App\Service\Accounting\IssuedInvoice\IssuedInvoiceArchive;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La entrega trimestral a la gestoría: la pantalla que enseña qué va a ir y la
 * descarga del ZIP. El contenido del ZIP (nombres y libro) lo cubre
 * AccountantPackageTest; aquí, que las dos rutas respondan y el ZIP sea un ZIP.
 *
 * Usa un trimestre de 2099, sin facturas, para no depender de lo que haya en db_test.
 */
class AccountantExportControllerTest extends AbstractAuthenticatedTest
{
    private const TAX_ID = 'Q2899002H';

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $archive = static::getContainer()->get(IssuedInvoiceArchive::class);
        foreach ($em->getRepository(Customer::class)->findBy(['taxId' => self::TAX_ID]) as $customer) {
            foreach ($em->getRepository(IssuedInvoice::class)->findBy(['customer' => $customer]) as $invoice) {
                if (($path = $archive->pathTo($invoice)) !== null) {
                    unlink($path);
                }
                $em->remove($invoice);
            }
            $em->flush();
            $em->remove($customer);
        }
        $em->flush();
        foreach ($em->getRepository(Setting::class)->findAll() as $setting) {
            $em->remove($setting);
        }
        $em->flush();

        parent::tearDown();
    }

    public function testLaPantallaEnseñaElTrimestreElegido(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        $client->request('GET', '/gestion/accounting/invoices/accountant?year=2099&quarter=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '2T2099');
        $this->assertSelectorTextContains('body', 'No hay facturas anotadas con fecha de este trimestre');
    }

    /** Aun vacío, el ZIP sale y lleva el libro: la gestoría ve que ese trimestre no hubo nada. */
    public function testLaDescargaEsUnZip(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        $client->request('GET', '/gestion/accounting/invoices/accountant/download?year=2099&quarter=2');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/zip');
        $this->assertStringContainsString('2T2099.zip', (string) $client->getResponse()->headers->get('Content-Disposition'));
        $zip = $client->getInternalResponse()->getContent();
        $this->assertStringStartsWith('PK', $zip, 'Un ZIP empieza siempre por «PK».');
        $this->assertStringContainsString('Libro facturas recibidas 2T2099.xlsx', $zip);
        $this->assertStringContainsString('Libro de caja 2T2099.xlsx', $zip, 'Con los movimientos de cada cuenta, tengan factura o no.');
    }

    /** Las emitidas del trimestre van en el ZIP: su libro y su PDF, con el nombre de siempre. */
    public function testLasEmitidasVanEnElZip(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $this->issueOne('2099-05-20');

        $client->request('GET', '/gestion/accounting/invoices/accountant?year=2099&quarter=2');
        $this->assertSelectorTextContains('body', 'FRA 1-2099 Fundación de pruebas 20.05.2099.pdf');

        $client->request('GET', '/gestion/accounting/invoices/accountant/download?year=2099&quarter=2');
        $zip = $client->getInternalResponse()->getContent();
        $this->assertStringContainsString('Libro facturas emitidas 2T2099.xlsx', $zip);
        $this->assertStringContainsString('Facturas emitidas 2T2099/FRA 1-2099 Fundación de pruebas 20.05.2099.pdf', $zip);
    }

    /** Una emitida con la fecha en otro trimestre no entra. */
    public function testUnaEmitidaDeOtroTrimestreNoEntra(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $this->issueOne('2099-07-01');

        $client->request('GET', '/gestion/accounting/invoices/accountant/download?year=2099&quarter=2');

        $this->assertStringNotContainsString('Libro facturas emitidas', $client->getInternalResponse()->getContent());
    }

    /** Emite una factura de 100 € al 21 % con esa fecha, con su PDF guardado. */
    private function issueOne(string $date): void
    {
        $settings = static::getContainer()->get(AppSettings::class);
        $settings->setString(AppSettings::ISSUED_INVOICE_ISSUER, "Asociación de pruebas\nCIF: G00000000");
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $customer = (new Customer())->setName("Fundación de pruebas\n(segunda línea)")->setTaxId(self::TAX_ID);
        $invoice = (new IssuedInvoice())
            ->setCustomer($customer)
            ->setIssueDate(new \DateTimeImmutable($date))
            ->addLine(new IssuedInvoiceLine('Colaboración', '100.00', '21'));
        $em->persist($customer);
        $em->persist($invoice);
        $em->flush();

        $this->assertNull(static::getContainer()->get(InvoiceIssuer::class)->issue($invoice, null));
    }

    private function enableModule(): void
    {
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
    }
}
