<?php

namespace App\Mailer;

use App\Service\AppSettings;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Añade una cabecera Reply-To a los emails salientes cuando el ajuste
 * {@see AppSettings::EMAIL_REPLY_TO} tiene valor.
 *
 * Pensado para el rodaje: el From de la app es un buzón `noreply@` y lxs socixs
 * no deberían responder ahí (la gestión se hace en la web). Pero mientras aún no
 * tienen acceso, una respuesta ("esta semana no recojo") se perdería. Con este
 * ajuste, esas respuestas van a una cuenta humana leída. Se vacía cuando el
 * autoservicio esté rodado.
 *
 * NO pisa un Reply-To que el mensaje ya traiga (p.ej. el formulario de contacto
 * pone el del visitante): solo lo añade si no hay ninguno. Mensajes que no sean
 * {@see Email} (los crudos) se ignoran. Con el ajuste vacío —el default— no hace
 * nada.
 */
#[AsEventListener]
class ReplyToListener
{
    public function __construct(
        private readonly AppSettings $settings,
    ) {
    }

    /**
     * Inyecta el Reply-To configurado antes de entregar, salvo que el ajuste
     * esté vacío o el mensaje ya tenga uno propio.
     */
    public function __invoke(MessageEvent $event): void
    {
        $message = $event->getMessage();
        if (!$message instanceof Email) {
            return;
        }

        if ($message->getReplyTo() !== []) {
            return;
        }

        $replyTo = trim($this->settings->getString(AppSettings::EMAIL_REPLY_TO));
        if ($replyTo === '') {
            return;
        }

        $message->replyTo(new Address($replyTo));
    }
}
