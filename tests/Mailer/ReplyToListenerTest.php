<?php

namespace App\Tests\Mailer;

use App\Mailer\ReplyToListener;
use App\Service\AppSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Reply-To global: se añade cuando EMAIL_REPLY_TO tiene valor, no cuando está
 * vacío, y nunca pisa un Reply-To que el mensaje ya traiga.
 */
class ReplyToListenerTest extends TestCase
{
    /**
     * Pasa el email dado por el listener configurado con el valor de Reply-To
     * indicado y devuelve las direcciones de Reply-To resultantes.
     *
     * @return list<string>
     */
    private function replyToAfter(string $configured, Email $email): array
    {
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getString')
            ->with(AppSettings::EMAIL_REPLY_TO)
            ->willReturn($configured);

        $envelope = new Envelope(new Address('noreply@csavegadejarama.org'), [new Address('real@socix.org')]);
        $event = new MessageEvent($email, $envelope, 'smtp://test');

        (new ReplyToListener($settings))($event);

        return array_map(
            static fn (Address $a): string => $a->getAddress(),
            $email->getReplyTo(),
        );
    }

    private function plainEmail(): Email
    {
        return (new Email())->from('noreply@csavegadejarama.org')->to('real@socix.org')->text('hola');
    }

    public function testSinValorNoAnadeReplyTo(): void
    {
        $this->assertSame([], $this->replyToAfter('', $this->plainEmail()));
    }

    public function testConValorAnadeReplyTo(): void
    {
        $this->assertSame(['csa@csavegadejarama.org'], $this->replyToAfter('csa@csavegadejarama.org', $this->plainEmail()));
    }

    public function testRecortaEspacios(): void
    {
        $this->assertSame(['csa@csavegadejarama.org'], $this->replyToAfter('  csa@csavegadejarama.org  ', $this->plainEmail()));
    }

    public function testNoPisaUnReplyToYaPresente(): void
    {
        $email = $this->plainEmail()->replyTo(new Address('visitante@contacto.org'));
        $this->assertSame(['visitante@contacto.org'], $this->replyToAfter('csa@csavegadejarama.org', $email));
    }
}
