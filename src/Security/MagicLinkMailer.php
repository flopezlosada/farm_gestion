<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * Genera y envía por email el magic-link de acceso de un User.
 *
 * Lógica compartida por los flujos públicos de SecurityController (primer
 * acceso y recuperación) y por la ficha de admin ("enviar enlace de acceso"
 * a un socix concreto durante el despliegue selectivo).
 */
class MagicLinkMailer
{
    public function __construct(
        private readonly LoginLinkHandlerInterface $loginLinkHandler,
        private readonly MailerInterface $mailer,
    ) {
    }

    /**
     * Crea un login link para el User y se lo manda a su email.
     *
     * @param User $user Cuenta destinataria.
     * @return bool false si el User no tiene email (no se envía nada).
     */
    public function send(User $user): bool
    {
        // Trim también el string vacío: users heredados del dump de prod
        // pueden traer email = '' y to('') revienta en tiempo de envío.
        if ($user->getEmail() === null || trim($user->getEmail()) === '') {
            return false;
        }

        $details = $this->loginLinkHandler->createLoginLink($user);

        $message = (new TemplatedEmail())
            ->to($user->getEmail())
            ->subject('Tu enlace de acceso a la CSA Vega de Jarama')
            ->htmlTemplate('email/magic_link.html.twig')
            ->textTemplate('email/magic_link.txt.twig')
            ->context([
                'link' => $details->getUrl(),
                'expires_at' => $details->getExpiresAt(),
            ]);

        $this->mailer->send($message);

        return true;
    }
}
