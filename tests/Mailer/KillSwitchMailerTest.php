<?php

namespace App\Tests\Mailer;

use App\Mailer\KillSwitchMailer;
use App\Service\AppSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Interruptor general del correo: delega en el mailer real cuando EMAIL_ENABLED
 * está encendido y descarta el envío (sin tocar el mailer) cuando está apagado.
 */
class KillSwitchMailerTest extends TestCase
{
    public function testConElInterruptorEncendidoDelegaEnElMailerReal(): void
    {
        $email = (new Email())->from('noreply@csavegadejarama.org')->to('socix@test.org')->text('hola');
        $envelope = new Envelope(new Address('noreply@csavegadejarama.org'), [new Address('socix@test.org')]);

        $settings = $this->createMock(AppSettings::class);
        $settings->method('getBool')->with(AppSettings::EMAIL_ENABLED)->willReturn(true);

        $inner = $this->createMock(MailerInterface::class);
        $inner->expects($this->once())->method('send')->with($email, $envelope);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('notice');

        (new KillSwitchMailer($inner, $settings, $logger))->send($email, $envelope);
    }

    public function testConElInterruptorApagadoDescartaElEnvio(): void
    {
        $email = (new Email())->from('noreply@csavegadejarama.org')->to('socix@test.org')->text('hola');

        $settings = $this->createMock(AppSettings::class);
        $settings->method('getBool')->with(AppSettings::EMAIL_ENABLED)->willReturn(false);

        $inner = $this->createMock(MailerInterface::class);
        $inner->expects($this->never())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('notice');

        (new KillSwitchMailer($inner, $settings, $logger))->send($email);
    }
}
