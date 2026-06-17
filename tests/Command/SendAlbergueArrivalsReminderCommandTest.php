<?php

namespace App\Tests\Command;

use App\Entity\Helper;
use App\Entity\HelperSource;
use App\Entity\Stay;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests del comando del recordatorio del albergue. Cubre lo crítico de un
 * comando de email: que NO envía con el toggle apagado (el modo de fallo
 * peligroso) y que el dry-run lista sin enviar. No muta la configuración: se
 * apoya en el default del toggle de email (apagado).
 */
class SendAlbergueArrivalsReminderCommandTest extends KernelTestCase
{
    /**
     * Con el toggle de email apagado (su default), el comando sale en verde sin
     * enviar nada y avisándolo.
     */
    public function testNoEnviaConElToggleApagado(): void
    {
        self::bootKernel();
        $tester = $this->commandTester();

        // Sin --dry-run ni --force: pasa el gate del cron (default ON) y se para
        // en el del email (default OFF). No hace falta --to: el gate va antes.
        $exit = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('desactivado', $tester->getDisplay());
    }

    /**
     * El dry-run lista las llegadas próximas sin enviar correo.
     */
    public function testDryRunListaLlegadasSinEnviar(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();

        $source = (new HelperSource())->setName('TEST cmd ' . uniqid());
        $helperName = 'TEST Llega ' . uniqid();
        $helper = (new Helper())->setName($helperName)->setSource($source);
        $arrival = (new \DateTimeImmutable('today'))->modify('+2 days');
        $stay = (new Stay())
            ->setHelper($helper)
            ->setArrivalDate($arrival)
            ->setDepartureDate($arrival->modify('+10 days'))
            ->setStatus(Stay::STATUS_CONFIRMED);
        $em->persist($source);
        $em->persist($helper);
        $em->persist($stay);
        $em->flush();
        $ids = ['stay' => $stay->getId(), 'helper' => $helper->getId(), 'source' => $source->getId()];

        $tester = $this->commandTester();
        $exit = $tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $display = strtolower($tester->getDisplay());
        $this->assertStringContainsString(strtolower($helperName), $display, 'El dry-run debería listar la llegada.');
        $this->assertStringContainsString('sin envío', $display);

        // Limpieza.
        $em->remove($em->getRepository(Stay::class)->find($ids['stay']));
        $em->flush();
        $em->remove($em->getRepository(Helper::class)->find($ids['helper']));
        $em->remove($em->getRepository(HelperSource::class)->find($ids['source']));
        $em->flush();
    }

    /**
     * CommandTester del comando (el kernel ya debe estar arrancado).
     */
    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:send-albergue-arrivals-reminder'));
    }
}
