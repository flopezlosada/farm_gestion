<?php

namespace App\Tests\Service\Telegram;

use App\Entity\TelegramLink;
use App\Entity\User;
use App\Service\Telegram\TelegramInvitationMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * La invitación va al correo de la persona, con su enlace, y sólo si hay a dónde
 * y qué mandar.
 */
class TelegramInvitationMailerTest extends TestCase
{
    /** @var list<RawMessage> */
    private array $sent = [];

    public function testMandaElEnlaceDeSuInvitacionASuCorreo(): void
    {
        $link = new TelegramLink((new User())->setUsername('damaso')->setEmail('damaso@example.test'));
        $code = $link->invite(new \DateTimeImmutable());

        $this->assertTrue($this->mailer()->send($link, 'csa_pruebas_bot'));

        $this->assertCount(1, $this->sent);
        /** @var TemplatedEmail $email */
        $email = $this->sent[0];
        $this->assertSame('damaso@example.test', $email->getTo()[0]->getAddress());
        $this->assertSame('https://t.me/csa_pruebas_bot?start=' . $code, $email->getContext()['link']);
    }

    /** Hay cuentas heredadas con el correo vacío: no se intenta mandar a ''. */
    public function testSinCorreoNoMandaNada(): void
    {
        $link = new TelegramLink((new User())->setUsername('sin-correo')->setEmail('  '));
        $link->invite(new \DateTimeImmutable());

        $this->assertFalse($this->mailer()->send($link, 'csa_pruebas_bot'));
        $this->assertSame([], $this->sent);
    }

    /** Ya vinculada y sin invitación pendiente, no hay enlace que mandar. */
    public function testSinInvitacionPendienteNoMandaNada(): void
    {
        $link = new TelegramLink((new User())->setUsername('ya-vinculada')->setEmail('ya@example.test'));
        $link->link('123', null, new \DateTimeImmutable());

        $this->assertFalse($this->mailer()->send($link, 'csa_pruebas_bot'));
        $this->assertSame([], $this->sent);
    }

    private function mailer(): TelegramInvitationMailer
    {
        return new TelegramInvitationMailer(new class($this->sent) implements MailerInterface {
            /** @param list<RawMessage> $sent */
            public function __construct(private array &$sent)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent[] = $message;
            }
        });
    }
}
