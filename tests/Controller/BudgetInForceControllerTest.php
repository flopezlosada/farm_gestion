<?php

namespace App\Tests\Controller;

use App\Entity\Budget;
use App\Entity\Setting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * El botón de la lista de presupuestos que pone uno en vigor o lo saca, y que el que
 * está en vigor no se pueda borrar.
 *
 * Autocontenido: año 2096.
 */
class BudgetInForceControllerTest extends AbstractAuthenticatedTest
{
    private const YEAR = 2096;

    private KernelBrowser $browser;
    private EntityManagerInterface $em;
    private Budget $approved;
    private Budget $revised;

    protected function setUp(): void
    {
        parent::setUp();
        $this->browser = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(3));
        $this->approved = (new Budget())->setYear(self::YEAR)->setName('Aprobado '.$suffix)
            ->setApproved(true)->setApprovedAt(new \DateTimeImmutable('2096-01-20'));
        $this->revised = (new Budget())->setYear(self::YEAR)->setName('Revisado '.$suffix);
        $this->em->persist($this->approved);
        $this->em->persist($this->revised);
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach ([$this->revised, $this->approved] as $budget) {
            $found = $this->em->find(Budget::class, $budget->getId());
            if ($found !== null) {
                $this->em->remove($found);
            }
        }
        foreach ($this->em->getRepository(Setting::class)->findBy(['name' => AppSettings::FEATURE_CONTABILIDAD]) as $setting) {
            $this->em->remove($setting);
        }
        $this->em->flush();

        parent::tearDown();
    }

    /**
     * El primer clic lo pone en vigor y la lista lo marca; el segundo lo saca.
     */
    public function testPonerYSacarDeVigor(): void
    {
        $this->toggle($this->revised);
        $this->assertTrue($this->reload($this->revised)->isInForce());
        $crawler = $this->browser->request('GET', '/gestion/accounting/budgets/');
        $row = $crawler->filter(sprintf('form[action$="/%d/in-force"]', $this->revised->getId()))->closest('tr');
        $this->assertStringContainsString('en vigor', $row->text());
        $this->assertStringContainsString('Sacar de vigor', $row->text());

        $this->toggle($this->revised);
        $this->assertFalse($this->reload($this->revised)->isInForce());
    }

    /**
     * Sin ninguno marcado manda el aprobado: la lista lo dice con la etiqueta y no le
     * ofrece «Poner en vigor», que no cambiaría nada. Al otro del año sí se le ofrece.
     */
    public function testSinMarcaElAprobadoSeVeEnVigor(): void
    {
        $crawler = $this->browser->request('GET', '/gestion/accounting/budgets/');
        $approvedRow = $crawler->filter(sprintf('form[action$="/%d/in-force"]', $this->approved->getId()))->closest('tr');
        $revisedRow = $crawler->filter(sprintf('form[action$="/%d/in-force"]', $this->revised->getId()))->closest('tr');

        $this->assertCount(1, $approvedRow->filter('.prl-chip--ok'));
        $this->assertStringNotContainsString('Poner en vigor', $approvedRow->text());
        $this->assertCount(0, $revisedRow->filter('.prl-chip--ok'));
        $this->assertStringContainsString('Poner en vigor', $revisedRow->text());
    }

    /**
     * El que está en vigor no se borra aunque no esté aprobado: cambiaría en silencio
     * contra qué se mide el año.
     */
    public function testElEnVigorNoSeBorra(): void
    {
        // El token de borrar se coge mientras aún hay papelera: después ya no se pinta.
        $crawler = $this->browser->request('GET', '/gestion/accounting/budgets/');
        $token = $crawler->filter(sprintf('form[action$="/%d/delete"] input[name="_token"]', $this->revised->getId()))->attr('value');
        $this->toggle($this->revised);

        $crawler = $this->browser->request('GET', '/gestion/accounting/budgets/');
        $this->assertCount(0, $crawler->filter(sprintf('form[action$="/%d/delete"]', $this->revised->getId())), 'sin papelera');

        $this->browser->request('POST', sprintf('/gestion/accounting/budgets/%d/delete', $this->revised->getId()), [
            '_token' => $token,
        ]);
        $this->assertResponseRedirects('/gestion/accounting/budgets/');
        $this->assertNotNull($this->reload($this->revised), 'no se ha borrado');
    }

    private function toggle(Budget $budget): void
    {
        $crawler = $this->browser->request('GET', '/gestion/accounting/budgets/');
        $form = $crawler->filter(sprintf('form[action$="/%d/in-force"]', $budget->getId()));
        $this->browser->request('POST', $form->attr('action'), ['_token' => $form->filter('input[name="_token"]')->attr('value')]);
        $this->assertResponseRedirects('/gestion/accounting/budgets/');
    }

    private function reload(Budget $budget): ?Budget
    {
        $this->em->clear();

        return $this->em->find(Budget::class, $budget->getId());
    }
}
