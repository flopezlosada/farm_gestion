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
 * Tests funcionales del panel propio del trabajador (/work): que las pantallas
 * cargan, que el fichaje crea el evento, que el PDF sale, y que el permiso
 * retribuido se registra aprobado directo mientras las vacaciones siguen
 * pendientes de aprobación (decisión legal, art. 37.3 vs 38 ET).
 */
class WorkControllerTest extends WebTestCase
{
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
            ->setName('Trabajadora de prueba')
            ->setHireDate(new \DateTimeImmutable('2026-01-01'))
            ->setActive(true)
            ->setAnnualVacationDays(22);
        $this->em->persist($this->worker);

        $user = (new User())
            ->setUsername('worker-test-panel')
            ->setEmail('worker-test-panel@example.test')
            ->setPassword('x')
            ->setEnabled(true)
            ->setPasswordSet(true)
            ->setWorker($this->worker);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
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
            if (str_starts_with((string) $u->getUsername(), 'worker-test-')) {
                $u->setWorker(null);
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

    private function csrf(string $url): string
    {
        $crawler = $this->client->request('GET', $url);
        $this->assertResponseIsSuccessful();

        return $crawler->filter('input[name="_csrf_token"]')->first()->attr('value');
    }

    public function testPanelPagesLoad(): void
    {
        foreach (['/work', '/work/calendar', '/work/vacations'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful();
        }
    }

    public function testTimesheetPdfIsAPdf(): void
    {
        $this->client->request('GET', '/work/timesheet.pdf');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
    }

    public function testClockCreatesEntry(): void
    {
        $token = $this->csrf('/work');
        $this->client->request('POST', '/work/clock', ['_csrf_token' => $token]);
        $this->assertResponseRedirects('/work');

        $this->em->clear();
        $entries = $this->em->getRepository(TimeEntry::class)->findAll();
        $this->assertCount(1, $entries, 'Fichar debe crear un evento.');
        $this->assertSame(TimeEntry::TYPE_IN, $entries[0]->getType(), 'El primer fichaje del día es una entrada.');
    }

    public function testPermitIsRegisteredApprovedWithoutApproval(): void
    {
        $token = $this->csrf('/work/vacations');
        $this->client->request('POST', '/work/vacations/request', [
            '_csrf_token' => $token,
            'type' => Absence::TYPE_PERMIT,
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-10',
            'note' => 'Cita médica',
        ]);
        $this->assertResponseRedirects('/work/vacations');

        $this->em->clear();
        $absences = $this->em->getRepository(Absence::class)->findBy(['type' => Absence::TYPE_PERMIT]);
        $this->assertCount(1, $absences);
        $this->assertSame(Absence::STATUS_APPROVED, $absences[0]->getStatus(), 'El permiso (art. 37.3 ET) no requiere aprobación.');
    }

    public function testVacationStaysRequested(): void
    {
        $token = $this->csrf('/work/vacations');
        $this->client->request('POST', '/work/vacations/request', [
            '_csrf_token' => $token,
            'type' => Absence::TYPE_VACATION,
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-09',
        ]);
        $this->assertResponseRedirects('/work/vacations');

        $this->em->clear();
        $absences = $this->em->getRepository(Absence::class)->findBy(['type' => Absence::TYPE_VACATION]);
        $this->assertCount(1, $absences);
        $this->assertSame(Absence::STATUS_REQUESTED, $absences[0]->getStatus(), 'Las vacaciones (art. 38 ET) sí pasan por aprobación.');
    }
}
