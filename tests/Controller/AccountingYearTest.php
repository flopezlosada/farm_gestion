<?php

namespace App\Tests\Controller;

use App\Entity\Budget;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\BudgetLine;
use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El resumen del año enseña lo que se ha movido, mes a mes. Las sumas son las de
 * BudgetTracker, que tiene sus propios tests.
 */
class AccountingYearTest extends AbstractAuthenticatedTest
{
    private ?Budget $budget = null;
    private ?BudgetCategoryGroup $group = null;
    private ?BudgetCategory $category = null;

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(Setting::class)->findBy(['name' => AppSettings::FEATURE_CONTABILIDAD]) as $setting) {
            $em->remove($setting);
        }
        foreach ([$this->budget, $this->category, $this->group] as $entity) {
            if ($entity !== null) {
                $em->remove($em->find($entity::class, $entity->getId()));
                $em->flush();
            }
        }
        $em->flush();

        parent::tearDown();
    }

    /**
     * Una partida que sólo está en el presupuesto no se ha movido: en la rejilla
     * saldría como una fila de «·» con total 0, así que no sale.
     */
    public function testUnaPartidaSoloPresupuestadaNoSaleEnLaRejilla(): void
    {
        $client = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $suffix = bin2hex(random_bytes(3));
        $this->group = (new BudgetCategoryGroup())->setName('Finca '.$suffix)->setKind(BudgetCategoryGroup::KIND_EXPENSE);
        $this->category = (new BudgetCategory())->setName('Alquiler de la finca '.$suffix)->setGroup($this->group);
        $this->budget = (new Budget())->setYear(2099)->setName('Presupuesto 2099')->setApproved(true);
        $this->budget->addLine((new BudgetLine())->setCategory($this->category)->setMonth(1)->setAmount('-1200.00'));
        $em->persist($this->group);
        $em->persist($this->category);
        $em->persist($this->budget);
        $em->flush();

        $crawler = $client->request('GET', '/gestion/accounting/year/2099');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.csa-topbar__crumbs', 'Informes');
        $this->assertSelectorTextContains('body', 'No hay ningún apunte de 2099');
        $this->assertStringNotContainsString('Alquiler de la finca '.$suffix, $crawler->filter('body')->text());
    }
}
