<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La portada de contabilidad («Pendiente») y lo que salió de ella: «Cómo vamos» en
 * Informes, el coste de la cesta en Presupuestos y las dos páginas índice del menú.
 * Lo que dice la lista de tareas lo cubre AccountingAgendaTest; aquí, que cada
 * pantalla responde y enseña lo suyo con lo que haya en db_test.
 */
class AccountingControllerTest extends AbstractAuthenticatedTest
{
    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(Setting::class)->findBy(['name' => AppSettings::FEATURE_CONTABILIDAD]) as $setting) {
            $em->remove($setting);
        }
        $em->flush();

        parent::tearDown();
    }

    public function testLaPortadaEsLoPendiente(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        $client->request('GET', '/gestion/accounting/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Pendiente');
        $this->assertSelectorTextContains('body', 'hay hoy en las cuentas');
        $this->assertSelectorTextContains('body', 'Dónde está el dinero');
    }

    public function testComoVamosEstaEnInformes(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        $client->request('GET', '/gestion/accounting/report?year=2099');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Cómo vamos');
        $this->assertSelectorTextContains('body', 'No hay ningún presupuesto cargado para 2099');
    }

    public function testElCosteDeLaCestaEstaEnPresupuestos(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        $client->request('GET', '/gestion/accounting/budgets/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Cuánto tiene que costar la cesta');
    }

    /** Las dos páginas índice del menú enlazan a cada pantalla de su sección. */
    public function testInformesYAjustesEnlazanSusPantallas(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        $crawler = $client->request('GET', '/gestion/accounting/reports');
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('.acc-hub a[href="/gestion/accounting/report"]'));
        $this->assertCount(1, $crawler->filter('.acc-hub a[href="/gestion/accounting/invoices/accountant"]'));

        $crawler = $client->request('GET', '/gestion/accounting/settings');
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('.acc-hub a[href="/gestion/accounting/setup/accounts"]'));
        $this->assertCount(1, $crawler->filter('.acc-hub a[href="/gestion/accounting/customers"]'));
    }

    private function enableModule(): void
    {
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
    }
}
