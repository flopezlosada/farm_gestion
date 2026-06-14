<?php

namespace App\Mailer;

use App\Service\AppSettings;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;

/**
 * Red de seguridad para entornos de prueba: si el ajuste
 * {@see AppSettings::EMAIL_REDIRECT_TO} tiene valor, reescribe el destinatario
 * del SOBRE SMTP de todos los emails que envía la app para que vayan SOLO a
 * esa(s) dirección(es).
 *
 * El header To original del mensaje NO se toca, así que sigue visible a quién
 * iba realmente. Equivale al `framework.mailer.envelope.recipients` nativo, pero
 * resuelto en código por dos motivos: trata el caso "vacío = sin redirección"
 * (el nodo nativo no admite una env var vacía, revienta la validación de tipos
 * en compile-time) y lee la configuración del sistema de ajustes de la app
 * ({@see AppSettings}), conmutable desde la web, en vez de una variable de
 * entorno.
 *
 * Con el ajuste vacío —el default, y como DEBE quedar en producción— este
 * listener no hace nada y cada email va a su destinatario real.
 */
#[AsEventListener]
class RedirectRecipientsListener
{
    public function __construct(
        private readonly AppSettings $settings,
    ) {
    }

    /**
     * Reescribe los destinatarios del sobre antes de entregar, salvo que la
     * redirección esté desactivada (ajuste vacío).
     */
    public function __invoke(MessageEvent $event): void
    {
        $recipients = $this->parseRecipients($this->settings->getString(AppSettings::EMAIL_REDIRECT_TO));
        if ($recipients === []) {
            return;
        }

        $event->getEnvelope()->setRecipients(
            array_map(static fn (string $email): Address => new Address($email), $recipients),
        );
    }

    /**
     * Trocea la lista separada por comas en direcciones limpias, descartando
     * las vacías.
     *
     * @return list<string>
     */
    private function parseRecipients(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $email): bool => $email !== '',
        ));
    }
}
