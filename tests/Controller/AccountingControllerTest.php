<?php

namespace App\Tests\Controller;

use App\Entity\Budget;
use App\Entity\FinancialAccount;
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
    /** Cuenta creada por el test, para quitarla al acabar. */
    private ?FinancialAccount $account = null;

    /** Presupuesto creado por el test, para quitarlo al acabar. */
    private ?Budget $budget = null;

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(Setting::class)->findBy(['name' => AppSettings::FEATURE_CONTABILIDAD]) as $setting) {
            $em->remove($setting);
        }
        if ($this->account !== null) {
            $em->remove($em->find(FinancialAccount::class, $this->account->getId()));
        }
        if ($this->budget !== null) {
            $em->remove($em->find(Budget::class, $this->budget->getId()));
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
        $this->assertSelectorTextContains('h1', 'Las cuentas de');
        $this->assertSelectorTextContains('body', 'en las cuentas, según el libro');
        $this->assertSelectorTextContains('body', 'Dónde está el dinero');
    }

    /**
     * La rejilla del presupuesto acaba en la tesorería a fin de cada mes, que el
     * navegador calcula desde la de empezar: es la cifra con que se lee un escenario.
     */
    public function testElPresupuestoDiceLaTesoreriaAFinDeMes(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->budget = (new Budget())->setYear(2099)->setName('Escenario de prueba')->setOpeningCash('1500.50');
        $em->persist($this->budget);
        $em->flush();

        $crawler = $client->request('GET', sprintf('/gestion/accounting/budgets/%d/lines', $this->budget->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSame('1500.50', $crawler->filter('#budget-grid')->attr('data-opening'));
        $this->assertSelectorTextContains('.acc-cash-row', 'Tesorería a fin de mes');
        $this->assertCount(12, $crawler->filter('.acc-cash-row .acc-cash-month'));
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

    /**
     * La caja mes a mes nombra cada mes y da euros, como la ficha de una cuenta: «1, 2,
     * 3…» y «0,4k» no se leen. Un año futuro es todo previsión, desde enero.
     */
    public function testLaCajaMesAMesNombraLosMesesYDaEuros(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        $crawler = $client->request('GET', '/gestion/accounting/report?year=2099');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Desde enero es previsión');
        $months = $crawler->filter('.acc-months .m');
        $this->assertCount(12, $months);
        $this->assertStringContainsString('Ene', $months->eq(0)->text());
        $this->assertStringContainsString('Dic', $months->eq(11)->text());
        $this->assertStringEndsWith(' €', trim($months->eq(11)->filter('.v')->text()));
        $this->assertSame('Diciembre', explode(':', (string) $months->eq(11)->attr('title'))[0]);
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
        $this->assertCount(1, $crawler->filter(sprintf('.acc-hub a[href="/gestion/accounting/year/%d"]', (int) date('Y'))));
        $this->assertCount(0, $crawler->filter('.acc-hub a[href="/gestion/accounting/report"]'), 'Cómo vamos va en el menú, no en Informes.');
        $this->assertCount(1, $crawler->filter(sprintf('.acc-hub a[href="/gestion/accounting/year/%d/assembly"]', (int) date('Y'))));
        $this->assertCount(1, $crawler->filter('.acc-hub a[href="/gestion/accounting/invoices/accountant"]'));

        $crawler = $client->request('GET', '/gestion/accounting/settings');
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('.acc-hub a[href="/gestion/accounting/setup/accounts"]'));
        $this->assertCount(1, $crawler->filter('.acc-hub a[href="/gestion/accounting/customers"]'));
    }

    /**
     * Cómo vamos y Presupuestos se miran a menudo: van en el menú de Contabilidad, no
     * escondidos dentro de Informes. «Cómo vamos» deja arriba sólo con qué se compara.
     */
    public function testComoVamosYPresupuestosEstanEnElMenu(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        $crawler = $client->request('GET', '/gestion/accounting/report');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('.csa-navgroup a[href="/gestion/accounting/report"]'));
        $this->assertCount(1, $crawler->filter('.csa-navgroup a[href="/gestion/accounting/budgets/"]'));
        $this->assertSelectorTextContains('.acc-against', 'Comparado con');
        $this->assertCount(0, $crawler->filter('.prl a[href="/gestion/accounting/entry/new"]'), 'Anotar se hace desde Inicio y Movimientos.');
    }

    /**
     * Movimientos filtrados por una cuenta responde con su saldo. El de cada fila lo
     * cubre AccountEntryRunningBalanceTest.
     */
    public function testMovimientosDeUnaCuentaEnsenanSuSaldo(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->account = (new FinancialAccount())
            ->setName('Banco prueba '.bin2hex(random_bytes(3)))
            ->setOpeningBalance('0')
            ->setOpeningDate(new \DateTimeImmutable('2099-01-01'));
        $em->persist($this->account);
        $em->flush();

        $client->request('GET', '/gestion/accounting/ledger?account='.$this->account->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Movimientos');
        $this->assertSelectorTextContains('body', 'saldo de '.$this->account->getName());
    }

    /**
     * Filtrar con «Todas las cuentas» y «Todas las partidas» manda los parámetros
     * vacíos (`account=&category=`): es el formulario tal cual, no un caso raro.
     * Daba 400 porque `getInt()` no acepta la cadena vacía.
     */
    public function testMovimientosConLosFiltrosEnTodasNoDaError(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        $client->request('GET', '/gestion/accounting/ledger?account=&category=&from=&to=&q=');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Movimientos');
    }

    /**
     * Contabilidad es el trabajo diario del admin: sale justo debajo de «Día a día» y
     * es el único grupo que el admin recibe desplegado; el resto llega plegado.
     */
    public function testElAdminVeContabilidadArribaYDesplegada(): void
    {
        $client = $this->createAuthenticatedClient();
        $this->enableModule();

        $crawler = $client->request('GET', '/gestion/dashboard');

        $this->assertResponseIsSuccessful();
        $heads = $crawler->filter('.csa-navgroup__head')->each(static fn ($n) => trim($n->text()));
        $this->assertSame(['Día a día', 'Contabilidad', 'Administración'], array_slice($heads, 0, 3));
        $open = $crawler->filter('.csa-navgroup:not(.is-collapsed) .csa-navgroup__head')->each(static fn ($n) => trim($n->text()));
        $this->assertSame(['Contabilidad'], $open);
    }

    private function enableModule(): void
    {
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
    }
}
