<?php

namespace App\Security;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Invitación a un trabajador para entrar a fichar, enviada al darle acceso
 * desde su ficha.
 *
 * A diferencia del {@see MagicLinkMailer}, NO lleva un enlace de acceso: el
 * camino que se quiere es entrar con Google, que no caduca ni obliga a recordar
 * una contraseña. Un magic-link caduca a los 30 minutos y el alta se hace sin
 * que el trabajador esté delante, así que casi siempre llegaría muerto. Para
 * quien no tenga cuenta de Google, la invitación le manda a pedir un enlace por
 * correo (/login/forgot, que sirve a cualquier cuenta).
 */
class WorkerAccessMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'monolog.logger.access')]
        private readonly LoggerInterface $accessLogger,
    ) {
    }

    /**
     * Envía la invitación al email de la cuenta.
     *
     * @param User $user Cuenta del trabajador.
     * @return bool false si la cuenta no tiene email (no se envía nada).
     */
    public function send(User $user): bool
    {
        if ($user->getEmail() === null || trim($user->getEmail()) === '') {
            $this->accessLogger->warning(
                'Invitación de trabajador no enviada: la cuenta no tiene email.',
                ['usuario' => $user->getUserIdentifier()]
            );

            return false;
        }

        $message = (new TemplatedEmail())
            ->to($user->getEmail())
            ->subject('Ya puedes registrar tu jornada en la CSA Vega de Jarama')
            ->htmlTemplate('email/worker_access.html.twig')
            ->textTemplate('email/worker_access.txt.twig')
            ->context([
                'address' => $user->getEmail(),
                'google_url' => $this->urlGenerator->generate('connect_google_start', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'link_url' => $this->urlGenerator->generate('app_login_link_forgot', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);

        $this->mailer->send($message);

        // "Entregado al mailer", no "recibido": ver MagicLinkMailer.
        $this->accessLogger->info('Invitación de trabajador enviada.', ['destinatario' => $user->getEmail()]);

        return true;
    }
}
