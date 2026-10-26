<?php

namespace App\Tests\Controller;

use App\Entity\Budget;
use App\Entity\BudgetCategory;
use App\Entity\BudgetCategoryGroup;
use App\Entity\BudgetLine;
use App\Entity\PaySchedule;
use App\Entity\Setting;
use App\Entity\Worker;
use App\Entity\WorkerSalary;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * La calculadora de escenarios de un presupuesto: responde con lo que dice el enlace y
 * los sueldos sólo existen para quien administra.
 *
 * Trabaja sobre un año lejano, sin nada anotado: todos los meses son previsión y la
 * tesorería arranca de la del presupuesto.
 */
class BudgetScenarioControllerTest extends AbstractAuthenticatedTest
{
    private const YEAR = 2099;

    private KernelBrowser $browser;
    private EntityManagerInterface $em;
    private Budget $budget;
    private BudgetCategory $tractor;
    private Worker $worker;
    /** @var list<object> Lo creado por el test, en el orden en que se creó. */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->browser = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(3));
        $group = (new BudgetCategoryGroup())->setName('INVERSIÓN PRUEBA '.$suffix)->setKind(BudgetCategoryGroup::KIND_INVESTMENT)->setSortOrder(99);
        $this->persist($group);
        $this->tractor = (new BudgetCategory())->setGroup($group)->setName('Maquinaria '.$suffix)->setSortOrder(1);
        $this->persist($this->tractor);

        $this->budget = (new Budget())->setYear(self::YEAR)->setName('Escenario '.$suffix)->setOpeningCash('1000.00');
        $this->budget->addLine(new BudgetLine($this->budget, $this->tractor, 2, '-250.00'));
        $this->persist($this->budget);

        $this->worker = (new Worker())->setName('Trabajador escenario '.$suffix)
            ->setHireDate(new \DateTimeImmutable('2090-01-01'))
            ->setWorkdayType(Worker::WORKDAY_FULL_TIME);
        $this->persist($this->worker);
        $this->persist((new WorkerSalary())
            ->setWorker($this->worker)
            ->setAnnualGross('21000.00')
            ->setPayments(PaySchedule::Fourteen)
            ->setEmployerSsRate('31.40')
            ->setValidFrom(new \DateTimeImmutable('2090-01-01')));
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach (array_reverse($this->created) as $row) {
            $found = $this->em->find($row::class, $row->getId());
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
     * Sin palancas, el escenario es el presupuesto: 1.000 de partida menos 250 en
     * febrero. Un tractor de 3.000 en marzo, tecleado en positivo, sale.
     */
    public function testOneOffMovesTheScenarioEnd(): void
    {
        $crawler = $this->browser->request('GET', $this->url());
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('750,00 €', $crawler->filter('.acc-headline')->text());

        $crawler = $this->browser->request('GET', $this->url([
            'oneoff' => [['category' => (string) $this->tractor->getId(), 'month' => '3', 'amount' => '3000']],
        ]));

        $this->assertResponseIsSuccessful();
        $headline = $crawler->filter('.acc-headline')->text();
        $this->assertStringContainsString('-2.250,00 €', $headline);
        $this->assertStringContainsString('Mar', $headline);
    }

    /**
     * Admin ve las fichas y, con la casilla marcada, el año le cuesta los sueldos:
     * 21.000 € de bruto más 6.594 € de Seguridad Social (31,40 % de 21.000).
     */
    public function testAdminRecalculatesWithTheWorkerFiles(): void
    {
        $crawler = $this->browser->request('GET', $this->url(['recalc' => '1']));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="recalc"][checked]');
        $this->assertStringContainsString($this->worker->getName(), $crawler->filter('.acc-lever-table')->last()->text());
        $this->assertStringContainsString('-26.844,00 €', $crawler->filter('.acc-headline')->text());
    }

    /**
     * Quien lleva la contabilidad sin ser admin no ve sueldos: ni la palanca ni las
     * fichas, y un enlace que la traiga marcada no cambia la cuenta.
     */
    public function testAccountantWithoutAdminNeverSeesSalaries(): void
    {
        $user = $this->createUserWithRoles(['ROLE_GESTION_CONTABILIDAD']);
        $this->created[] = $user;
        $this->browser->loginUser($user);

        $crawler = $this->browser->request('GET', $this->url(['recalc' => '1']));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('input[name="recalc"]');
        $this->assertStringNotContainsString($this->worker->getName(), $crawler->text());
        $this->assertStringContainsString('750,00 €', $crawler->filter('.acc-headline')->text());
    }

    /**
     * La rejilla del presupuesto lleva a su calculadora.
     */
    public function testBudgetGridLinksToTheCalculator(): void
    {
        $this->browser->request('GET', sprintf('/gestion/accounting/budgets/%d/lines', $this->budget->getId()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf('a[href="%s"]', $this->url()));
    }

    /**
     * @param array<string, mixed> $query
     */
    private function url(array $query = []): string
    {
        $url = sprintf('/gestion/accounting/budgets/%d/scenario', $this->budget->getId());

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    private function persist(object $entity): void
    {
        $this->em->persist($entity);
        $this->em->flush();
        $this->created[] = $entity;
    }
}
