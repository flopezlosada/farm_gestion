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
}
