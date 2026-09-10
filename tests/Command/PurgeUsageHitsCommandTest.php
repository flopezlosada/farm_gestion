<?php

namespace App\Tests\Command;

use App\Entity\CronRun;
use App\Entity\NotificationLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * La tarea de purga. Borra datos, así que lo que se vigila es que borre lo que
 * tiene que borrar y NO lo que no: un corte mal puesto se lleva por delante el
 * material con el que se diagnostica, y eso no se recupera.
 *
 * Las dos retenciones son distintas a propósito —la telemetría caduca a los 90
 * días por minimización, la bitácora al año porque sirve para diagnosticar— y
 * se comprueba justo eso: que no se confundan.
 */
class PurgeUsageHitsCommandTest extends KernelTestCase
{
    private const TARGET = 'purga@test.org';

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(NotificationLog::class)->findBy(['target' => self::TARGET]) as $log) {
            $em->remove($log);
        }
        foreach ($em->getRepository(CronRun::class)->findBy(['taskKey' => 'cron.test_purga']) as $run) {
            $em->remove($run);
        }
        $em->flush();

        parent::tearDown();
    }

    /** Una retención en cero o negativa se rechaza en vez de borrarlo todo. */
    public function testUnaRetencionInvalidaSeRechaza(): void
    {
        self::bootKernel();
        $tester = $this->commandTester();

        $this->assertSame(Command::INVALID, $tester->execute(['--dry-run' => true, '--days' => '0']));
        $this->assertSame(Command::INVALID, $tester->execute(['--dry-run' => true, '--log-days' => '-5']));
    }

    /** El dry-run informa y no toca nada. */
    public function testElDryRunNoBorra(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $viejo = $this->log(new \DateTimeImmutable('-800 days'));
        $em->persist($viejo);
        $em->flush();
        $id = $viejo->getId();

        $tester = $this->commandTester();
        $tester->execute(['--dry-run' => true]);

        $em->clear();
        $this->assertNotNull($em->getRepository(NotificationLog::class)->find($id), 'El dry-run no puede borrar.');
    }

    /**
     * Lo caducado se va, lo reciente se queda. Es el criterio de aceptación de
     * la tarea entera.
     */
    public function testBorraLoCaducadoYRespetaLoReciente(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $viejo = $this->log(new \DateTimeImmutable('-400 days'));
        $reciente = $this->log(new \DateTimeImmutable('-10 days'));
        $runViejo = $this->run(new \DateTimeImmutable('-400 days'));
        $runReciente = $this->run(new \DateTimeImmutable('-10 days'));
        foreach ([$viejo, $reciente, $runViejo, $runReciente] as $fila) {
            $em->persist($fila);
        }
        $em->flush();
        [$viejoId, $recienteId, $runViejoId, $runRecienteId] = [
            $viejo->getId(), $reciente->getId(), $runViejo->getId(), $runReciente->getId(),
        ];

        $this->commandTester()->execute(['--force' => true]);

        $em->clear();
        $logs = $em->getRepository(NotificationLog::class);
        $runs = $em->getRepository(CronRun::class);

        $this->assertNull($logs->find($viejoId), 'Un aviso de hace más de un año se purga.');
        $this->assertNotNull($logs->find($recienteId), 'Un aviso de hace diez días se conserva.');
        $this->assertNull($runs->find($runViejoId), 'Una ejecución de hace más de un año se purga.');
        $this->assertNotNull($runs->find($runRecienteId), 'Una ejecución de hace diez días se conserva.');
    }

    /**
     * La retención de la bitácora es la larga, no la de la telemetría. Con una
     * sola retención, los avisos se irían a los 90 días y la pregunta típica
     * —que llega meses después— se quedaría sin respuesta.
     */
    public function testLaBitacoraNoCaducaConLaRetencionDeLaTelemetria(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // Más viejo que la retención de telemetría (90) y más nuevo que la de
        // diagnóstico (365): tiene que sobrevivir.
        $enMedio = $this->log(new \DateTimeImmutable('-200 days'));
        $em->persist($enMedio);
        $em->flush();
        $id = $enMedio->getId();

        $this->commandTester()->execute(['--force' => true]);

        $em->clear();
        $this->assertNotNull(
            $em->getRepository(NotificationLog::class)->find($id),
            'Un aviso de hace 200 días está dentro del año de retención de diagnóstico.'
        );
    }

    private function log(\DateTimeImmutable $sentAt): NotificationLog
    {
        return (new NotificationLog())
            ->setSentAt($sentAt)
            ->setChannel(NotificationLog::CHANNEL_EMAIL)
            ->setKind('test_purga')
            ->setTarget(self::TARGET)
            ->setStatus(NotificationLog::STATUS_SENT);
    }

    private function run(\DateTimeImmutable $startedAt): CronRun
    {
        return (new CronRun())
            ->setTaskKey('cron.test_purga')
            ->setCommand('app:test-purga')
            ->setStatus(CronRun::STATUS_DONE)
            ->setStartedAt($startedAt);
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:purge-usage-hits'));
    }
}
