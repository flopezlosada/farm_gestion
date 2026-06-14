<?php

namespace App\Mailer;

use App\Service\AppSettings;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Interruptor general del correo saliente. Decora el {@see MailerInterface} de
 * la app y, si el ajuste {@see AppSettings::EMAIL_ENABLED} está apagado, descarta
 * el envío en silencio (con una traza en el log) en lugar de entregarlo.
 *
 * Se corta aquí, en el mailer, y no en un listener de MessageEvent porque ese
 * evento no ofrece una forma limpia de cancelar la entrega: vaciar los
 * destinatarios del sobre revienta. Al decorar el mailer, ningún email llega
 * siquiera al transporte cuando el interruptor está apagado.
 *
 * Encendido (default), delega tal cual en el mailer real y cada email sigue
 * gobernado por su propio ajuste.
 */
class KillSwitchMailer implements MailerInterface
{
    public function __construct(
        private readonly MailerInterface $inner,
        private readonly AppSettings $settings,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Entrega el mensaje salvo que el interruptor general esté apagado, en cuyo
     * caso lo descarta y deja constancia en el log.
     *
     * @param RawMessage    $message  Mensaje a enviar.
     * @param Envelope|null $envelope Sobre opcional (remitente/destinatarios).
     */
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if (!$this->settings->getBool(AppSettings::EMAIL_ENABLED)) {
            $this->logger->notice('Email descartado: el interruptor general de envíos (email.enabled) está apagado.');
            return;
        }

        $this->inner->send($message, $envelope);
    }
}
