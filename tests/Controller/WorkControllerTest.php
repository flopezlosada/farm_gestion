<?php

namespace App\Tests\Controller;

use App\Entity\Absence;
use App\Entity\Notification;
use App\Entity\Setting;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Worker;
use App\Service\AppSettings;
use App\Tests\Entity\StampsTimeEntryRecordedAt;
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
    use StampsTimeEntryRecordedAt;

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
        foreach ($em->getRepository(Notification::class)->findBy(['kind' => [Notification::KIND_STAFF_ABSENCE_REQUESTED, Notification::KIND_WORK_ABSENCE_DECIDED]]) as $n) {
            $em->remove($n);
        }
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

    /**
     * La campanita está en «Mi jornada»: los avisos del trabajador llegan a la
     * bandeja de su cuenta y sin ella no habría dónde verlos.
     */
    public function testWorkPanelShowsTheBell(): void
    {
        $this->client->request('GET', '/work');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('a.csa-topbar__bell[href="/avisos"]');
    }

    /**
     * Un trabajador sin ficha de socix ve la bandeja dentro de «Mi jornada», no
     * en el shell de gestión, cuyo lateral sólo tiene secciones que no puede abrir.
     */
    public function testInboxOfAWorkerUsesTheWorkShell(): void
    {
        $this->client->request('GET', '/avisos');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.csa-sidebar__brand-sub', 'Mi jornada');
        $this->assertSelectorTextContains('.csa-page-header', 'Mi jornada · Avisos');
        $this->assertSelectorExists('.csa-topbar__crumbs a[href="/work"]');
    }

    /**
     * Con el módulo laboral apagado /work da 403: la bandeja no puede montarse
     * sobre un layout cuyos enlaces rebotan, así que cae al shell de la cuenta.
     */
    public function testInboxOfAWorkerFallsBackWhenLaboralIsOff(): void
    {
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_LABORAL, false);

        $this->client->request('GET', '/avisos');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.csa-page-header', 'Mi cuenta · Avisos');
        $this->assertSelectorNotExists('.csa-topbar__crumbs a[href="/work"]');
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

    /**
     * Con la jornada de hoy ya cerrada, el panel no invita a «empezar tu
     * jornada»: dice a qué hora salió y que otra entrada abre un tramo nuevo.
     */
    public function testAfterClockingOutThePanelSaysSoInsteadOfStartYourDay(): void
    {
        foreach ([1, 2] as $_) {
            $token = $this->csrf('/work');
            $this->client->request('POST', '/work/clock', ['_csrf_token' => $token]);
            $this->assertResponseRedirects('/work');
        }

        $crawler = $this->client->request('GET', '/work');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Has fichado la salida a las', $crawler->filter('.wk-clock__state')->text());
        $this->assertStringContainsString('Fichar otra entrada', $crawler->filter('.wk-clock button')->text());
        $this->assertStringNotContainsString('empezar tu jornada', $crawler->filter('.wk-clock')->text());
    }

    /**
     * El panel pinta los fichajes de hoy y sólo marca los registros diferidos:
     * el del botón y lo que el supervisor apunta en el momento salen limpios; lo
     * que se apuntó más tarde lleva el chip, que dice cuándo y quién.
     */
    public function testTodayListMarksOnlyDeferredEntries(): void
    {
        $token = $this->csrf('/work');
        $this->client->request('POST', '/work/clock', ['_csrf_token' => $token]);
        $crawler = $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('.wk-row'));
        $this->assertCount(0, $crawler->filter('.wk-chip-deferred'), 'Lo fichado con el botón no es diferido.');

        $madrid = new \DateTimeZone('Europe/Madrid');
        $worker = $this->em->find(Worker::class, $this->worker->getId());
        $this->em->persist((new TimeEntry())
            ->setWorker($worker)
            ->setType(TimeEntry::TYPE_OUT)
            ->setOccurredAt(new \DateTimeImmutable('now', $madrid))
            ->setSource(TimeEntry::SOURCE_SUPERVISOR));
        $this->em->persist($this->setRecordedAt((new TimeEntry())
            ->setWorker($worker)
            ->setType(TimeEntry::TYPE_IN)
            ->setOccurredAt(new \DateTimeImmutable('today 00:00', $madrid))
            ->setSource(TimeEntry::SOURCE_SUPERVISOR), new \DateTimeImmutable('today 00:30', $madrid)));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/work');
        $this->assertResponseIsSuccessful();
        $this->assertCount(3, $crawler->filter('.wk-row'));
        $this->assertCount(1, $crawler->filter('.wk-chip-deferred'), 'Sólo lo apuntado más tarde es diferido, lo apunte quien lo apunte.');
        $this->assertSame('Apuntado a las 00:30 por el supervisor', trim($crawler->filter('.wk-chip-deferred')->text()), 'El chip dice cuándo y quién lo apuntó.');
    }

    /**
     * Un día rellenado días después dice en el calendario CUÁNDO se apuntó, no
     * una etiqueta genérica: es lo que el trabajador y el supervisor entienden
     * sin leyenda.
     */
    public function testCalendarSaysWhenADayWasWrittenDownLater(): void
    {
        $madrid = new \DateTimeZone('Europe/Madrid');
        $day = new \DateTimeImmutable('2026-03-10 09:00', $madrid);
        $this->em->persist((new TimeEntry())
            ->setWorker($this->em->find(Worker::class, $this->worker->getId()))
            ->setType(TimeEntry::TYPE_IN)
            ->setOccurredAt($day));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/work/calendar', ['year' => 2026, 'month' => 3]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(
            'Apuntado el ' . (new \DateTimeImmutable('now', $madrid))->format('d/m'),
            trim($crawler->filter('.cal-badge--deferred')->text()),
        );
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

    /**
     * Pedir vacaciones deja el aviso en la bandeja de quien las aprueba (el
     * admin de las fixtures lo es por jerarquía) y no en la del propio trabajador.
     */
    public function testVacationRequestReachesStaffInboxButNotTheWorker(): void
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
        $notices = $this->em->getRepository(Notification::class)->findBy(['kind' => Notification::KIND_STAFF_ABSENCE_REQUESTED]);
        $recipients = array_map(static fn (Notification $n): string => (string) $n->getRecipient()->getUsername(), $notices);

        $this->assertContains('admin', $recipients, 'Quien aprueba vacaciones tiene que enterarse de que se han pedido.');
        $this->assertNotContains('worker-test-panel', $recipients, 'El trabajador no se avisa a sí mismo.');
        $this->assertSame('Trabajadora de prueba pide vacaciones', $notices[0]->getTitle());
        $this->assertSame('Del 05/10/2026 al 09/10/2026. Falta aprobarlas o rechazarlas en su ficha.', $notices[0]->getBody());
    }
}
