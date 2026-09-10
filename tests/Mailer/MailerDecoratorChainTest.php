<?php

namespace App\Tests\Mailer;

use App\Entity\NotificationLog;
use App\Mailer\RecordingMailer;
use App\Repository\NotificationLogRepository;
use App\Service\AppSettings;
use App\Service\Notification\NotificationRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * El ORDEN de los dos decoradores del mailer, comprobado sobre el contenedor de
 * verdad y no sobre una cadena montada a mano.
 *
 * Existe porque el orden ya estuvo mal y ningún test se enteró: los tests de
 * cada decorador lo construyen con un inner de mentira, así que verifican la
 * pieza y no cómo quedan encajadas. Y encajadas al revés el fallo es invisible
 * —el contenedor compila, los correos siguen saliendo— salvo en el único
 * momento en que importa: con el interruptor general apagado.
 *
 * La regla que se protege: `RecordingMailer` envuelve a `KillSwitchMailer`. Al
 * contrario, un correo descartado por el maestro no dejaría ni rastro, y en la
 * pantalla de avisos "los envíos están apagados" se vería igual que "no había a
 * quién avisar".
 */
class MailerDecoratorChainTest extends KernelTestCase
{
    private const TARGET = 'cadena-decoradores@test.org';

    protected function tearDown(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(NotificationLog::class)->findBy(['target' => self::TARGET]) as $log) {
            $em->remove($log);
        }

        // El interruptor se toca en un test y se deja como estaba: es un ajuste
        // global y dejarlo apagado silenciaría los envíos del resto de la suite.
        $setting = $em->getRepository(\App\Entity\Setting::class)->findOneBy(['name' => AppSettings::EMAIL_ENABLED]);
        if ($setting !== null) {
            $em->remove($setting);
        }
        $em->flush();

        parent::tearDown();
    }

    /** El servicio que recibe quien manda un correo es el que registra. */
    public function testElMailerDeLaAppEsElQueRegistra(): void
    {
        self::bootKernel();

        $this->assertInstanceOf(
            RecordingMailer::class,
            self::getContainer()->get(MailerInterface::class),
            'La bitácora tiene que ser el decorador de fuera; si no, no ve lo que el interruptor descarta.'
        );
    }

    /**
     * EL CASO QUE JUSTIFICA TODO: con el interruptor general apagado no sale
     * ningún correo, pero el intento queda apuntado como descartado.
     */
    public function testConLosEnviosApagadosElIntentoQuedaApuntado(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $container->get(AppSettings::class)->setBool(AppSettings::EMAIL_ENABLED, false);

        $container->get(MailerInterface::class)->send(
            (new Email())->from('csa@test.org')->to(self::TARGET)->subject('No debe salir')->text('hola')
        );

        $container->get(EntityManagerInterface::class)->clear();
        $log = $container->get(NotificationLogRepository::class)->findOneBy(['target' => self::TARGET], ['id' => 'DESC']);

        $this->assertNotNull($log, 'Un correo descartado por el interruptor también deja línea en la bitácora.');
        $this->assertSame(NotificationLog::STATUS_DISCARDED, $log->getStatus());
    }

    /**
     * Guarda de que las piezas siguen conectadas: el registrador que usa la
     * aplicación es un servicio del contenedor, no algo que sólo exista en los
     * tests unitarios.
     */
    public function testElRegistradorEstaEnElContenedor(): void
    {
        self::bootKernel();

        $this->assertInstanceOf(NotificationRecorder::class, self::getContainer()->get(NotificationRecorder::class));
    }
}
