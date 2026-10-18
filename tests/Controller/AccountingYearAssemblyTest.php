<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El cierre del año para la asamblea responde y dice contra qué se compara. Las
 * cifras son las de BudgetTracker, que tiene sus propios tests.
 */
class AccountingYearAssemblyTest extends AbstractAuthenticatedTest
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

    public function testUnAnoSinPresupuestoLoDice(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);

        $client->request('GET', '/gestion/accounting/year/2099/assembly');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Cuentas de 2099');
        $this->assertSelectorTextContains('body', 'No hay presupuesto aprobado de 2099');
        $this->assertSelectorTextContains('body', 'Imprimir o guardar en PDF');
    }

    /**
     * Con el año abierto el papel lo dice (el aviso se imprime) y el saldo final no
     * se llama «31 de diciembre», porque todavía no lo es.
     */
    public function testElAnoEnCursoDiceQueNoHaAcabado(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $year = (int) date('Y');

        $crawler = $client->request('GET', sprintf('/gestion/accounting/year/%d/assembly', $year));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', sprintf('%d todavía no ha acabado', $year));
        $this->assertCount(0, $crawler->filter('.acc-no-print .prl-chip'));
        $this->assertContains('Hoy', $crawler->filter('th')->each(static fn ($th): string => trim($th->text())));
    }

    public function testUnAnoCerradoAcabaEl31DeDiciembre(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);

        $crawler = $client->request('GET', '/gestion/accounting/year/2000/assembly');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('body', 'todavía no ha acabado');
        $this->assertContains('31 de diciembre', $crawler->filter('th')->each(static fn ($th): string => trim($th->text())));
    }
}
