<?php

namespace App\Tests\Controller;

use App\Entity\PaySchedule;
use App\Entity\Setting;
use App\Entity\Worker;
use App\Entity\WorkerSalary;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Los sueldos de los trabajadores: sólo los ve quien administra, un sueldo nuevo
 * cierra el anterior y dos tramos de la misma persona no pueden pisarse.
 */
class AccountingWorkerControllerTest extends AbstractAuthenticatedTest
{
    private KernelBrowser $browser;
    private EntityManagerInterface $em;
    private Worker $worker;
    /** @var list<object> Lo creado por el test, en el orden en que se creó. */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->browser = $this->createAuthenticatedClient();
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_CONTABILIDAD, true);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->worker = (new Worker())->setName('Trabajador sueldos '.bin2hex(random_bytes(3)))
            ->setHireDate(new \DateTimeImmutable('2020-01-01'))
            ->setWorkdayType(Worker::WORKDAY_FULL_TIME);
        $this->persist($this->worker);
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
     * Quien lleva la contabilidad sin ser admin no ve sueldos, ni en la pantalla ni en
     * el enlace de Ajustes.
     */
    public function testAccountingStaffWithoutAdminCannotSeeSalaries(): void
    {
        $user = $this->createUserWithRoles(['ROLE_GESTION_CONTABILIDAD', 'ROLE_GESTION_CONTABILIDAD_EDIT']);
        $this->created[] = $user;
        $this->browser->loginUser($user);

        $salary = $this->salary('2025-01-01', null);
        $this->persist($salary);
        foreach ([
            '/gestion/accounting/workers',
            '/gestion/accounting/workers/'.$this->worker->getId().'/salary/new',
            '/gestion/accounting/workers/salary/'.$salary->getId().'/edit',
        ] as $url) {
            $this->browser->request('GET', $url);
            $this->assertResponseStatusCodeSame(403, $url);
        }

        $this->browser->request('GET', '/gestion/accounting/settings');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('a[href="/gestion/accounting/workers"]');
    }

    /**
     * El admin ve la ficha y, con un sueldo puesto, el coste del año.
     */
    public function testAdminSeesTheYearCost(): void
    {
        $this->persist($this->salary('2025-01-01', null));

        $crawler = $this->browser->request('GET', '/gestion/accounting/workers?year=2026');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString($this->worker->getName(), $crawler->filter('.acc-grid')->text());
        // 21.000 en 14 pagas + 30 % de 21.000 de Seguridad Social.
        $this->assertSame('27.300', trim($crawler->filter('.acc-grid tfoot .acc-total-col')->text()));
    }

    /**
     * Un sueldo nuevo cierra el que seguía abierto el día antes de empezar.
     */
    public function testANewSalaryClosesTheOpenOne(): void
    {
        $old = $this->salary('2025-01-01', null);
        $this->persist($old);

        $crawler = $this->browser->request('GET', '/gestion/accounting/workers/'.$this->worker->getId().'/salary/new');
        $this->assertResponseIsSuccessful();
        $form = $crawler->selectButton('Guardar')->form([
            'worker_salary[validFrom]' => '2026-07-01',
            'worker_salary[annualGross]' => '22400.00',
            'worker_salary[payments]' => '14',
            'worker_salary[employerSsRate]' => '30.00',
        ]);
        $this->browser->submit($form);

        $this->assertResponseRedirects('/gestion/accounting/workers');
        $this->em->clear();
        $salaries = $this->em->getRepository(WorkerSalary::class)->findBy(['worker' => $this->worker->getId()], ['validFrom' => 'ASC']);
        array_push($this->created, ...array_slice($salaries, 1));
        $this->assertCount(2, $salaries);
        $this->assertSame('2026-06-30', $salaries[0]->getValidTo()->format('Y-m-d'));
        $this->assertNull($salaries[1]->getValidTo());
        $this->assertSame('22400.00', $salaries[1]->getAnnualGross());
    }

    /**
     * Un tramo que pisa a otro cerrado no se guarda, y el otro se queda como estaba.
     */
    public function testAnOverlappingSalaryIsRejected(): void
    {
        $this->persist($this->salary('2025-01-01', '2025-12-31'), $this->salary('2026-01-01', '2026-06-30'));

        $crawler = $this->browser->request('GET', '/gestion/accounting/workers/'.$this->worker->getId().'/salary/new');
        $form = $crawler->selectButton('Guardar')->form([
            'worker_salary[validFrom]' => '2026-03-01',
            'worker_salary[validTo]' => '',
        ]);
        $this->browser->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Pisa el sueldo que va del 01/01/2026 al 30/06/2026');
        $this->em->clear();
        $this->assertCount(2, $this->em->getRepository(WorkerSalary::class)->findBy(['worker' => $this->worker->getId()]));
    }

    /**
     * Un sueldo con fecha de fin (una reducción temporal) no cierra el abierto: lo
     * dejaría sin sueldo al acabar. Choca, y el abierto se queda como estaba.
     */
    public function testATemporarySalaryDoesNotCloseTheOpenOne(): void
    {
        $this->persist($this->salary('2025-01-01', null));

        $crawler = $this->browser->request('GET', '/gestion/accounting/workers/'.$this->worker->getId().'/salary/new');
        $this->browser->submit($crawler->selectButton('Guardar')->form([
            'worker_salary[validFrom]' => '2026-07-01',
            'worker_salary[validTo]' => '2026-09-30',
        ]));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Pisa el sueldo que va desde el 01/01/2025');
        $this->em->clear();
        $salaries = $this->em->getRepository(WorkerSalary::class)->findBy(['worker' => $this->worker->getId()]);
        $this->assertCount(1, $salaries);
        $this->assertNull($salaries[0]->getValidTo());
    }

    /**
     * Corregir un tramo tampoco puede hacerle pisar a otro, ni dejarlo acabando antes
     * de empezar.
     */
    public function testEditingKeepsSalariesApartAndInOrder(): void
    {
        $first = $this->salary('2025-01-01', '2025-12-31');
        $this->persist($first, $this->salary('2026-01-01', null));
        $url = '/gestion/accounting/workers/salary/'.$first->getId().'/edit';

        $crawler = $this->browser->request('GET', $url);
        $this->browser->submit($crawler->selectButton('Guardar')->form(['worker_salary[validTo]' => '2026-02-28']));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Pisa el sueldo que va desde el 01/01/2026');

        $crawler = $this->browser->request('GET', $url);
        $this->browser->submit($crawler->selectButton('Guardar')->form(['worker_salary[validTo]' => '2024-12-31']));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Acaba antes de empezar.');

        $this->em->clear();
        $this->assertSame('2025-12-31', $this->em->find(WorkerSalary::class, $first->getId())->getValidTo()->format('Y-m-d'));
    }

    /**
     * Un tramo de 21.000 € en 14 pagas y 30 % de Seguridad Social.
     */
    private function salary(string $from, ?string $to): WorkerSalary
    {
        return (new WorkerSalary())->setWorker($this->worker)
            ->setValidFrom(new \DateTimeImmutable($from))
            ->setValidTo($to !== null ? new \DateTimeImmutable($to) : null)
            ->setAnnualGross('21000.00')
            ->setPayments(PaySchedule::Fourteen)
            ->setEmployerSsRate('30.00');
    }

    /**
     * Guarda y apunta para borrar al acabar.
     */
    private function persist(object ...$rows): void
    {
        foreach ($rows as $row) {
            $this->em->persist($row);
            $this->created[] = $row;
        }
        $this->em->flush();
    }
}
