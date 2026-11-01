<?php

namespace App\Tests\Controller;

use App\Entity\Absence;
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
 *
 * Un mes sólo se aprueba con cada día que le tocaba explicado, así que los
 * tests cubren con un permiso los días que no fichan ({@see self::excuse()}).
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
        foreach ($em->getRepository(Absence::class)->findAll() as $a) {
            $em->remove($a);
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

    /**
     * Cubre con un permiso aprobado todo el mes revisado, salvo los días
     * indicados (que quedan como huecos si le tocaban). El día 10, el de los
     * fichajes, puede quedar cubierto: lo trabajado manda sobre la ausencia.
     *
     * @param int[] $exceptDays Días del mes (1-31) que se dejan sin cubrir.
     */
    private function excuse(Worker $worker, array $exceptDays = []): void
    {
        $last = (int) $this->month->end()->modify('-1 day')->format('j');
        $from = 1;
        foreach ([...$exceptDays, $last + 1] as $hole) {
            if ($hole > $from) {
                $absence = (new Absence())
                    ->setWorker($worker)
                    ->setType(Absence::TYPE_PERMIT)
                    ->setStartDate(new \DateTimeImmutable(sprintf('%s-%02d', $this->month->key(), $from)))
                    ->setEndDate(new \DateTimeImmutable(sprintf('%s-%02d', $this->month->key(), $hole - 1)))
                    ->setStatus(Absence::STATUS_APPROVED);
                $this->em->persist($absence);
            }
            $from = $hole + 1;
        }
        $this->em->flush();
    }

    /**
     * Primer día de lunes a jueves del mes revisado a partir del 12: un día que
     * le toca a cualquiera con el horario por defecto.
     */
    private function weekdayAfterTheTenth(): int
    {
        for ($day = 12; ; $day++) {
            $date = new \DateTimeImmutable(sprintf('%s-%02d', $this->month->key(), $day));
            if ((int) $date->format('N') <= 4) {
                return $day;
            }
        }
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
        $this->excuse($worker);

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
        $this->excuse($worker);
        // Otra trabajadora con el mes limpio, para tener un token válido en la página.
        $this->excuse($this->makeWorker('Trabajadora sin incidencias'));

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

    /**
     * Un día que le tocaba sin fichar ni ausencia no deja aprobar. Desde el
     * propio cierre se justifica (aquí, una huelga), con el día ya puesto, se
     * vuelve al cierre y entonces sí se aprueba. Nunca se rellena con el
     * horario: el registro es de la jornada real.
     */
    public function testUnDiaSinFicharSeJustificaDesdeElCierreYEntoncesSeAprueba(): void
    {
        $worker = $this->makeWorker();
        $this->punch($worker, TimeEntry::TYPE_IN, '09:00');
        $this->punch($worker, TimeEntry::TYPE_OUT, '14:00');
        $gapDay = $this->weekdayAfterTheTenth();
        $gap = sprintf('%s-%02d', $this->month->key(), $gapDay);
        $this->excuse($worker, [$gapDay]);
        $this->excuse($this->makeWorker('Trabajadora sin incidencias'));

        $crawler = $this->client->request('GET', $this->closeUrl());
        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter(sprintf('form[action$="/gestion/staff/close/approve/%d"]', $worker->getId())), 'Con un hueco no debe ofrecerse aprobar.');
        $this->assertStringContainsString('Sin fichar ni ausencia', $crawler->filter('.prl-table')->text());

        // Y el POST fabricado tampoco aprueba.
        $token = $crawler->filter('form[action*="/gestion/staff/close/approve/"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/gestion/staff/close/approve/' . $worker->getId(), [
            '_token' => $token,
            'year' => $this->month->year,
            'month' => $this->month->month,
        ]);
        $this->assertNull($this->standingClose($worker));

        // El hueco enlaza al formulario de ausencia con ese día ya puesto.
        $crawler = $this->client->request('GET', $this->closeUrl());
        $link = $crawler->filter('a.mc-gap');
        $this->assertCount(1, $link, 'Sólo el día sin cubrir es un hueco.');
        $crawler = $this->client->click($link->link());
        $this->assertResponseIsSuccessful();
        $form = $crawler->selectButton('Registrar ausencia')->form();
        $this->assertSame($gap, $form['absence[startDate]']->getValue());
        $this->assertSame($gap, $form['absence[endDate]']->getValue());

        $form['absence[type]']->select(Absence::TYPE_STRIKE);
        $this->client->submit($form);
        $this->assertResponseRedirects($this->closeUrl());

        $this->em->clear();
        $strike = $this->em->getRepository(Absence::class)->findOneBy(['worker' => $worker->getId(), 'type' => Absence::TYPE_STRIKE]);
        $this->assertNotNull($strike, 'La huelga debe quedar registrada.');
        $this->assertSame(Absence::STATUS_APPROVED, $strike->getStatus());

        // Con el día explicado, ya se aprueba.
        $this->approve($worker);
        $this->assertNotNull($this->standingClose($worker));
    }

    /**
     * Quien ya no trabaja aquí no tiene fecha de baja: los días tras irse no se
     * pueden justificar con verdad, así que no bloquean. Su mes se aprueba con
     * lo que fichó.
     */
    public function testElMesDeQuienSeFueSeApruebaSinJustificarLosDiasSinFichar(): void
    {
        $worker = $this->makeWorker('Trabajadora que se fue');
        $this->punch($worker, TimeEntry::TYPE_IN, '09:00');
        $this->punch($worker, TimeEntry::TYPE_OUT, '14:00');
        $worker->setActive(false);
        $this->em->flush();

        $this->approve($worker);
        $this->assertNotNull($this->standingClose($worker));
    }

    /**
     * Quien ya trabajaba cuando se empezó a fichar en la app: los días entre su
     * alta y el inicio del registro no son huecos, y el mes se aprueba con lo que
     * hay desde ese día.
     */
    public function testLosDiasAntesDeIniciarElRegistroNoImpidenAprobar(): void
    {
        $worker = $this->makeWorker('Trabajadora que empezó a fichar a mitad de mes');
        $worker->setRecordingStartDate(new \DateTimeImmutable($this->month->key() . '-15'));
        // Del 15 al final, justificado; del 1 al 14, nada de nada.
        $this->em->persist((new Absence())
            ->setWorker($worker)
            ->setType(Absence::TYPE_PERMIT)
            ->setStartDate(new \DateTimeImmutable($this->month->key() . '-15'))
            ->setEndDate($this->month->end()->modify('-1 day'))
            ->setStatus(Absence::STATUS_APPROVED));
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->closeUrl());
        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('a.mc-gap'), 'Antes del inicio del registro no hay huecos.');

        $this->approve($worker);
        $this->assertNotNull($this->standingClose($worker));
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
