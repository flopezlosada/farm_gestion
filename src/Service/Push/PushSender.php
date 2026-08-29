<?php

namespace App\Service\Push;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * El único sitio que materializa "manda este aviso al móvil de esta gente".
 * Reparte el mensaje a todos los navegadores de cada persona y, de paso,
 * mantiene honesta la lista: las suscripciones que el servicio de push da por
 * muertas (404/410) se borran.
 *
 * TODO ES BEST-EFFORT. Cualquier fallo —claves VAPID sin configurar, red caída,
 * error de cifrado— se registra y se traga. Un push que no sale nunca puede
 * tumbar la operación que lo disparó ni abortar la tanda del planificador a
 * medias: el aviso es un extra, no la operación.
 *
 * EL ENVÍO MASIVO VA EN UN SOLO LOTE ({@see sendToMany()}), y es la razón de
 * que esta clase exista en vez de un simple bucle sobre `sendToUser()`. Cada
 * push lleva firma VAPID y cifrado propios, más una petición HTTPS: con
 * doscientos socixs y varios dispositivos por cabeza son varios cientos de
 * operaciones. Mandarlas de una en una es la diferencia entre unos segundos y
 * un timeout de PHP en un hosting compartido.
 *
 * Aun así, un aviso masivo NO se lanza desde una petición web: se encola y lo
 * manda el planificador. Ver {@see \App\Service\Volunteering\VolunteerCallEscalator}.
 */
class PushSender
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $entityManager,
        private readonly PushTransport $transport,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Manda un aviso a todos los navegadores de una persona.
     *
     * @param User        $user  quien lo recibe
     * @param string      $title el título del aviso
     * @param string|null $body  el cuerpo del aviso
     * @param string      $path  la ruta que abre al pulsarlo (p. ej. "/panel/voluntariado")
     *
     * @return int navegadores a los que llegó
     */
    public function sendToUser(User $user, string $title, ?string $body, string $path): int
    {
        return $this->sendToMany([$user], $title, $body, $path);
    }

    /**
     * Manda el MISMO aviso a mucha gente de una vez, en un único lote.
     *
     * @param list<User>  $users quienes lo reciben
     * @param string      $title el título del aviso
     * @param string|null $body  el cuerpo del aviso
     * @param string      $path  la ruta que abre al pulsarlo
     *
     * @return int navegadores a los que llegó
     */
    public function sendToMany(array $users, string $title, ?string $body, string $path): int
    {
        if (!$this->transport->isConfigured() || [] === $users) {
            return 0;
        }

        $subscriptions = $this->subscriptions->findByUsers($users);
        if ([] === $subscriptions) {
            return 0;
        }

        try {
            return $this->dispatch($subscriptions, $title, $body, $path);
        } catch (\Throwable $e) {
            $this->logger->error('No se pudieron enviar las notificaciones push', [
                'recipients' => \count($users),
                'exception' => $e,
            ]);

            return 0;
        }
    }

    /**
     * Cifra, manda y poda. Separado de {@see sendToMany()} para que el
     * try/catch de arriba no envuelva media función y se lea de un vistazo qué
     * es lo que puede fallar.
     *
     * @param list<PushSubscription> $subscriptions los navegadores
     * @param string                 $title         el título del aviso
     * @param string|null            $body          el cuerpo del aviso
     * @param string                 $path          la ruta que abre al pulsarlo
     *
     * @return int navegadores a los que llegó
     */
    private function dispatch(array $subscriptions, string $title, ?string $body, string $path): int
    {
        $payload = json_encode([
            'title' => $title,
            'body' => $body ?? '',
            'url' => $path,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        // El endpoint es único, así que sirve para volver del resultado de
        // entrega a la fila que hay que borrar.
        $byEndpoint = [];
        foreach ($subscriptions as $subscription) {
            $byEndpoint[$subscription->getEndpoint()] = $subscription;
        }

        $delivered = 0;
        $pruned = false;

        foreach ($this->transport->send($subscriptions, $payload) as $report) {
            if ($report->subscriptionGone) {
                $expired = $byEndpoint[$report->endpoint] ?? null;
                if (null !== $expired) {
                    $this->entityManager->remove($expired);
                    $pruned = true;
                }
                continue;
            }

            if ($report->delivered) {
                ++$delivered;
                continue;
            }

            $this->logger->warning('Fallo al entregar una notificación push', [
                'endpoint' => $report->endpoint,
                'reason' => $report->reason,
            ]);
        }

        // Un solo flush al final: podar de una en una serían tantos UPDATE como
        // dispositivos muertos, justo en el envío que más gente toca.
        if ($pruned) {
            $this->entityManager->flush();
        }

        return $delivered;
    }
}
