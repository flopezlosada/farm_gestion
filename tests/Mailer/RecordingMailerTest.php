<?php

namespace App\Tests\Mailer;

use App\Entity\NotificationLog;
use App\Mailer\RecordingMailer;
use App\Service\AppSettings;
use App\Service\Notification\NotificationRecorder;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * La bitácora del correo saliente. Lo que se comprueba aquí es que el registro
 * cuente la verdad en los tres desenlaces —salió, reventó, se descartó—, porque
 * un registro que se equivoca es peor que no tenerlo: manda a buscar la avería
 * al sitio equivocado.
 */
class RecordingMailerTest extends TestCase
{
    /**
     * Un correo entregado deja su línea, con el tipo deducido de la plantilla y
     * una línea POR DESTINATARIO: al registro se le pregunta siempre por una
     * persona.
     */
    public function testUnEnvioCorrectoSeApuntaConSuTipoYCadaDestinatarix(): void
    {
        $recorder = $this->createMock(NotificationRecorder::class);
        $recorder->expects($this->exactly(2))->method('sent');
        $recorder->expects($this->never())->method('failed');

        $email = (new TemplatedEmail())
            ->to('una@test.org', 'otra@test.org')
            ->subject('Recordatorio: tu cesta')
            ->htmlTemplate('email/pickup_reminder.html.twig');

        $this->mailer($recorder, enabled: true)->send($email);
    }

    /**
     * El tipo sale del nombre de la plantilla, sin que quien envía tenga que
     * declarar nada. Es lo que hace que el registro cubra también los avisos
     * que nadie instrumentó.
     */
    public function testElTipoSaleDelNombreDeLaPlantilla(): void
    {
        $capturado = null;
        $recorder = $this->createMock(NotificationRecorder::class);
        $recorder->method('sent')->willReturnCallback(
            static function (string $channel, string $kind) use (&$capturado): void {
                $capturado = $kind;
            }
        );

        $email = (new TemplatedEmail())
            ->to('quien@test.org')
            ->subject('Tu cesta de esta semana')
            ->htmlTemplate('email/delivery_confirmation.html.twig');

        $this->mailer($recorder, enabled: true)->send($email);

        $this->assertSame('delivery_confirmation', $capturado);
    }

    /**
     * Un correo compuesto a mano, sin plantilla, también entra en el registro:
     * de algunos sólo se sabrá el asunto, pero ninguno puede faltar.
     */
    public function testUnCorreoSinPlantillaSeApuntaComoSinClasificar(): void
    {
        $capturado = null;
        $recorder = $this->createMock(NotificationRecorder::class);
        $recorder->method('sent')->willReturnCallback(
            static function (string $channel, string $kind) use (&$capturado): void {
                $capturado = $kind;
            }
        );

        $this->mailer($recorder, enabled: true)->send(
            (new Email())->to('quien@test.org')->subject('Escrito a mano')->text('hola')
        );

        $this->assertSame('sin_clasificar', $capturado);
    }

    /**
     * Si el transporte revienta, queda apuntado el fallo Y la excepción sigue
     * su curso: registrar no es amortiguar, quien envía tiene que enterarse.
     */
    public function testUnFalloSeApuntaYLaExcepcionSigue(): void
    {
        $recorder = $this->createMock(NotificationRecorder::class);
        $recorder->expects($this->once())->method('failed');
        $recorder->expects($this->never())->method('sent');

        $inner = $this->createMock(MailerInterface::class);
        $inner->method('send')->willThrowException(new TransportException('el servidor dice que no'));

        $mailer = new RecordingMailer($inner, $recorder, $this->settings(true));

        $this->expectException(TransportException::class);
        $mailer->send((new Email())->to('quien@test.org')->subject('Da igual')->text('hola'));
    }

    /**
     * EL CASO QUE JUSTIFICA EL ORDEN DE LOS DECORADORES: con el interruptor
     * general apagado, el correo no sale pero el intento SÍ se registra, y como
     * descartado, no como enviado. Si no se apuntara, un maestro apagado se
     * vería exactamente igual que una tarea que no encontró a nadie.
     */
    public function testConElInterruptorApagadoSeApuntaComoDescartado(): void
    {
        $recorder = $this->createMock(NotificationRecorder::class);
        $recorder->expects($this->once())->method('discarded');
        $recorder->expects($this->never())->method('sent');

        $this->mailer($recorder, enabled: false)->send(
            (new Email())->to('quien@test.org')->subject('No sale')->text('hola')
        );
    }

    /**
     * @param bool $enabled valor del interruptor general de envíos
     */
    private function mailer(NotificationRecorder $recorder, bool $enabled): RecordingMailer
    {
        return new RecordingMailer(
            $this->createMock(MailerInterface::class),
            $recorder,
            $this->settings($enabled),
        );
    }

    private function settings(bool $enabled): AppSettings
    {
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getBool')->willReturnCallback(
            static fn (string $key): bool => $key === AppSettings::EMAIL_ENABLED ? $enabled : false
        );

        return $settings;
    }

    /** Guarda de que el canal se apunta siempre como correo. */
    public function testElCanalEsSiempreCorreo(): void
    {
        $capturado = null;
        $recorder = $this->createMock(NotificationRecorder::class);
        $recorder->method('sent')->willReturnCallback(
            static function (string $channel) use (&$capturado): void {
                $capturado = $channel;
            }
        );

        $this->mailer($recorder, enabled: true)->send(
            (new Email())->to('quien@test.org')->subject('Hola')->text('hola')
        );

        $this->assertSame(NotificationLog::CHANNEL_EMAIL, $capturado);
    }
}
