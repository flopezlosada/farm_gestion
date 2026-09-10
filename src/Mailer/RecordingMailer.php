<?php

namespace App\Mailer;

use App\Entity\NotificationLog;
use App\Service\AppSettings;
use App\Service\Notification\NotificationRecorder;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Apunta en la bitácora todo el correo que sale de la aplicación, decorando el
 * mailer para verlo pasar.
 *
 * SE REGISTRA EN EL TRANSPORTE Y NO EN CADA EMISOR, y esa es la decisión que
 * hace útil el registro. Hay una docena larga de sitios que mandan correo
 * —recordatorio, confirmación, listados, avisos de voluntariado, enlaces de
 * acceso, digests de jornada…— y sólo cuatro pasaban por el guardián de
 * idempotencia. Instrumentarlos uno a uno significaría que el registro nace
 * cojo y que cada aviso nuevo tiene que acordarse de apuntarse. Aquí no se
 * escapa ninguno, ni los de hoy ni los de mañana.
 *
 * El TIPO de aviso sale del nombre de la plantilla ("email/pickup_reminder.html.twig"
 * → "pickup_reminder"), así que también sale gratis: nadie tiene que declarar
 * nada.
 *
 * ENVUELVE AL INTERRUPTOR GENERAL ({@see KillSwitchMailer}), no al revés, para
 * poder registrar también lo que aquél descarta. El precio es que este
 * decorador tiene que mirar el mismo ajuste para saber si un envío que volvió
 * sin error salió de verdad o se tiró: por dentro, un correo descartado y uno
 * entregado son indistinguibles. Si algún día algo más descarta correo en
 * silencio, esta clase lo etiquetará como enviado y habrá que revisarlo.
 *
 * Se registra a quién iba dirigido el mensaje (la cabecera "To"), no a dónde lo
 * llevó el sobre: en pruebas la redirección reescribe el sobre y, si se mirara
 * eso, el registro diría que todo el correo fue a la misma dirección.
 */
class RecordingMailer implements MailerInterface
{
    /** Tipo con el que se apunta un correo del que no se puede deducir cuál es. */
    private const KIND_UNKNOWN = 'sin_clasificar';

    public function __construct(
        private readonly MailerInterface $inner,
        private readonly NotificationRecorder $recorder,
        private readonly AppSettings $settings,
    ) {
    }

    /**
     * Entrega el mensaje y deja constancia de lo que pasó con él.
     *
     * La excepción, si la hay, se relanza después de registrarla: quien envía
     * tiene que seguir enterándose de que su correo no salió. El registro
     * observa, no amortigua.
     *
     * @param RawMessage    $message  Mensaje a enviar.
     * @param Envelope|null $envelope Sobre opcional (remitente/destinatarios).
     */
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $kind = $this->kindOf($message);
        $subject = $message instanceof Email ? $message->getSubject() : null;
        $targets = $this->targetsOf($message, $envelope);

        try {
            $this->inner->send($message, $envelope);
        } catch (\Throwable $e) {
            foreach ($targets as $target) {
                $this->recorder->failed(NotificationLog::CHANNEL_EMAIL, $kind, $target, $e->getMessage(), $subject);
            }

            throw $e;
        }

        $discarded = !$this->settings->getBool(AppSettings::EMAIL_ENABLED);
        foreach ($targets as $target) {
            $discarded
                ? $this->recorder->discarded(
                    NotificationLog::CHANNEL_EMAIL,
                    $kind,
                    $target,
                    'El interruptor general de envíos está apagado.',
                    $subject,
                )
                : $this->recorder->sent(NotificationLog::CHANNEL_EMAIL, $kind, $target, $subject);
        }
    }

    /**
     * Qué aviso es, deducido del nombre de la plantilla:
     * "email/pickup_reminder.html.twig" → "pickup_reminder".
     *
     * Un correo sin plantilla (los pocos que se componen a mano) se apunta como
     * sin clasificar en vez de quedarse fuera: en el registro tiene que estar
     * todo, aunque de algunos sólo se sepa el asunto.
     */
    private function kindOf(RawMessage $message): string
    {
        if (!$message instanceof TemplatedEmail) {
            return self::KIND_UNKNOWN;
        }

        $template = $message->getHtmlTemplate() ?? $message->getTextTemplate();
        if ($template === null) {
            return self::KIND_UNKNOWN;
        }

        $name = basename($template);
        foreach (['.html.twig', '.txt.twig', '.twig'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return substr($name, 0, -\strlen($suffix));
            }
        }

        return $name;
    }

    /**
     * A quién iba: las direcciones del "To", y si no hubiera (un correo sólo
     * con copia oculta), las del sobre. Una línea de bitácora por destinatario,
     * porque la pregunta que se le hace al registro siempre es por una persona.
     *
     * @return list<string>
     */
    private function targetsOf(RawMessage $message, ?Envelope $envelope): array
    {
        $addresses = $message instanceof Email ? $message->getTo() : [];

        if ($addresses === [] && $envelope !== null) {
            $addresses = $envelope->getRecipients();
        }

        $targets = array_map(static fn ($address) => $address->getAddress(), $addresses);

        // Un mensaje sin destinatario legible sigue mereciendo su línea: si no,
        // un fallo de configuración que deja correos sin "To" se vería como que
        // no se envió nada, y no como el problema que es.
        return $targets === [] ? ['(sin destinatario)'] : $targets;
    }
}
