<?php

namespace App\Service\Push;

use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * El cable de verdad, sobre `minishlink/web-push`. Aquí está todo lo que sabe
 * de VAPID, cifrado y HTTP; nada de eso sale de esta clase.
 */
class WebPushTransport implements PushTransport
{
    /**
     * Segundos que el servicio de push guarda el mensaje para un dispositivo
     * apagado. Tres días: bastante para alcanzar un móvil que estuvo un rato
     * sin cobertura, poco para que no salte un aviso rancio de algo que ya pasó.
     */
    public const TTL_SECONDS = 259200;

    /**
     * Segundos de espera por servicio de push. Con tope para que un endpoint
     * colgado no se lleve por delante el lote entero de un aviso a doscientas
     * personas.
     */
    public const TIMEOUT_SECONDS = 10;

    public function __construct(
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')]
        private readonly string $vapidPublicKey,
        #[Autowire('%env(VAPID_PRIVATE_KEY)%')]
        private readonly string $vapidPrivateKey,
        #[Autowire('%env(VAPID_SUBJECT)%')]
        private readonly string $vapidSubject,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->vapidPublicKey && '' !== $this->vapidPrivateKey;
    }

    public function send(array $subscriptions, string $payload): iterable
    {
        $webPush = new WebPush(
            [
                'VAPID' => [
                    'subject' => $this->vapidSubject,
                    'publicKey' => $this->vapidPublicKey,
                    'privateKey' => $this->vapidPrivateKey,
                ],
            ],
            // Urgencia alta: que el servicio de push lo entregue ya en vez de
            // guardarlo para la próxima ventana de bajo consumo del móvil. Un
            // "falta gente para el reparto de mañana" no admite esperar.
            ['urgency' => 'high', 'TTL' => self::TTL_SECONDS],
            self::TIMEOUT_SECONDS,
            // Sin redirecciones: un endpoint guardado en BBDD no puede desviar
            // la petición a otro host (SSRF).
            ['allow_redirects' => false],
        );

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                new Subscription(
                    $subscription->getEndpoint(),
                    $subscription->getP256dh(),
                    $subscription->getAuth(),
                    'aes128gcm',
                ),
                $payload,
            );
        }

        // queueNotification + un solo flush: la librería lanza todas las
        // peticiones a la vez. Un flush por suscripción las serializaría.
        foreach ($webPush->flush() as $report) {
            yield $this->toDeliveryReport($report);
        }
    }

    /**
     * Traduce un resultado de la librería al nuestro. Público a propósito: la
     * lectura del 404/410 —que es la regla que decide si se borra una fila— se
     * puede así fijar en un test contra objetos reales de la librería.
     *
     * @param MessageSentReport $report el resultado que da la librería
     *
     * @return PushDeliveryReport el mismo resultado en términos de la aplicación
     */
    public function toDeliveryReport(MessageSentReport $report): PushDeliveryReport
    {
        if ($report->isSubscriptionExpired()) {
            return PushDeliveryReport::gone($report->getEndpoint(), $report->getReason());
        }

        return $report->isSuccess()
            ? PushDeliveryReport::delivered($report->getEndpoint())
            : PushDeliveryReport::failed($report->getEndpoint(), $report->getReason());
    }
}
