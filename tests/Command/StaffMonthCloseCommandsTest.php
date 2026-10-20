<?php

namespace App\Tests\Command;

use App\Entity\EmittedEffect;
use App\Entity\Setting;
use App\Entity\User;
use App\Entity\Worker;
use App\Entity\WorkMonthClose;
use App\Service\AppSettings;
use App\Service\Staff\WorkMonth;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Las dos tareas del cierre mensual del registro de jornada: el aviso a gestión
 * laboral de lo que queda sin aprobar, y el envío del resumen a quien trabaja a
 * tiempo parcial.
 *
 * Lo que importa en las dos es lo mismo: que avisen a quien toca y que NO
 * repitan. Un resumen que llega tres veces confunde; un aviso diario se ignora.
 */
class StaffMonthCloseCommandsTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $settings = self::getContainer()->get(AppSettings::class);
        $settings->setBool(AppSettings::FEATURE_LABORAL, true);
        $settings->setBool(AppSettings::EMAIL_ENABLED, true);
        $settings->setBool(AppSettings::EMAIL_STAFF_MONTH_CLOSE, true);
        $settings->setBool(AppSettings::EMAIL_STAFF_MONTHLY_SUMMARY, true);
    }

    protected function tearDown(): void
    {
        foreach (['staff_month_close_reminder', 'staff_monthly_summary'] as $kind) {
            foreach ($this->em->getRepository(EmittedEffect::class)->findBy(['kind' => $kind]) as $effect) {
                $this->em->remove($effect);
            }
        }
        foreach ($this->em->getRepository(WorkMonthClose::class)->findAll() as $close) {
            $this->em->remove($close);
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

    /**
     * Trabajador dado de alta hace años, con cuenta de acceso y correo.
     */
    private function worker(string $name, ?string $workdayType, bool $withAccount = true): Worker
    {
        $worker = (new Worker())
            ->setName($name)
            ->setHireDate(new \DateTimeImmutable('2020-01-01'))
            ->setWorkdayType($workdayType)
            ->setActive(true)
            ->setAnnualVacationDays(22);
        $this->em->persist($worker);

        if ($withAccount) {
            $slug = strtolower(preg_replace('/\W+/', '-', $name));
            $user = (new User())
                ->setUsername('worker-test-' . $slug)
                ->setEmail($slug . '@example.test')
                ->setPassword('x')
                ->setEnabled(true)
                ->setPasswordSet(true)
                ->setWorker($worker);
            $this->em->persist($user);
        }
        $this->em->flush();

        return $worker;
    }

    private function approve(Worker $worker, WorkMonth $month): WorkMonthClose
    {
        $close = new WorkMonthClose($worker, $month, null, new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')));
        $this->em->persist($close);
        $this->em->flush();

        return $close;
    }

    private function runCommand(string $command, array $input = []): string
    {
        $tester = new CommandTester((new Application(self::$kernel))->find($command));
        $tester->execute($input);

        return preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function previousMonth(): WorkMonth
    {
        return WorkMonth::containing(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->previous();
    }

    public function testElResumenSaleSoloAQuienTrabajaATiempoParcialYUnaVez(): void
    {
        $month = $this->previousMonth();
        $this->approve($this->worker('Parcial Uno', Worker::WORKDAY_PART_TIME), $month);
        $this->approve($this->worker('Completa Dos', Worker::WORKDAY_FULL_TIME), $month);

        $display = $this->runCommand('app:send-staff-monthly-summaries');
        $this->assertStringContainsString('parcial-uno@example.test', $display);
        $this->assertStringNotContainsString('completa-dos@example.test', $display);
        $this->assertStringContainsString('1 resumen(es) enviados', $display);

        // Segunda pasada del reloj: no se repite.
        $this->assertStringContainsString('Ningún resumen nuevo', $this->runCommand('app:send-staff-monthly-summaries'));
    }

    public function testUnMesReabiertoYVueltoAAprobarMandaElResumenNuevo(): void
    {
        $month = $this->previousMonth();
        $worker = $this->worker('Parcial Tres', Worker::WORKDAY_PART_TIME);
        $first = $this->approve($worker, $month);
        $this->runCommand('app:send-staff-monthly-summaries');

        $first->reopen(null, new \DateTimeImmutable());
        $this->em->flush();
        $this->approve($worker, $month);

        $this->assertStringContainsString('1 resumen(es) enviados', $this->runCommand('app:send-staff-monthly-summaries'));
    }

    public function testSinTipoDeJornadaOSinCorreoNoSeAdivinaSeAvisa(): void
    {
        $month = $this->previousMonth();
        $this->approve($this->worker('Sin Tipo', null), $month);
        $this->approve($this->worker('Parcial Sin Cuenta', Worker::WORKDAY_PART_TIME, withAccount: false), $month);

        $display = $this->runCommand('app:send-staff-monthly-summaries');

        $this->assertStringContainsString('Sin tipo de jornada en la ficha', $display);
        $this->assertStringContainsString('Sin Tipo', $display);
        $this->assertStringContainsString('sin correo de acceso', $display);
        $this->assertStringContainsString('Parcial Sin Cuenta', $display);
        // Y no se ha apuntado: cuando se arregle, tiene que salir.
        $this->assertSame([], $this->em->getRepository(EmittedEffect::class)->findBy(['kind' => 'staff_monthly_summary']));
    }

    public function testElAvisoDeCierreSaleUnaVezPorEscalonYCallaSiTodoEstaAprobado(): void
    {
        $month = $this->previousMonth();
        $pending = $this->worker('Pendiente Cuatro', Worker::WORKDAY_FULL_TIME);
        // Día 2 del mes siguiente: escalón 1 (el aviso del día 1, por si el reloj se cayó).
        $date = $month->next()->start()->modify('+1 day')->format('Y-m-d');

        $display = $this->runCommand('app:send-staff-month-close-reminder', ['--date' => $date]);
        $this->assertStringContainsString('Pendiente Cuatro', $display);
        $this->assertStringContainsString('Aviso enviado a', $display);
        $this->assertStringContainsString('admin@csavega.local', $display, 'Admin alcanza el permiso de escritura de personal por jerarquía.');

        $this->assertStringContainsString('ya se había enviado', $this->runCommand('app:send-staff-month-close-reminder', ['--date' => $date]));

        // Aprobado el mes, no hay nada de lo que avisar.
        $this->approve($pending, $month);
        $this->assertStringContainsString('está aprobado', $this->runCommand('app:send-staff-month-close-reminder', ['--date' => $date]));
    }
}
