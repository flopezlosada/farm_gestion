<?php

namespace App\Tests\Controller;

use App\Entity\Absence;
use App\Entity\Setting;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Worker;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests funcionales del área de gestión de personal (/gestion/staff). Cubren el
 * acceso a las pantallas con FEATURE_LABORAL encendido y, sobre todo, el borrado
 * de trabajador con cascada y forzado (la lógica más arriesgada del módulo).
 *
 * Las tablas laborales arrancan vacías en db_test (ninguna fixture crea
 * trabajadores), así que el tearDown purga todo lo creado para no contaminar el
 * resto de la suite.
 */
class StaffControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_LABORAL, true);

        $admin = $this->em->getRepository(User::class)->loadUserByIdentifier('admin');
        if ($admin === null) {
            throw new \RuntimeException('Fixtures sin User admin; carga UserFixtures en db_test.');
        }
        $this->client->loginUser($admin);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(TimeEntry::class)->findAll() as $e) {
            $em->remove($e);
        }
        foreach ($em->getRepository(Absence::class)->findAll() as $a) {
            $em->remove($a);
        }
        $em->flush();
        foreach ($em->getRepository(User::class)->findAll() as $u) {
            if ($u->getWorker() !== null) {
                $u->setWorker(null);
            }
        }
        $em->flush();
        foreach ($em->getRepository(Worker::class)->findAll() as $w) {
            $em->remove($w);
        }
        foreach ($em->getRepository(Setting::class)->findAll() as $s) {
            $em->remove($s);
        }
        foreach ($em->getRepository(User::class)->findAll() as $u) {
            if (str_starts_with((string) $u->getUsername(), 'worker-test-')) {
                $em->remove($u);
            }
        }
        $em->flush();

        parent::tearDown();
    }

    private function makeWorker(string $name = 'Trabajador de prueba'): Worker
    {
        $worker = (new Worker())
            ->setName($name)
            ->setHireDate(new \DateTimeImmutable('2026-01-01'))
            ->setActive(true)
            ->setAnnualVacationDays(22);
        $this->em->persist($worker);
        $this->em->flush();

        return $worker;
    }

    private function addEntry(Worker $worker, string $type, string $at): TimeEntry
    {
        $entry = (new TimeEntry())
            ->setWorker($worker)
            ->setType($type)
            ->setOccurredAt(new \DateTimeImmutable($at, new \DateTimeZone('Europe/Madrid')))
            ->setSource(TimeEntry::SOURCE_SUPERVISOR);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    public function testIndexLoads(): void
    {
        $this->client->request('GET', '/gestion/staff');
        $this->assertResponseIsSuccessful();
    }

    public function testGapsLoads(): void
    {
        $this->makeWorker();
        $this->client->request('GET', '/gestion/staff/gaps');
        $this->assertResponseIsSuccessful();
    }

    public function testTeamCalendarLoads(): void
    {
        $this->makeWorker();
        $this->client->request('GET', '/gestion/staff/calendar');
        $this->assertResponseIsSuccessful();
    }

    public function testShowAndCalendarLoad(): void
    {
        $worker = $this->makeWorker();
        $this->client->request('GET', '/gestion/staff/' . $worker->getId());
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/gestion/staff/' . $worker->getId() . '/calendar');
        $this->assertResponseIsSuccessful();
    }

    public function testTimesheetPdfIsAPdf(): void
    {
        $worker = $this->makeWorker();
        $this->addEntry($worker, TimeEntry::TYPE_IN, '2026-01-15 09:00');
        $this->addEntry($worker, TimeEntry::TYPE_OUT, '2026-01-15 17:00');

        $this->client->request('GET', '/gestion/staff/' . $worker->getId() . '/timesheet.pdf?year=2026&month=1');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
    }

    public function testDeleteWorkerWithoutEntries(): void
    {
        $worker = $this->makeWorker();
        $id = $worker->getId();

        $crawler = $this->client->request('GET', '/gestion/staff/' . $id);
        $token = $crawler->filter('#confirm-worker-delete input[name="_token"]')->attr('value');

        $this->client->request('POST', '/gestion/staff/' . $id, ['_token' => $token]);
        $this->assertResponseRedirects('/gestion/staff');

        $this->em->clear();
        $this->assertNull($this->em->getRepository(Worker::class)->find($id), 'El trabajador sin fichajes debe borrarse.');
    }

    public function testDeleteBlockedWithEntriesWithoutForce(): void
    {
        $worker = $this->makeWorker();
        $id = $worker->getId();
        $this->addEntry($worker, TimeEntry::TYPE_IN, '2026-01-15 09:00');

        $crawler = $this->client->request('GET', '/gestion/staff/' . $id);
        // Con fichajes, la ficha pinta la caja de "forzar borrado" con el mismo token.
        $token = $crawler->filter('#confirm-worker-delete input[name="_token"]')->attr('value');

        // POST sin force: debe bloquearse y conservar el trabajador.
        $this->client->request('POST', '/gestion/staff/' . $id, ['_token' => $token]);
        $this->assertResponseRedirects('/gestion/staff/' . $id);

        $this->em->clear();
        $this->assertNotNull($this->em->getRepository(Worker::class)->find($id), 'Con fichajes y sin force, NO debe borrarse.');
    }

    public function testForceDeleteCascadesEntriesAbsencesAndOrphanUser(): void
    {
        $worker = $this->makeWorker();
        $id = $worker->getId();
        $this->addEntry($worker, TimeEntry::TYPE_IN, '2026-01-15 09:00');

        $absence = (new Absence())
            ->setWorker($worker)
            ->setType(Absence::TYPE_VACATION)
            ->setStatus(Absence::STATUS_APPROVED)
            ->setStartDate(new \DateTimeImmutable('2026-02-01'))
            ->setEndDate(new \DateTimeImmutable('2026-02-05'));
        $this->em->persist($absence);

        // Cuenta de login SOLO laboral: debe borrarse al forzar el borrado.
        $user = (new User())
            ->setUsername('worker-test-orphan')
            ->setEmail('worker-test-orphan@example.test')
            ->setPassword('x')
            ->setEnabled(true)
            ->setPasswordSet(true)
            ->setWorker($worker);
        $this->em->persist($user);
        $this->em->flush();
        $userId = $user->getId();

        $crawler = $this->client->request('GET', '/gestion/staff/' . $id);
        $token = $crawler->filter('#confirm-worker-delete input[name="_token"]')->attr('value');

        $this->client->request('POST', '/gestion/staff/' . $id, ['_token' => $token, 'force' => '1']);
        $this->assertResponseRedirects('/gestion/staff');

        $this->em->clear();
        $this->assertNull($this->em->getRepository(Worker::class)->find($id), 'El trabajador debe borrarse con force.');
        $this->assertSame([], $this->em->getRepository(TimeEntry::class)->findAll(), 'Los fichajes deben borrarse en cascada.');
        $this->assertSame([], $this->em->getRepository(Absence::class)->findAll(), 'Las ausencias deben borrarse en cascada.');
        $this->assertNull($this->em->getRepository(User::class)->find($userId), 'La cuenta solo-laboral huérfana debe borrarse.');
    }
}
