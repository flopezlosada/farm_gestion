<?php

namespace App\Service\Push;

use App\Entity\PushSubscription;

/**
 * El cable: lo único que sabe hablar con los servicios de push de los
 * navegadores.
 *
 * Está detrás de una interfaz para que {@see PushSender} —que es quien decide a
 * quién se poda y qué se registra— se pueda probar sin red, sin claves VAPID y
 * sin un navegador de verdad. Esa política es la parte que se rompe en silencio;
 * el cable, o va o no va.
 */
interface PushTransport
{
    /**
     * Si hay claves VAPID configuradas. Sin ellas no se puede cifrar nada, y el
     * envío entero se convierte en un no-op silencioso (que es lo que queremos
     * en local y en los tests).
     *
     * @return bool true si se puede enviar
     */
    public function isConfigured(): bool;

    /**
     * Manda el mismo mensaje a todas las suscripciones dadas, en un solo lote.
     *
     * En lote y no de una en una porque `flush()` de la librería lanza todas las
     * peticiones a la vez y espera después: con doscientas suscripciones, la
     * diferencia entre eso y un envío secuencial es la diferencia entre unos
     * segundos y un timeout de PHP.
     *
     * @param list<PushSubscription> $subscriptions los navegadores a los que mandar
     * @param string                 $payload       el JSON ya serializado
     *
     * @return iterable<PushDeliveryReport> un resultado por navegador
     */
    public function send(array $subscriptions, string $payload): iterable;
}
