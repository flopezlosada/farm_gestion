<?php

namespace App\Tests\Controller;

use App\Entity\Absence;
use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\PaySchedule;
use App\Entity\Setting;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Worker;
use App\Entity\WorkerSalary;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

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
        foreach ($em->getRepository(WorkerSalary::class)->findAll() as $salary) {
            $em->remove($salary);
        }
        $em->flush();
        foreach ($em->getRepository(User::class)->findAll() as $u) {
            if ($u->getWorker() !== null) {
                $u->setWorker(null);
            }
        }
        $em->flush();
        // Las fichas de cesta que crean los tests van detrás de sus trabajadores:
        // el trabajador las referencia.
        $basketCards = [];
        foreach ($em->getRepository(Worker::class)->findAll() as $w) {
            if ($w->getPartner() !== null) {
                $basketCards[] = $w->getPartner();
            }
            $em->remove($w);
        }
        $em->flush();
        foreach ($basketCards as $card) {
            $em->remove($card);
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
            ->setAnnualVacationDays(22);
        $this->em->persist($worker);
        $this->em->flush();

        return $worker;
    }

    /**
     * Entra con un usuario que sólo tiene esos roles, ya con contraseña puesta
     * (sin ella, la app le manda antes a elegirla).
     *
     * @param list<string> $roles
     */
    private function loginWithRoles(array $roles): void
    {
        $user = (new User())
            ->setUsername('worker-test-' . uniqid())
            ->setEmail(uniqid('worker-test-') . '@example.test')
            ->setPassword('x')
            ->setEnabled(true)
            ->setPasswordSet(true)
            ->setRoles($roles);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
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

    /** Los datos personales se guardan desde la ficha, normalizados, y se ven en ella. */
    public function testPersonalDataIsSavedAndShown(): void
    {
        $worker = $this->makeWorker();
        $crawler = $this->client->request('GET', '/gestion/staff/' . $worker->getId() . '/edit');
        $this->client->submit($crawler->selectButton('Guardar cambios')->form([
            'worker[workdayType]' => Worker::WORKDAY_FULL_TIME,
            'worker[nif]' => '12.345.678-z',
            'worker[phone]' => '600 111 222',
            'worker[email]' => 'alba@example.org',
            'worker[address]' => 'Calle Mayor 1, 28190 Patones',
        ]));
        $this->assertResponseRedirects('/gestion/staff/' . $worker->getId());

        $this->em->clear();
        $saved = $this->em->getRepository(Worker::class)->find($worker->getId());
        $this->assertSame('12345678Z', $saved->getNif());
        $this->assertSame('600 111 222', $saved->getPhone());
        $this->assertSame('alba@example.org', $saved->getEmail());
        $this->assertSame('Calle Mayor 1, 28190 Patones', $saved->getAddress());

        $crawler = $this->client->request('GET', '/gestion/staff/' . $worker->getId());
        $this->assertStringContainsString('12345678Z', $crawler->filter('.stf-personal')->text());
        $this->assertStringContainsString('Calle Mayor 1, 28190 Patones', $crawler->filter('.stf-personal')->text());
        $this->assertSame('alba@example.org', $crawler->filter('input[name="email"]')->attr('value'), 'El acceso se propone con su correo.');
    }

    /**
     * «Darle cesta»: el alta sale con los datos de la ficha laboral y como no
     * socia; al completar lo que falta y guardar, la ficha queda enlazada al
     * trabajador y se sigue a «Añadir cesta».
     */
    public function testGiveBasketCreatesNonMemberCardLinkedToTheWorker(): void
    {
        $email = uniqid('cesta') . '@example.test';
        $worker = $this->makeWorker('Carmen Ruiz García')
            ->setNif('12345678Z')
            ->setPhone('6' . random_int(10000000, 99999999))
            ->setEmail($email)
            ->setAddress('Calle Mayor 1, 28190 Patones');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/gestion/staff/' . $worker->getId());
        $this->assertSame(
            '/gestion/partner/new?worker=' . $worker->getId(),
            $crawler->selectLink('Darle cesta')->attr('href'),
        );

        $crawler = $this->client->request('GET', '/gestion/partner/new?worker=' . $worker->getId() . '&start=2030-01-07');
        $this->assertResponseIsSuccessful();
        $form = $crawler->selectButton('Crear socix')->form();
        $this->assertSame('Carmen', $form['partner[name]']->getValue());
        $this->assertSame('Ruiz García', $form['partner[surname]']->getValue());
        $this->assertSame('12345678Z', $form['partner[DNI]']->getValue());
        $this->assertSame($email, $form['partner[email]']->getValue());
        $this->assertSame('1', $form['partner[notMember]']->getValue(), 'Sale marcada «No es socia».');

        $form['partner[state]']->setValue(self::firstOption($form['partner[state]']));
        $form['partner[city]']->setValue(self::firstOption($form['partner[city]']));
        $form['partner[share_payment]']->setValue(self::firstOption($form['partner[share_payment]']));
        $this->client->submit($form);

        $this->em->clear();
        $card = $this->em->getRepository(Worker::class)->find($worker->getId())->getPartner();
        $this->assertNotNull($card, 'La ficha queda enlazada al trabajador.');
        $this->assertResponseRedirects('/gestion/partner/' . $card->getId() . '/add_basket?start=2030-01-07');
        $this->assertFalse($card->isMember());
        $this->assertSame('Calle Mayor 1, 28190 Patones', $card->getAddress());
        $this->assertCount(0, $card->getPartnerBasketShares(), 'La cesta se elige en el paso siguiente.');
    }

    /**
     * Alta de quien no es socia y recibe cesta: al guardar se sigue a dar de alta
     * su ficha de no socia. Lo de tras la baja no sale: aún no hay cesta.
     */
    public function testNewWorkerWhoGetsABasketGoesOnToGiveIt(): void
    {
        $name = 'Nuevo con cesta ' . uniqid();
        $form = $this->client->request('GET', '/gestion/staff/new')->selectButton('Crear trabajador')->form();
        $this->assertFalse($form->has('worker[basketAfterLeave]'));
        $form['worker[name]'] = $name;
        $form['worker[hireDate]'] = '2026-01-01';
        $form['worker[workdayType]'] = Worker::WORKDAY_FULL_TIME;
        $form['worker[alreadyMember]'] = '0';
        $form['worker[givesBasket]']->tick();
        $form['worker[basketStart]'] = '2030-01-07';
        $this->client->submit($form);

        $worker = $this->em->getRepository(Worker::class)->findOneBy(['name' => $name]);
        $this->assertNotNull($worker);
        $this->assertResponseRedirects('/gestion/partner/new?worker=' . $worker->getId() . '&start=2030-01-07');
    }

    /** «Recibe cesta» sin fecha no se guarda: es con la que se abre «Añadir cesta». */
    public function testGivesBasketNeedsAStartDate(): void
    {
        $worker = $this->makeWorker();
        $form = $this->client->request('GET', '/gestion/staff/' . $worker->getId() . '/edit')->selectButton('Guardar cambios')->form();
        $form['worker[workdayType]'] = Worker::WORKDAY_FULL_TIME;
        $form['worker[alreadyMember]'] = '0';
        $form['worker[givesBasket]']->tick();
        $this->client->submit($form);

        $this->assertResponseIsSuccessful('Se vuelve a pintar el formulario, sin redirigir.');
        $this->assertSelectorTextContains('.csa-field__error', 'desde cuándo recibe cesta');
    }

    /** «Sí» sin elegir ficha no se guarda: se pide que se elija. */
    public function testAlreadyMemberNeedsTheirCard(): void
    {
        $worker = $this->makeWorker();
        $form = $this->client->request('GET', '/gestion/staff/' . $worker->getId() . '/edit')->selectButton('Guardar cambios')->form();
        $form['worker[workdayType]'] = Worker::WORKDAY_FULL_TIME;
        $form['worker[alreadyMember]'] = '1';
        $this->client->submit($form);

        $this->assertResponseIsSuccessful('Se vuelve a pintar el formulario, sin redirigir.');
        $this->assertSelectorTextContains('.csa-field__error', 'Elige su ficha de socia');
    }

    /**
     * Socia sin cesta en curso que recibe cesta: se enlaza su ficha y se sigue a
     * «Añadir cesta». Al volver a «No», la ficha se desenlaza aunque el
     * desplegable escondido siga enviándola.
     */
    public function testMemberWithoutBasketIsLinkedAndGoesOnToAddIt(): void
    {
        $member = (new Partner())->setName('TEST')->setSurname('Socia sin cesta ' . uniqid());
        $this->em->persist($member);
        $worker = $this->makeWorker();

        $form = $this->client->request('GET', '/gestion/staff/' . $worker->getId() . '/edit')->selectButton('Guardar cambios')->form();
        $form['worker[workdayType]'] = Worker::WORKDAY_FULL_TIME;
        $form['worker[alreadyMember]'] = '1';
        $form['worker[partner]'] = (string) $member->getId();
        $form['worker[givesBasket]']->tick();
        $form['worker[basketStart]'] = '2030-01-07';
        $this->client->submit($form);
        $this->assertResponseRedirects('/gestion/partner/' . $member->getId() . '/add_basket?start=2030-01-07');

        $crawler = $this->client->request('GET', '/gestion/partner/' . $member->getId() . '/add_basket?start=2030-01-07');
        $this->assertSame('2030-01-07', $crawler->filter('#partner_basket_share_start_date')->attr('value'), '«Añadir cesta» llega con la fecha puesta.');

        $form = $this->client->request('GET', '/gestion/staff/' . $worker->getId() . '/edit')->selectButton('Guardar cambios')->form();
        $this->assertSame('1', $form['worker[alreadyMember]']->getValue());
        $this->assertTrue($form->has('worker[basketAfterLeave]'), 'Con ficha ya se puede decidir qué pasa tras la baja.');
        $form['worker[alreadyMember]'] = '0';
        $this->client->submit($form);
        $this->assertResponseRedirects('/gestion/staff/' . $worker->getId());

        $this->em->clear();
        $this->assertNull($this->em->getRepository(Worker::class)->find($worker->getId())->getPartner());
        $this->em->remove($this->em->getRepository(Partner::class)->find($member->getId()));
        $this->em->flush();
    }

    /**
     * Los datos de una socia para rellenar los del trabajador: con las claves de
     * su formulario y el nombre completo en uno. Las filas reales tienen huecos,
     * que llegan como null.
     */
    public function testMemberDataFillsTheWorkerFields(): void
    {
        $member = (new Partner())->setName('Carmen')->setSurname('Ruiz García')->setDNI('12345678Z')->setAddress(null);
        $this->em->persist($member);
        $this->em->flush();

        $this->client->request('GET', '/gestion/staff/member-data/' . $member->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSame(
            ['name' => 'Carmen Ruiz García', 'nif' => '12345678Z', 'phone' => null, 'email' => null, 'address' => null],
            json_decode($this->client->getResponse()->getContent(), true),
        );

        // La petición limpia el EntityManager: se vuelve a cargar.
        $member = $this->em->getRepository(Partner::class)->find($member->getId());
        $member->setMember(false);
        $this->em->flush();
        $this->client->request('GET', '/gestion/staff/member-data/' . $member->getId());
        $this->assertResponseStatusCodeSame(404, 'De una ficha de no socia no se dan datos.');

        $this->em->remove($this->em->getRepository(Partner::class)->find($member->getId()));
        $this->em->flush();
    }

    /** Quien no puede ver Socixs no saca los datos de una socia. */
    public function testMemberDataNeedsPartnersRole(): void
    {
        $member = (new Partner())->setName('TEST')->setSurname('Socia ' . uniqid());
        $this->em->persist($member);
        $this->loginWithRoles(['ROLE_GESTION_LABORAL_EDIT']);

        $this->client->request('GET', '/gestion/staff/member-data/' . $member->getId());
        $this->assertResponseStatusCodeSame(403);

        $this->em->remove($this->em->getRepository(Partner::class)->find($member->getId()));
        $this->em->flush();
    }

    /** Con ficha de no socia no se pregunta nada: se enseña, y guardar la conserva. */
    public function testNonMemberCardIsShownAndKept(): void
    {
        $card = (new Partner())->setName('TEST')->setSurname('No socia ' . uniqid())->setMember(false);
        $this->em->persist($card);
        $worker = $this->makeWorker()->setPartner($card);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/gestion/staff/' . $worker->getId() . '/edit');
        $form = $crawler->selectButton('Guardar cambios')->form();
        $this->assertFalse($form->has('worker[alreadyMember]'));
        $this->assertFalse($form->has('worker[partner]'));
        $this->assertTrue($form->has('worker[basketAfterLeave]'));
        $this->assertSelectorTextContains('form', 'No es socia. Su ficha de cesta');

        $form['worker[workdayType]'] = Worker::WORKDAY_FULL_TIME;
        $this->client->submit($form);
        $this->assertResponseRedirects('/gestion/staff/' . $worker->getId());
        $this->em->clear();
        $this->assertSame($card->getId(), $this->em->getRepository(Worker::class)->find($worker->getId())->getPartner()?->getId());
    }

    /** Con ficha de cesta ya no se ofrece ni se crea otra: se vuelve a su ficha laboral. */
    public function testGiveBasketTwiceDoesNotCreateASecondCard(): void
    {
        $card = (new Partner())->setName('TEST')->setSurname('Ya con cesta ' . uniqid())->setMember(false);
        $this->em->persist($card);
        $worker = $this->makeWorker()->setPartner($card);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/gestion/staff/' . $worker->getId());
        $this->assertCount(0, $crawler->selectLink('Darle cesta'));

        $this->client->request('GET', '/gestion/partner/new?worker=' . $worker->getId());
        $this->assertResponseRedirects('/gestion/staff/' . $worker->getId());
    }

    /**
     * Enlazar la ficha toca la ficha laboral: quien sólo gestiona socixs puede
     * dar altas, pero no la de un trabajador.
     */
    public function testGiveBasketNeedsLaborEditRole(): void
    {
        $worker = $this->makeWorker();
        $this->loginWithRoles(['ROLE_GESTION_SOCIXS_EDIT']);

        $this->client->request('GET', '/gestion/partner/new?worker=' . $worker->getId());
        $this->assertResponseStatusCodeSame(403);
    }

    /** @return string El primer valor no vacío de un select. */
    private static function firstOption(ChoiceFormField $field): string
    {
        return array_values(array_filter($field->availableOptionValues(), static fn (string $v): bool => $v !== ''))[0];
    }

    /** Un DNI con la letra mal no se guarda: se avisa en el formulario. */
    public function testWrongNifIsRejected(): void
    {
        $worker = $this->makeWorker();
        $crawler = $this->client->request('GET', '/gestion/staff/' . $worker->getId() . '/edit');
        $this->client->submit($crawler->selectButton('Guardar cambios')->form([
            'worker[workdayType]' => Worker::WORKDAY_FULL_TIME,
            'worker[nif]' => '12345678A',
        ]));

        $this->assertResponseIsSuccessful('Se vuelve a pintar el formulario, sin redirigir.');
        $this->assertSelectorTextContains('.csa-field__error', 'DNI/NIE no es válido');
        $this->em->clear();
        $this->assertNull($this->em->getRepository(Worker::class)->find($worker->getId())->getNif());
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

    /**
     * Los sueldos son el histórico de costes de contabilidad, que gestión laboral no
     * ve: borrar la ficha no puede llevárselos.
     */
    public function testDeleteBlockedWhenTheWorkerHasSalaries(): void
    {
        $worker = $this->makeWorker();
        $id = $worker->getId();
        $this->addSalary($worker);

        $crawler = $this->client->request('GET', '/gestion/staff/' . $id);
        $token = $crawler->filter('#confirm-worker-delete input[name="_token"]')->attr('value');

        $this->client->request('POST', '/gestion/staff/' . $id, ['_token' => $token]);
        $this->assertResponseRedirects('/gestion/staff/' . $id);

        $this->em->clear();
        $this->assertNotNull($this->em->getRepository(Worker::class)->find($id));
        $this->assertCount(1, $this->em->getRepository(WorkerSalary::class)->findBy(['worker' => $id]));
    }

    /**
     * El borrado forzado de admin (limpieza de pruebas) sí se lleva los sueldos.
     */
    public function testAdminForceDeleteRemovesSalaries(): void
    {
        $worker = $this->makeWorker();
        $id = $worker->getId();
        $this->addSalary($worker);

        $crawler = $this->client->request('GET', '/gestion/staff/' . $id);
        $token = $crawler->filter('#confirm-worker-delete input[name="_token"]')->attr('value');

        $this->client->request('POST', '/gestion/staff/' . $id, ['_token' => $token, 'force' => '1']);
        $this->assertResponseRedirects('/gestion/staff');

        $this->em->clear();
        $this->assertNull($this->em->getRepository(Worker::class)->find($id));
        $this->assertSame([], $this->em->getRepository(WorkerSalary::class)->findBy(['worker' => $id]));
    }

    /**
     * Un sueldo abierto de 21.000 € en 14 pagas.
     */
    private function addSalary(Worker $worker): void
    {
        $this->em->persist((new WorkerSalary())->setWorker($worker)
            ->setValidFrom(new \DateTimeImmutable('2026-01-01'))
            ->setAnnualGross('21000.00')
            ->setPayments(PaySchedule::Fourteen)
            ->setEmployerSsRate('30.00'));
        $this->em->flush();
    }

    public function testDayShowsHowEachEntryCameInAndWhatWasVoided(): void
    {
        $worker = $this->makeWorker();
        // addEntry() los apunta hoy por un día pasado: son registros diferidos.
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
        $absenceId = $absence->getId();
        // Sin reinicio del kernel el Worker sigue en memoria con su colección de
        // ausencias de antes del alta: la ficha no vería la nueva.
        $this->em->clear();

        $crawler = $this->client->request('GET', '/gestion/staff/' . $id);
        $token = $crawler->filter('form[action$="/absence/' . $absenceId . '/reject"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/gestion/staff/' . $id . '/absence/' . $absenceId . '/reject', ['_token' => $token]);
        $this->assertResponseRedirects('/gestion/staff/' . $id);

        $this->em->clear();
        $notices = $this->em->getRepository(Notification::class)->findBy(['kind' => Notification::KIND_WORK_ABSENCE_DECIDED]);
        $this->assertCount(1, $notices);
        $this->assertSame('worker-test-decided', $notices[0]->getRecipient()->getUsername());
        $this->assertSame('Solicitud rechazada: vacaciones', $notices[0]->getTitle());
        $this->assertStringStartsWith('Del 21/12/2026 al 23/12/2026.', (string) $notices[0]->getBody());
    }

    /**
     * Una baja médica sin fecha de alta se registra dejando «Hasta» vacío, y
     * todas las pantallas que pintan ausencias la aguantan: antes el fin era
     * obligatorio y había que inventarse una fecha prevista.
     */
    public function testOpenSickLeaveIsRegisteredWithoutEndDateAndScreensLoad(): void
    {
        $worker = $this->makeWorker();
        $id = $worker->getId();

        $crawler = $this->client->request('GET', '/gestion/staff/' . $id . '/absence/new');
        $form = $crawler->selectButton('Registrar ausencia')->form([
            'absence[type]' => Absence::TYPE_SICK_LEAVE,
            'absence[startDate]' => '2026-10-05',
            'absence[endDate]' => '',
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects();

        $this->em->clear();
        $saved = $this->em->getRepository(Absence::class)->findBy(['worker' => $id]);
        $this->assertCount(1, $saved);
        $this->assertTrue($saved[0]->isOpen());
        $this->assertSame(Absence::STATUS_APPROVED, $saved[0]->getStatus());

        $crawler = $this->client->request('GET', '/gestion/staff/' . $id);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('sin alta todavía', $crawler->text());
        $this->assertCount(1, $crawler->filter('form[action$="/absence/' . $saved[0]->getId() . '/close"]'));

        foreach (['/gestion/staff/calendar', '/gestion/staff/gaps', '/gestion/staff/' . $id . '/calendar', '/gestion/staff/' . $id . '/day/2026-10-07', '/gestion/dashboard'] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
        }
    }

    /**
     * Unas vacaciones sin fecha de fin no se guardan: sólo la baja puede quedar
     * abierta, porque del fin depende el saldo.
     */
    public function testVacationWithoutEndDateIsRejected(): void
    {
        $worker = $this->makeWorker();
        $id = $worker->getId();

        $crawler = $this->client->request('GET', '/gestion/staff/' . $id . '/absence/new');
        $form = $crawler->selectButton('Registrar ausencia')->form([
            'absence[type]' => Absence::TYPE_VACATION,
            'absence[startDate]' => '2026-10-05',
            'absence[endDate]' => '',
        ]);
        $this->client->submit($form);

        // Vuelve a pintar el formulario con el error, sin redirigir.
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'sólo una baja médica puede quedar abierta');
        $this->assertSame([], $this->em->getRepository(Absence::class)->findBy(['worker' => $id]));
    }

    /**
     * Mientras la baja no tiene alta, ocupa todo lo posterior: no se puede
     * registrar otra ausencia detrás sin cerrarla antes.
     */
    public function testOpenSickLeaveBlocksLaterAbsences(): void
    {
        $worker = $this->makeWorker();
        $id = $worker->getId();
        $this->persistOpenSickLeave($worker, '2026-10-05');
        $this->em->clear();

        $crawler = $this->client->request('GET', '/gestion/staff/' . $id . '/absence/new');
        $form = $crawler->selectButton('Registrar ausencia')->form([
            'absence[type]' => Absence::TYPE_VACATION,
            'absence[startDate]' => '2027-03-01',
            'absence[endDate]' => '2027-03-05',
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects();

        $this->em->clear();
        $this->assertCount(1, $this->em->getRepository(Absence::class)->findBy(['worker' => $id]));
    }

    /**
     * Dar el alta con un día ya pasado (el parte llega tarde) cierra la baja en
     * ese día.
     */
    public function testClosingAnOpenSickLeaveSetsItsLastDay(): void
    {
        $worker = $this->makeWorker();
        $id = $worker->getId();
        $absenceId = $this->persistOpenSickLeave($worker, '2026-10-05')->getId();
        $this->em->clear();

        $crawler = $this->client->request('GET', '/gestion/staff/' . $id);
        $token = $crawler->filter('form[action$="/absence/' . $absenceId . '/close"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/gestion/staff/' . $id . '/absence/' . $absenceId . '/close', ['_token' => $token, 'end_date' => '2026-10-16']);
        $this->assertResponseRedirects('/gestion/staff/' . $id);

        $this->em->clear();
        $closed = $this->em->getRepository(Absence::class)->find($absenceId);
        $this->assertFalse($closed->isOpen());
        $this->assertEquals(new \DateTimeImmutable('2026-10-16'), $closed->getEndDate());
    }

    /**
     * Un alta anterior al inicio, o con una fecha que no existe, no toca la baja.
     */
    public function testClosingWithAnInvalidDayLeavesTheLeaveOpen(): void
    {
        $worker = $this->makeWorker();
        $id = $worker->getId();
        $absenceId = $this->persistOpenSickLeave($worker, '2026-10-05')->getId();
        $this->em->clear();

        $crawler = $this->client->request('GET', '/gestion/staff/' . $id);
        $token = $crawler->filter('form[action$="/absence/' . $absenceId . '/close"] input[name="_token"]')->attr('value');
        foreach (['2026-10-04', '2026-02-31', ''] as $day) {
            $this->client->request('POST', '/gestion/staff/' . $id . '/absence/' . $absenceId . '/close', ['_token' => $token, 'end_date' => $day]);
            $this->assertResponseRedirects('/gestion/staff/' . $id);
        }

        $this->em->clear();
        $this->assertTrue($this->em->getRepository(Absence::class)->find($absenceId)->isOpen());
    }

    /**
     * La baja nace complementada al 100 % (la casilla viene marcada) y quien la
     * registra puede desmarcarla: entonces se guarda sin complemento.
     */
    public function testSickLeaveIsToppedUpUnlessUnticked(): void
    {
        $worker = $this->makeWorker();
        $id = $worker->getId();

        $crawler = $this->client->request('GET', '/gestion/staff/' . $id . '/absence/new');
        $this->assertSelectorExists('input[name="absence[topsUpToFullPay]"][checked]');
        $this->assertSelectorExists('[data-csa-show-when="absence[type]=sick_leave"]');
        $form = $crawler->selectButton('Registrar ausencia')->form([
            'absence[type]' => Absence::TYPE_SICK_LEAVE,
            'absence[startDate]' => '2026-10-16',
            'absence[endDate]' => '',
        ]);
        $form['absence[topsUpToFullPay]']->untick();
        $this->client->submit($form);
        $this->assertResponseRedirects();

        $this->em->clear();
        $saved = $this->em->getRepository(Absence::class)->findBy(['worker' => $id]);
        $this->assertCount(1, $saved);
        $this->assertFalse($saved[0]->topsUpToFullPay());
    }

    /**
     * Desde la ficha se quita y se vuelve a poner el complemento de una baja;
     * unas vacaciones no lo admiten.
     */
    public function testTopUpIsToggledOnlyOnSickLeaves(): void
    {
        $worker = $this->makeWorker();
        $id = $worker->getId();
        $sickId = $this->persistOpenSickLeave($worker, '2026-10-16')->getId();
        $vacation = (new Absence())
            ->setWorker($worker)
            ->setType(Absence::TYPE_VACATION)
            ->setStatus(Absence::STATUS_APPROVED)
            ->setStartDate(new \DateTimeImmutable('2026-08-03'))
            ->setEndDate(new \DateTimeImmutable('2026-08-07'));
        $this->em->persist($vacation);
        $this->em->flush();
        $vacationId = $vacation->getId();
        $this->em->clear();

        $crawler = $this->client->request('GET', '/gestion/staff/' . $id);
        $this->assertStringContainsString('la asociación complementa hasta el 100 %', $crawler->text());
        $this->assertCount(0, $crawler->filter('form[action$="/absence/' . $vacationId . '/top-up"]'));
        $token = $crawler->filter('form[action$="/absence/' . $sickId . '/top-up"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/gestion/staff/' . $id . '/absence/' . $sickId . '/top-up', ['_token' => $token]);
        $this->assertResponseRedirects('/gestion/staff/' . $id);
        $this->em->clear();
        $this->assertFalse($this->em->getRepository(Absence::class)->find($sickId)->topsUpToFullPay());

        $this->client->request('POST', '/gestion/staff/' . $id . '/absence/' . $sickId . '/top-up', ['_token' => $token]);
        $this->em->clear();
        $this->assertTrue($this->em->getRepository(Absence::class)->find($sickId)->topsUpToFullPay());

        $this->client->request('POST', '/gestion/staff/' . $id . '/absence/' . $vacationId . '/top-up', ['_token' => $token]);
        $this->assertResponseRedirects('/gestion/staff/' . $id);
        $this->em->clear();
        $this->assertTrue($this->em->getRepository(Absence::class)->find($vacationId)->topsUpToFullPay());
    }

    /**
     * Guarda una baja médica aprobada sin fecha de alta.
     *
     * @param Worker $worker Trabajador.
     * @param string $start  Primer día de baja, 'Y-m-d'.
     * @return Absence
     */
    private function persistOpenSickLeave(Worker $worker, string $start): Absence
    {
        $absence = (new Absence())
            ->setWorker($worker)
            ->setType(Absence::TYPE_SICK_LEAVE)
            ->setStatus(Absence::STATUS_APPROVED)
            ->setStartDate(new \DateTimeImmutable($start));
        $this->em->persist($absence);
        $this->em->flush();

        return $absence;
    }
}
