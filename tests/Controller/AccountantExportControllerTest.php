<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
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
    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
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
    }

    private function enableModule(): void
    {
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
    }
}
