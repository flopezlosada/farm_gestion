<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Worker;
use App\Entity\WorkMonthClose;
use App\Service\AppSettings;
use App\Service\Staff\WorkMonth;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El cierre mensual del registro de jornada de punta a punta: aprobar, que el
 * mes aprobado quede de verdad bloqueado, y reabrir.
 *
 * Se trabaja siempre con el MES ANTERIOR al de hoy, que es el que se puede
 * aprobar, para que el test valga lo ejecute el día que lo ejecute.
 */
class WorkMonthCloseControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private WorkMonth $month;

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

        $this->month = WorkMonth::containing(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->previous();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(WorkMonthClose::class)->findAll() as $c) {
            $em->remove($c);
        }
        foreach ($em->getRepository(TimeEntry::class)->findAll() as $e) {
            $em->remove($e);
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

    private function makeWorker(string $name = 'Trabajadora de cierre'): Worker
    {
        $worker = (new Worker())
            ->setName($name)
            ->setHireDate(new \DateTimeImmutable('2020-01-01'))
            ->setWorkdayType(Worker::WORKDAY_PART_TIME)
            ->setActive(true)
            ->setAnnualVacationDays(22);
        $this->em->persist($worker);
        $this->em->flush();

        return $worker;
    }

    /**
     * Un fichaje el día 10 del mes revisado.
     */
    private function punch(Worker $worker, string $type, string $time): TimeEntry
    {
        $at = new \DateTimeImmutable(sprintf('%s-10 %s', $this->month->key(), $time), new \DateTimeZone('Europe/Madrid'));
        $entry = (new TimeEntry())
            ->setWorker($worker)
            ->setType($type)
            ->setOccurredAt($at)
            ->setSource(TimeEntry::SOURCE_SELF);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function closeUrl(): string
    {
        return sprintf('/gestion/staff/close?year=%d&month=%d', $this->month->year, $this->month->month);
    }

    private function approve(Worker $worker): void
    {
        $crawler = $this->client->request('GET', $this->closeUrl());
        $this->assertResponseIsSuccessful();
        $form = $crawler->filter(sprintf('form[action$="/gestion/staff/close/approve/%d"]', $worker->getId()));
        $this->assertCount(1, $form, 'Debe ofrecerse aprobar el mes.');

        $this->client->request('POST', '/gestion/staff/close/approve/' . $worker->getId(), [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
            'year' => $this->month->year,
            'month' => $this->month->month,
        ]);
        $this->assertResponseRedirects();
    }

    private function standingClose(Worker $worker): ?WorkMonthClose
    {
        $this->em->clear();

        return $this->em->getRepository(WorkMonthClose::class)->findOneBy([
            'worker' => $worker->getId(),
            'year' => $this->month->year,
            'month' => $this->month->month,
            'reopenedAt' => null,
        ]);
    }

    public function testAprobarBloqueaElMesYReabrirLoDesbloquea(): void
    {
        $worker = $this->makeWorker();
        $this->punch($worker, TimeEntry::TYPE_IN, '09:00');
        $this->punch($worker, TimeEntry::TYPE_OUT, '14:00');

        $this->approve($worker);
        $close = $this->standingClose($worker);
        $this->assertNotNull($close, 'El mes debe quedar aprobado.');

        // Con el mes aprobado, añadir un registro se rechaza y no se guarda nada.
        $crawler = $this->client->request('GET', '/gestion/staff/' . $worker->getId());
        $token = $crawler->filter('form[action$="/entry/add"] input[name="_token"]')->attr('value');
        $this->client->request('POST', sprintf('/gestion/staff/%d/entry/add', $worker->getId()), [
            '_token' => $token,
            'type' => TimeEntry::TYPE_IN,
            'date' => $this->month->key() . '-11',
            'time' => '09:00',
        ]);
        $this->em->clear();
        $this->assertCount(2, $this->em->getRepository(TimeEntry::class)->findBy(['worker' => $worker->getId()]));

        // El día se ve, pero de sólo lectura.
        $crawler = $this->client->request('GET', sprintf('/gestion/staff/%d/day/%s-10', $worker->getId(), $this->month->key()));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('ya está aprobado', $crawler->filter('.csa-callout')->text());
        $this->assertCount(0, $crawler->filter('form[action$="/entry/add"]'));

        // Reabrir: queda la traza y vuelve a admitir cambios.
        $crawler = $this->client->request('GET', $this->closeUrl());
        $reopenToken = $crawler->filter('#dlg-reopen-' . $close->getId() . ' input[name="_token"]')->attr('value');
        $this->client->request('POST', sprintf('/gestion/staff/close/%d/reopen', $close->getId()), ['_token' => $reopenToken]);
        $this->assertResponseRedirects();

        $this->assertNull($this->standingClose($worker));
        $reopened = $this->em->getRepository(WorkMonthClose::class)->find($close->getId());
        $this->assertNotNull($reopened->getReopenedAt());
        $this->assertNotNull($reopened->getReopenedBy());
    }

    public function testNoSeApruebaUnMesConUnaJornadaSinCerrar(): void
    {
        $worker = $this->makeWorker();
        // Entrada sin salida: esas horas no suman, el resumen mentiría.
        $this->punch($worker, TimeEntry::TYPE_IN, '09:00');
        // Otra trabajadora con el mes limpio, para tener un token válido en la página.
        $this->makeWorker('Trabajadora sin incidencias');

        $crawler = $this->client->request('GET', $this->closeUrl());
        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter(sprintf('form[action$="/gestion/staff/close/approve/%d"]', $worker->getId())), 'No debe ofrecerse aprobar.');
        $this->assertStringContainsString('A corregir', $crawler->filter('.prl-table')->text());

        // Y el POST fabricado tampoco aprueba.
        $token = $crawler->filter('form[action*="/gestion/staff/close/approve/"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/gestion/staff/close/approve/' . $worker->getId(), [
            '_token' => $token,
            'year' => $this->month->year,
            'month' => $this->month->month,
        ]);
        $this->assertNull($this->standingClose($worker));
    }

    public function testElMesEnCursoNoSeAprueba(): void
    {
        $worker = $this->makeWorker();
        $current = $this->month->next();

        $crawler = $this->client->request('GET', sprintf('/gestion/staff/close?year=%d&month=%d', $current->year, $current->month));
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('aún no ha terminado', $crawler->filter('.csa-callout')->text());
        $this->assertCount(0, $crawler->filter('form[action*="/gestion/staff/close/approve/"]'));
    }
}
