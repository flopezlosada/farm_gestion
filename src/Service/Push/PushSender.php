<?php

namespace App\Service\Push;

use App\Entity\NotificationLog;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use App\Service\Notification\NotificationRecorder;
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
        private readonly NotificationRecorder $recorder,
    ) {
    }

    /**
     * Manda un aviso a todos los navegadores de una persona.
     *
     * @param User        $user  quien lo recibe
     * @param string      $title el título del aviso
     * @param string|null $body  el cuerpo del aviso
     * @param string      $path  la ruta que abre al pulsarlo (p. ej. "/panel/voluntariado")
     * @param string      $kind  qué aviso es, para la bitácora ("pickup_reminder")
     *
     * @return int navegadores a los que llegó
     */
    public function sendToUser(User $user, string $title, ?string $body, string $path, string $kind): int
    {
        return $this->sendToMany([$user], $title, $body, $path, $kind);
    }

    /**
     * Manda el MISMO aviso a mucha gente de una vez, en un único lote.
     *
     * Deja una línea en la bitácora por PERSONA —no por navegador ni por
     * lote—, porque la pregunta que se le hace luego al registro siempre es
     * "¿le llegó a fulana?". Quien no tiene ningún navegador suscrito no genera
     * línea: no es un envío fallido, es que ese canal no existe para elle, y
     * eso se ve en su ficha.
     *
     * El $kind es obligatorio a propósito, aunque el envío no lo necesite para
     * nada: con un valor por defecto, un aviso nuevo entraría en el registro
     * como "sin clasificar" sin que nadie se enterara, y el filtro por tipo de
     * la pantalla de avisos iría degradándose solo.
     *
     * @param list<User>  $users quienes lo reciben
     * @param string      $title el título del aviso
     * @param string|null $body  el cuerpo del aviso
     * @param string      $path  la ruta que abre al pulsarlo
     * @param string      $kind  qué aviso es, para la bitácora
     *
     * @return int navegadores a los que llegó
     */
    public function sendToMany(array $users, string $title, ?string $body, string $path, string $kind): int
    {
        if (!$this->transport->isConfigured() || [] === $users) {
            return 0;
        }

        $subscriptions = $this->subscriptions->findByUsers($users);
        if ([] === $subscriptions) {
            return 0;
        }

        try {
            $perUser = $this->dispatch($subscriptions, $title, $body, $path);
        } catch (\Throwable $e) {
            $this->logger->error('No se pudieron enviar las notificaciones push', [
                'recipients' => \count($users),
                'exception' => $e,
            ]);

            // El lote entero se fue al traste: queda constancia para todos los
            // que tenían dónde recibirlo. Sin esto, un fallo del transporte se
            // vería en el registro exactamente igual que "nadie estaba suscrito".
            $this->recordFailure($subscriptions, $kind, $title, $e->getMessage());

            return 0;
        }

        return $this->record($perUser, $kind, $title);
    }

    /**
     * Cifra, manda y poda. Separado de {@see sendToMany()} para que el
     * try/catch de arriba no envuelva media función y se lea de un vistazo qué
     * es lo que puede fallar.
     *
     * Devuelve el resultado DESGLOSADO POR PERSONA y no un total, porque el
     * total no sirve para el registro: con "llegó a 14 navegadores" no se puede
     * responder si le llegó a alguien en concreto, que es justo lo que se
     * pregunta cuando alguien dice que no recibe nada.
     *
     * @param list<PushSubscription> $subscriptions los navegadores
     * @param string                 $title         el título del aviso
     * @param string|null            $body          el cuerpo del aviso
     * @param string                 $path          la ruta que abre al pulsarlo
     *
     * @return array<int, array{user: User, delivered: int, devices: int, gone: int}> por persona
     */
    private function dispatch(array $subscriptions, string $title, ?string $body, string $path): array
    {
        $payload = json_encode([
            'title' => $title,
            'body' => $body ?? '',
            'url' => $path,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        // El endpoint es único, así que sirve para volver del resultado de
        // entrega a la fila que hay que borrar y a quién era suya.
        $byEndpoint = [];
        foreach ($subscriptions as $subscription) {
            $byEndpoint[$subscription->getEndpoint()] = $subscription;
        }

        $perUser = $this->emptyTally($subscriptions);
        $pruned = false;

        foreach ($this->transport->send($subscriptions, $payload) as $report) {
            $owner = $byEndpoint[$report->endpoint] ?? null;
            $user = $owner?->getUser();

            if ($report->subscriptionGone) {
                if (null !== $owner) {
                    $this->entityManager->remove($owner);
                    $pruned = true;
                }
                $goneKey = null !== $user ? spl_object_id($user) : null;
                if (null !== $goneKey && isset($perUser[$goneKey])) {
                    ++$perUser[$goneKey]['gone'];
                }
                continue;
            }

            if ($report->delivered) {
                $key = null !== $user ? spl_object_id($user) : null;
                if (null !== $key && isset($perUser[$key])) {
                    ++$perUser[$key]['delivered'];
                }
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

        return $perUser;
    }

    /**
     * El marcador a cero: una entrada por persona con cuántos navegadores tenía.
     * Se construye antes de mandar para que quien no reciba nada siga saliendo
     * en el resultado con delivered = 0 — si sólo se contaran las entregas, un
     * fallo por persona sería indistinguible de no haberlo intentado.
     *
     * Se agrupa por IDENTIDAD DEL OBJETO y no por su id de base de datos: la
     * cuenta puede no estar persistida todavía y quedarse fuera del marcador,
     * y entonces un aviso entregado no se contaría. El envío no necesita que
     * nadie tenga id, así que el recuento tampoco debe exigirlo.
     *
     * @param list<PushSubscription> $subscriptions
     * @return array<int, array{user: User, delivered: int, devices: int, gone: int}>
     */
    private function emptyTally(array $subscriptions): array
    {
        $tally = [];
        foreach ($subscriptions as $subscription) {
            $user = $subscription->getUser();
            if (null === $user) {
                continue;
            }

            $key = spl_object_id($user);
            if (!isset($tally[$key])) {
                $tally[$key] = ['user' => $user, 'delivered' => 0, 'devices' => 0, 'gone' => 0];
            }
            ++$tally[$key]['devices'];
        }

        return $tally;
    }

    /**
     * Apunta en la bitácora qué recibió cada persona y devuelve el total de
     * navegadores alcanzados, que es lo que esperan quienes llaman.
     *
     * @param array<int, array{user: User, delivered: int, devices: int, gone: int}> $perUser
     */
    private function record(array $perUser, string $kind, string $title): int
    {
        $total = 0;
        foreach ($perUser as $entry) {
            $total += $entry['delivered'];
            $partner = $entry['user']->getPartner();

            if ($entry['delivered'] > 0) {
                $this->recorder->sent(
                    NotificationLog::CHANNEL_PUSH,
                    $kind,
                    sprintf('%d navegador(es)', $entry['delivered']),
                    $title,
                    $partner,
                );
                continue;
            }

            // "Sus navegadores ya no valían" y "el servicio de push rechazó el
            // aviso" son cosas distintas, y el motivo lo dice. Con un único
            // texto de fallo, una suscripción caducada —que es lo normal cuando
            // alguien reinstala el navegador— mandaría a buscar una avería que
            // no existe.
            $motivo = $entry['gone'] === $entry['devices']
                ? 'Sus navegadores ya no estaban dados de alta; se han retirado.'
                : 'Ningún navegador aceptó el aviso.';

            $this->recorder->failed(
                NotificationLog::CHANNEL_PUSH,
                $kind,
                sprintf('%d navegador(es)', $entry['devices']),
                $motivo,
                $title,
                $partner,
            );
        }

        return $total;
    }

    /**
     * Apunta el fallo del lote entero, uno por persona que tenía dónde
     * recibirlo.
     *
     * @param list<PushSubscription> $subscriptions
     */
    private function recordFailure(array $subscriptions, string $kind, string $title, string $error): void
    {
        foreach ($this->emptyTally($subscriptions) as $entry) {
            $this->recorder->failed(
                NotificationLog::CHANNEL_PUSH,
                $kind,
                sprintf('%d navegador(es)', $entry['devices']),
                $error,
                $title,
                $entry['user']->getPartner(),
            );
        }
    }
}
