<?php

namespace App\Tests\Command;

use App\Entity\EmittedEffect;
use App\Entity\Notification;
use App\Entity\Setting;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Entity\Worker;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Los dos avisos de olvidos del registro de jornada: la salida abierta (diario)
 * y los días sin fichar (semanal). Van al TRABAJADOR, que es quien sabe qué pasó
 * y puede arreglarlo, y no repiten en el mismo día.
 */
class StaffGapNoticesCommandsTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_LABORAL, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->em->getRepository(EmittedEffect::class)->findBy(['kind' => ['staff_open_shift_alert', 'staff_gaps_digest']]) as $effect) {
            $this->em->remove($effect);
        }
        foreach ($this->em->getRepository(Notification::class)->findBy(['kind' => [Notification::KIND_WORK_OPEN_SHIFT, Notification::KIND_WORK_GAPS]]) as $notice) {
            $this->em->remove($notice);
        }
        foreach ($this->em->getRepository(TimeEntry::class)->findAll() as $entry) {
            $this->em->remove($entry);
        }
        $this->em->flush();
        foreach ($this->em->getRepository(User::class)->findAll() as $user) {
            if (str_starts_with((string) $user->getUsername(), 'worker-test-')) {
                $user->setWorker(null);
                $this->em->remove($user);
            }
        }
        $this->em->flush();
        foreach ($this->em->getRepository(Worker::class)->findAll() as $worker) {
            $this->em->remove($worker);
        }
        foreach ($this->em->getRepository(Setting::class)->findAll() as $setting) {
            $this->em->remove($setting);
        }
        $this->em->flush();

        parent::tearDown();
    }

    private function worker(string $name, bool $withAccount = true): Worker
    {
        $worker = (new Worker())
            ->setName($name)
            ->setHireDate(new \DateTimeImmutable('2020-01-01'))
            ->setAnnualVacationDays(22);
        $this->em->persist($worker);

        if ($withAccount) {
            $slug = strtolower(preg_replace('/\W+/', '-', $name));
            $this->em->persist((new User())
                ->setUsername('worker-test-' . $slug)
                ->setEmail($slug . '@example.test')
                ->setPassword('x')
                ->setEnabled(true)
                ->setPasswordSet(true)
                ->setWorker($worker));
        }
        $this->em->flush();

        return $worker;
    }

    private function clockInYesterday(Worker $worker): \DateTimeImmutable
    {
        $at = new \DateTimeImmutable('yesterday 08:15', new \DateTimeZone('Europe/Madrid'));
        $this->em->persist((new TimeEntry())
            ->setWorker($worker)
            ->setType(TimeEntry::TYPE_IN)
            ->setOccurredAt($at)
            ->setSource(TimeEntry::SOURCE_SUPERVISOR));
        $this->em->flush();

        return $at;
    }

    private function runCommand(string $command, array $input = []): string
    {
        $tester = new CommandTester((new Application(self::$kernel))->find($command));
        $tester->execute($input + ['--force' => true]);

        return preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /**
     * @return list<Notification>
     */
    private function notices(string $kind): array
    {
        $this->em->clear();

        return $this->em->getRepository(Notification::class)->findBy(['kind' => $kind]);
    }

    public function testOpenShiftTellsTheWorkerOnceADay(): void
    {
        $at = $this->clockInYesterday($this->worker('Ana Abierta'));

        $this->runCommand('app:send-staff-open-shift-alert');
        $this->runCommand('app:send-staff-open-shift-alert');

        $notices = $this->notices(Notification::KIND_WORK_OPEN_SHIFT);
        $this->assertCount(1, $notices, 'Dos pasadas del reloj el mismo día avisan una sola vez.');
        $this->assertSame('worker-test-ana-abierta', $notices[0]->getRecipient()->getUsername());
        $this->assertSame('Te quedó la jornada abierta', $notices[0]->getTitle());
        $this->assertStringContainsString(sprintf('el %s a las 08:15', $at->format('d/m')), (string) $notices[0]->getBody());
    }

    public function testOpenShiftOfAWorkerWithoutAccountIsReportedNotSent(): void
    {
        $this->clockInYesterday($this->worker('Sin Cuenta', false));

        $display = $this->runCommand('app:send-staff-open-shift-alert');

        $this->assertSame([], $this->notices(Notification::KIND_WORK_OPEN_SHIFT));
        $this->assertStringContainsString('Sin Cuenta', $display, 'Quien no tiene cuenta queda dicho en la salida de la tarea.');
    }

    /**
     * Un trabajador sin un solo fichaje en la última semana tiene huecos en sus
     * días laborables (sin horario definido cuenta de lunes a viernes).
     */
    public function testGapsDigestTellsTheWorkerTheirUnclockedDays(): void
    {
        $this->worker('Hugo Huecos');

        $this->runCommand('app:send-staff-gaps-digest', ['--days' => 7]);

        $notices = $this->notices(Notification::KIND_WORK_GAPS);
        $this->assertCount(1, $notices);
        $this->assertSame('worker-test-hugo-huecos', $notices[0]->getRecipient()->getUsername());
        $this->assertMatchesRegularExpression('/^Tienes (un día|\d+ días) sin fichar$/', $notices[0]->getTitle());
        $this->assertStringContainsString('añade los fichajes en tu calendario', (string) $notices[0]->getBody());
    }
}
