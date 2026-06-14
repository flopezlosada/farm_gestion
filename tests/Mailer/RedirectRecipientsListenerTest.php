<?php

namespace App\Tests\Mailer;

use App\Mailer\RedirectRecipientsListener;
use App\Service\AppSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Red de seguridad de destinatarios: reescribe el sobre SMTP cuando el ajuste
 * EMAIL_REDIRECT_TO tiene valor, y no toca nada cuando está vacío.
 */
class RedirectRecipientsListenerTest extends TestCase
{
    /**
     * Despacha un MessageEvent con un destinatario "real" a través del listener
     * configurado con el valor de redirección dado, y devuelve los emails del
     * sobre resultante.
     *
     * @return list<string>
     */
    private function recipientsAfterRedirect(string $redirectTo): array
    {
        $settings = $this->createMock(AppSettings::class);
        $settings->method('getString')
            ->with(AppSettings::EMAIL_REDIRECT_TO)
            ->willReturn($redirectTo);

        $email = (new Email())->from('noreply@csavegadejarama.org')->to('real@socix.org')->text('hola');
        $envelope = new Envelope(new Address('noreply@csavegadejarama.org'), [new Address('real@socix.org')]);
        $event = new MessageEvent($email, $envelope, 'smtp://test');

        (new RedirectRecipientsListener($settings))($event);

        return array_map(
            static fn (Address $a): string => $a->getAddress(),
            $event->getEnvelope()->getRecipients(),
        );
    }

    public function testSinValorNoToceLosDestinatariosReales(): void
    {
        $this->assertSame(['real@socix.org'], $this->recipientsAfterRedirect(''));
    }

    public function testConUnaDireccionRedirigeTodoAhi(): void
    {
        $this->assertSame(['paco@test.org'], $this->recipientsAfterRedirect('paco@test.org'));
    }

    public function testAdmiteVariasDireccionesSeparadasPorComas(): void
    {
        $this->assertSame(
            ['a@test.org', 'b@test.org'],
            $this->recipientsAfterRedirect('a@test.org, b@test.org'),
        );
    }

    public function testRecortaEspaciosYDescartaVacios(): void
    {
        $this->assertSame(
            ['a@test.org'],
            $this->recipientsAfterRedirect('  a@test.org ,   '),
        );
    }
}
