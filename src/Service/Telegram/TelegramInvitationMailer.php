<?php

namespace App\Service\Telegram;

use App\Entity\TelegramLink;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Manda a una persona su enlace para darse de alta en el bot de Telegram.
 *
 * A SU correo y a ningún otro sitio: el enlace vincula con esa cuenta el Telegram
 * de quien lo abra, así que cuanto menos viaje, mejor. Si el interruptor general de
 * correo está apagado, el correo se descarta como cualquier otro.
 */
class TelegramInvitationMailer
{
    /**
     * @param MailerInterface $mailer Para enviar.
     */
    public function __construct(private readonly MailerInterface $mailer)
    {
    }

    /**
     * Envía la invitación vigente.
     *
     * @param TelegramLink $link        Invitación (con código sin gastar).
     * @param string       $botUsername @nombre del bot, sin la arroba.
     *
     * @return bool False si la persona no tiene correo o no hay invitación que mandar.
     */
    public function send(TelegramLink $link, string $botUsername): bool
    {
        $email = trim((string) $link->getUser()->getEmail());
        $code = $link->getInvitationCode();
        if ($email === '' || $code === null) {
            return false;
        }

        $this->mailer->send((new TemplatedEmail())
            ->to($email)
            ->subject('Tu acceso al bot de Telegram de la CSA Vega de Jarama')
            ->htmlTemplate('email/telegram_invitation.html.twig')
            ->textTemplate('email/telegram_invitation.txt.twig')
            ->context([
                'name' => $link->getUser()->getDisplayName(),
                'link' => sprintf('https://t.me/%s?start=%s', $botUsername, $code),
                'bot' => $botUsername,
                'expires_at' => $link->getInvitationExpiresAt(),
            ]));

        return true;
    }
}
