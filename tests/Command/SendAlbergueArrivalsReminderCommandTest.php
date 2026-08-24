<?php

namespace App\Tests\Command;

use App\Entity\Helper;
use App\Entity\HelperSource;
use App\Entity\Setting;
use App\Entity\Stay;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
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
     * Con la tarea encendida pero sin destinatario configurado, no falla: se
     * queda en "no había nada que hacer" diciendo por qué.
     *
     * Importa para el tick. Esta tarea exige `--to`, que hasta ahora sólo podía
     * venir de la línea del crontab del hosting; cuando la dispare el tick no
     * habrá nadie que se lo pase, y un fallo la dejaría reintentándose cada hora
     * para siempre. Con esto, la pantalla dice qué falta y no hay bucle.
     */
    public function testSinDestinatarioNoFallaYLoDice(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $settings = self::getContainer()->get(AppSettings::class);
        $settings->setBool(AppSettings::EMAIL_ENABLED, true);
        $settings->setBool(AppSettings::EMAIL_ALBERGUE_REMINDER, true);

        try {
            $tester = $this->commandTester();
            $exit = $tester->execute([]);

            $this->assertSame(Command::SUCCESS, $exit, 'Falta configuración, no es una avería del sistema.');
            $this->assertStringContainsString('Sin destinatario configurado', $tester->getDisplay());
        } finally {
            foreach ([AppSettings::EMAIL_ENABLED, AppSettings::EMAIL_ALBERGUE_REMINDER] as $key) {
                $setting = $em->getRepository(Setting::class)->findOneBy(['name' => $key]);
                if ($setting !== null) {
                    $em->remove($setting);
                }
            }
            $em->flush();
        }
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
