<?php

namespace App\Service\Push;

/**
 * El resultado de intentar entregar un aviso a UN navegador, en términos de la
 * aplicación y no de la librería.
 *
 * Existe para que la política de envío ({@see PushSender}) no tenga que conocer
 * los tipos de `minishlink/web-push`: así la poda de suscripciones muertas se
 * puede probar sin red y sin navegador, que es donde de verdad importa que esté
 * bien.
 *
 * `subscriptionGone` es la distinción que vale: un fallo pasajero (el servicio
 * de push caído, un timeout) se registra y ya está, pero un 404/410 significa
 * que ese navegador ya no existe y su fila hay que borrarla.
 */
final class PushDeliveryReport
{
    /**
     * @param string      $endpoint         la URL del servicio de push
     * @param bool        $delivered        si el aviso llegó
     * @param bool        $subscriptionGone si el navegador ya no existe (404/410)
     * @param string|null $reason           el motivo del fallo, si lo hubo
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly bool $delivered,
        public readonly bool $subscriptionGone,
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * @param string $endpoint la URL del servicio de push
     */
    public static function delivered(string $endpoint): self
    {
        return new self($endpoint, true, false);
    }

    /**
     * @param string      $endpoint la URL del servicio de push
     * @param string|null $reason   el motivo que dio el servicio
     */
    public static function gone(string $endpoint, ?string $reason = null): self
    {
        return new self($endpoint, false, true, $reason);
    }

    /**
     * @param string      $endpoint la URL del servicio de push
     * @param string|null $reason   el motivo del fallo
     */
    public static function failed(string $endpoint, ?string $reason = null): self
    {
        return new self($endpoint, false, false, $reason);
    }
}
