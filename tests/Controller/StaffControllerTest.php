<?php

namespace App\Tests\Controller;

use App\Entity\Absence;
use App\Entity\Notification;
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
        foreach ($em->getRepository(Notification::class)->findBy(['kind' => Notification::KIND_WORK_ABSENCE_DECIDED]) as $n) {
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

    public function testGrantAccessSendsGoogleInvitationNotAnExpiringLink(): void
    {
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::EMAIL_ENABLED, true);
        $worker = $this->makeWorker();
        $id = $worker->getId();

        $crawler = $this->client->request('GET', '/gestion/staff/' . $id);
        $token = $crawler->filter('form[action$="/access/grant"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/gestion/staff/' . $id . '/access/grant', [
            '_token' => $token,
            'email' => 'Worker-Test-Grant@Example.test',
        ]);
        $this->assertResponseRedirects('/gestion/staff/' . $id);

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->loadUserByIdentifier('worker-test-grant@example.test');
        $this->assertNotNull($user, 'Dar acceso crea la cuenta con el email normalizado.');
        $this->assertSame($id, $user->getWorker()?->getId());

        // La invitación lleva a entrar con Google y, como plan B, a pedir un
        // enlace; no un magic-link, que caducaría antes de que lo abra.
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertEmailAddressContains($email, 'To', 'worker-test-grant@example.test');
        $this->assertEmailHtmlBodyContains($email, '/connect/google');
        $this->assertEmailHtmlBodyContains($email, '/login/forgot');
        $this->assertEmailHtmlBodyNotContains($email, '/login/magic/check');
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

    public function testForceDeleteIsAdminOnly(): void
    {
        $worker = $this->makeWorker();
        $id = $worker->getId();
        $this->addEntry($worker, TimeEntry::TYPE_IN, '2026-01-15 09:00');

        // Gestiona personal con permiso de escritura, pero no es admin.
        $editor = (new User())
            ->setUsername('worker-test-editor')
            ->setEmail('worker-test-editor@example.test')
            ->setPassword('x')
            ->setEnabled(true)
            ->setPasswordSet(true)
            ->setRoles(['ROLE_GESTION_LABORAL_EDIT']);
        $this->em->persist($editor);
        $this->em->flush();
        $this->client->loginUser($editor);
        // El fichaje se creó por el EM compartido: la colección inversa del
        // trabajador en memoria no lo ve. Sin limpiar, la ficha creería que no
        // tiene fichajes y pintaría el borrado normal, no el forzado.
        $this->em->clear();

        // Ni siquiera se le pinta el botón…
        $crawler = $this->client->request('GET', '/gestion/staff/' . $id);
        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('#confirm-worker-delete'));

        // …y el POST fabricado se rechaza sin tocar nada.
        $this->client->request('POST', '/gestion/staff/' . $id, ['_token' => 'x', 'force' => '1']);
        $this->assertResponseStatusCodeSame(403);

        $this->em->clear();
        $this->assertNotNull($this->em->getRepository(Worker::class)->find($id));
        $this->assertCount(1, $this->em->getRepository(TimeEntry::class)->findAll());
    }

    public function testDayShowsHowEachEntryCameInAndWhatWasVoided(): void
    {
        $worker = $this->makeWorker();
        // addEntry() los crea con origen supervisor: cuentan como apuntados a mano.
        $this->addEntry($worker, TimeEntry::TYPE_IN, '2026-03-02 09:00');
        $wrong = $this->addEntry($worker, TimeEntry::TYPE_OUT, '2026-03-02 13:00');
        $admin = $this->em->getRepository(User::class)->loadUserByIdentifier('admin');
        $wrong->void($admin, 'Se fichó la salida dos veces', new \DateTimeImmutable('2026-03-03 08:00', new \DateTimeZone('Europe/Madrid')));
        $this->em->flush();

        $crawler = $this->client->request('GET', sprintf('/gestion/staff/%d/day/2026-03-02', $worker->getId()));
        $this->assertResponseIsSuccessful();

        $trail = $crawler->filter('.stf-trail')->each(static fn ($n) => trim(preg_replace('/\s+/', ' ', $n->text())));
        $this->assertCount(2, $trail, 'Una línea por el vigente y otra por el anulado.');
        $this->assertStringContainsString('Entrada 09:00', $trail[0]);
        $this->assertStringContainsString('apuntado el', $trail[0], 'La traza dice cuándo se apuntó lo que no se fichó en el momento.');
        $this->assertStringNotContainsString('fichado en el momento', $trail[0]);
        $this->assertStringContainsString('Salida 13:00', $trail[1]);
        $this->assertStringContainsString('anulado', $trail[1]);
        $this->assertStringContainsString('Se fichó la salida dos veces', $trail[1]);
    }

    /**
     * El calendario y el día de un trabajador cuelgan de «Trabajadores»: si
     * ningún enlace del grupo queda activo, el menú del admin (que arranca
     * plegado) esconde «Personal» justo al entrar.
     */
    public function testWorkerCalendarAndDayKeepPersonalMenuActive(): void
    {
        $worker = $this->makeWorker();

        foreach (['/gestion/staff/%d/calendar', '/gestion/staff/%d/day/2026-03-02'] as $url) {
            $crawler = $this->client->request('GET', sprintf($url, $worker->getId()));
            $this->assertResponseIsSuccessful();
            $this->assertSame('Trabajadores', trim($crawler->filter('.csa-sidebar__link--active')->text()), $url);
        }
    }

    /**
     * Rechazar unas vacaciones se lo dice al trabajador en su bandeja: antes la
     * pantalla le prometía «te avisaremos» y no le avisaba nadie.
     */
    public function testRejectingAVacationTellsTheWorker(): void
    {
        $worker = $this->makeWorker();
        $user = (new User())
            ->setUsername('worker-test-decided')
            ->setEmail('worker-test-decided@example.test')
            ->setPassword('x')
            ->setEnabled(true)
            ->setPasswordSet(true)
            ->setWorker($worker);
        $absence = (new Absence())
            ->setWorker($worker)
            ->setType(Absence::TYPE_VACATION)
            ->setStartDate(new \DateTimeImmutable('2026-12-21'))
            ->setEndDate(new \DateTimeImmutable('2026-12-23'))
            ->setStatus(Absence::STATUS_REQUESTED);
        $this->em->persist($user);
        $this->em->persist($absence);
        $this->em->flush();
        $id = $worker->getId();

        $crawler = $this->client->request('GET', '/gestion/staff/' . $id);
        $token = $crawler->filter('form[action$="/absence/' . $absence->getId() . '/reject"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/gestion/staff/' . $id . '/absence/' . $absence->getId() . '/reject', ['_token' => $token]);
        $this->assertResponseRedirects('/gestion/staff/' . $id);

        $this->em->clear();
        $notices = $this->em->getRepository(Notification::class)->findBy(['kind' => Notification::KIND_WORK_ABSENCE_DECIDED]);
        $this->assertCount(1, $notices);
        $this->assertSame('worker-test-decided', $notices[0]->getRecipient()->getUsername());
        $this->assertSame('Solicitud rechazada: vacaciones', $notices[0]->getTitle());
        $this->assertStringStartsWith('Del 21/12/2026 al 23/12/2026.', (string) $notices[0]->getBody());
    }
}
