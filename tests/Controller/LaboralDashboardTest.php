<?php

namespace App\Tests\Controller;

use App\Entity\Absence;
use App\Entity\Setting;
use App\Entity\User;
use App\Entity\Worker;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * La portada de quien lleva el personal: lo que espera su decisión.
 *
 * Lo delicado son las bajas y permisos que registra el propio trabajador: no se
 * aprueban, así que no están en ninguna cola, y la portada es el único sitio
 * donde se ven sin abrir la ficha. Las que registra el supervisor no se le
 * enseñan como nuevas: las acaba de poner él.
 */
class LaboralDashboardTest extends WebTestCase
{
    private const PREFIX = 'laboral-dash-test-';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Worker $worker;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_LABORAL, true);

        $this->worker = (new Worker())
            ->setName('Trabajadora Portada')
            ->setHireDate(new \DateTimeImmutable('2026-01-01'))
            ->setActive(true)
            ->setAnnualVacationDays(22);
        $this->em->persist($this->worker);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(Absence::class)->findAll() as $a) {
            $em->remove($a);
        }
        foreach ($em->getRepository(User::class)->findAll() as $u) {
            if (str_starts_with((string) $u->getUsername(), self::PREFIX)) {
                $em->remove($u);
            }
        }
        $em->flush();
        foreach ($em->getRepository(Worker::class)->findAll() as $w) {
            $em->remove($w);
        }
        foreach ($em->getRepository(Setting::class)->findAll() as $s) {
            $em->remove($s);
        }
        $em->flush();

        parent::tearDown();
    }

    /**
     * Las vacaciones por decidir, la baja que registró el trabajador esta
     * semana y el mes anterior sin aprobar. No salen la baja vieja ni la que
     * puso el supervisor.
     */
    public function testElResponsableVeLoQueEsperaSuDecision(): void
    {
        $manager = $this->user(['ROLE_GESTION_LABORAL_EDIT']);
        $this->absence(Absence::TYPE_VACATION, Absence::STATUS_REQUESTED, '2026-12-01', '2026-12-05');
        $this->absence(Absence::TYPE_SICK_LEAVE, Absence::STATUS_APPROVED, '2026-10-19', '2026-10-20');
        $old = $this->absence(Absence::TYPE_PERMIT, Absence::STATUS_APPROVED, '2026-08-03', '2026-08-03');
        $this->absence(Absence::TYPE_STRIKE, Absence::STATUS_APPROVED, '2026-10-15', '2026-10-15', $manager);
        $this->em->createQuery('UPDATE App\Entity\Absence a SET a.created = :old WHERE a.id = :id')
            ->setParameter('old', new \DateTime('-30 days'))
            ->setParameter('id', $old->getId())
            ->execute();

        $section = $this->personalSection($manager);

        $this->assertStringContainsString('Trabajadora Portada pide vacaciones', $section->text());
        $this->assertStringContainsString('01/12/2026 – 05/12/2026', $section->text());
        $this->assertStringContainsString('Trabajadora Portada · Baja médica', $section->text());
        $this->assertStringNotContainsString('Permiso', $section->text(), 'Una baja registrada hace un mes ya no es nueva.');
        $this->assertStringNotContainsString('Huelga', $section->text(), 'La que registró el supervisor no es nueva para él.');
        $this->assertStringContainsString('Registro de', $section->text(), 'El mes anterior sigue sin aprobar.');
    }

    /** Sin nada pendiente lo dice, en vez de enseñar una tarjeta vacía. */
    public function testSinNadaPendienteLoDice(): void
    {
        $this->em->remove($this->worker);
        $this->em->flush();

        $section = $this->personalSection($this->user(['ROLE_GESTION_LABORAL']));

        $this->assertStringContainsString('Nada pendiente', $section->text());
    }

    /** El admin ve su portada de gobierno, no la del personal. */
    public function testElAdminNoVeLaSeccion(): void
    {
        $this->client->loginUser($this->user(['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/gestion/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('.dash-section__title:contains("Personal")'));
    }

    /** Con el módulo apagado no se habla de él. */
    public function testConElModuloApagadoNoSale(): void
    {
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_LABORAL, false);

        $this->client->loginUser($this->user(['ROLE_GESTION_LABORAL_EDIT']));
        $crawler = $this->client->request('GET', '/gestion/dashboard');

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('.dash-section__title:contains("Personal")'));
    }

    /**
     * Entra con esa cuenta y devuelve la sección «Personal» de la portada.
     *
     * @param User $user La cuenta.
     */
    private function personalSection(User $user): Crawler
    {
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/gestion/dashboard');
        $this->assertResponseIsSuccessful();

        $section = $crawler->filter('.dash-section')->reduce(
            static fn (Crawler $node): bool => str_starts_with(trim($node->filter('.dash-section__title')->text()), 'Personal'),
        );
        $this->assertCount(1, $section, 'Quien lleva el personal tiene su sección en la portada.');

        return $section;
    }

    /**
     * Una cuenta de gestión con esos roles.
     *
     * @param string[] $roles
     */
    private function user(array $roles): User
    {
        $name = self::PREFIX . uniqid();
        $user = (new User())
            ->setUsername($name)
            ->setEmail($name . '@example.test')
            ->setPassword('x')
            ->setEnabled(true)
            ->setPasswordSet(true)
            ->setRoles($roles);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * Una ausencia de la trabajadora. Sin `$approvedBy` es como la registra
     * ella misma desde «Mi jornada».
     */
    private function absence(string $type, string $status, string $from, string $to, ?User $approvedBy = null): Absence
    {
        $absence = (new Absence())
            ->setWorker($this->worker)
            ->setType($type)
            ->setStatus($status)
            ->setStartDate(new \DateTimeImmutable($from))
            ->setEndDate(new \DateTimeImmutable($to))
            ->setApprovedBy($approvedBy);
        $this->em->persist($absence);
        $this->em->flush();

        return $absence;
    }
}
