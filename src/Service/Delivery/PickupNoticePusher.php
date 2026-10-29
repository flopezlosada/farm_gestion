<?php

namespace App\Service\Delivery;

use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Cron\EffectLedger;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\NotificationPreferences;
use App\Service\Notification\NotificationTopic;
use App\Service\Push\PushSender;

/**
 * El aviso de un reparto al móvil y en la bandeja, para quien lo pida: el
 * recordatorio ({@see PickupReminderPusher}) y la confirmación tras el cierre
 * ({@see \App\Command\SendDeliveryConfirmationsCommand}).
 *
 * Es UNA pieza para los dos porque los dos dicen lo mismo —cuándo recoges, qué
 * y dónde— y ya se vio adónde lleva tener dos: dos textos que se separan con el
 * tiempo. Quien llama decide destinatarios y clases de efecto; aquí sólo se
 * entrega.
 *
 * Deliberadamente separada del correo: son canales con fallos distintos —quien
 * no tiene email y quien no tiene el móvil suscrito no son la misma gente— y
 * mezclarlos obligaría a que un canal caído se llevara por delante al otro.
 *
 * SE ENVÍA POR GRUPOS, no de uno en uno ni todo en un lote. El mensaje lleva la
 * fecha física y el nodo de cada cual —Madrid recoge el miércoles en Cascorro,
 * la Sierra el viernes en Torremocha—, así que un único payload para todo el
 * mundo mentiría a la mitad. Pero dentro de un mismo texto se manda en un solo
 * lote: son dos o tres grupos por ejecución en vez de doscientas firmas VAPID y
 * doscientas peticiones HTTPS, que es la diferencia entre unos segundos y un
 * timeout en el hosting.
 *
 * LA IDEMPOTENCIA ES POR SOCIX Y DÍA, con la clase de efecto que pase quien
 * llama, una por canal: con la misma clase, mandar el correo dejaría el push por
 * enviado para siempre. La clave es el socix y no la cesta, para que una cesta
 * extra puntual el mismo día no dispare dos avisos.
 */
class PickupNoticePusher
{
    public function __construct(
        private readonly PushSender $push,
        private readonly UserRepository $users,
        private readonly EffectLedger $ledger,
        private readonly NotificationInbox $inbox,
        private readonly NotificationLink $link,
        private readonly NotificationPreferences $preferences,
    ) {
    }

    /**
     * Deja en la bandeja de cada socix la copia de su aviso.
     *
     * Quien llama la invoca ANTES del correo y del push: es la copia que no se
     * pierde, así que se escribe antes de intentar nada que pueda fallar.
     *
     * NO CONSULTA PREFERENCIAS, a diferencia de {@see send()}. La bandeja es el
     * suelo: quien ha apagado el correo y el móvil es exactamente quien más
     * necesita encontrarlo aquí. Es lo que permite que la pantalla de avisos
     * prometa que apagar un canal no pierde información.
     *
     * @param PickupNotice[] $notices    Los avisos, ya resueltos.
     * @param string         $effectKind Clase de efecto de la copia en el guardián de idempotencia.
     * @param bool           $resend     Orden explícita de repetir un aviso ya escrito.
     *
     * @return array{written: int, already: int} Copias escritas y socixs que ya la tenían.
     */
    public function recordInbox(array $notices, string $effectKind, bool $resend = false): array
    {
        $usersByPartner = $this->usersByPartner($notices);

        $written = 0;
        $already = 0;

        foreach ($notices as $notice) {
            $partnerId = $notice->partner->getId();
            if (null === $partnerId) {
                continue;
            }

            // Quien no tiene cuenta de acceso no tiene bandeja donde mirar, así
            // que no se le escribe nada: sería una fila que nadie puede abrir. Y
            // tampoco se apunta el efecto, para que el día que se le dé acceso
            // empiece a recibir sus copias sin más.
            $recipients = $usersByPartner[$partnerId] ?? [];
            if ([] === $recipients) {
                continue;
            }

            $emitted = $this->ledger->once(
                $effectKind,
                sprintf('partner-%d', $partnerId),
                $notice->pickupDate,
                fn () => $this->inbox->deliver(
                    $recipients,
                    Notification::KIND_PICKUP_REMINDER,
                    $this->title($notice),
                    $this->body($notice),
                ),
                sprintf('partner-%d', $partnerId),
                $resend,
            );

            $emitted ? ++$written : ++$already;
        }

        return ['written' => $written, 'already' => $already];
    }

    /**
     * Manda el aviso al móvil de cada socix que aún no lo hubiera recibido para
     * ese día.
     *
     * Quien no tiene cuenta de acceso —o la tiene pero no ha activado los avisos
     * en ningún navegador— sencillamente no recibe nada: el push es un extra
     * sobre el correo, no su sustituto, y no hay nada que reportar por ello.
     *
     * @param PickupNotice[] $notices    Los avisos, ya resueltos.
     * @param string         $effectKind Clase de efecto del push en el guardián de idempotencia.
     * @param string         $logKind    Cómo se llama el aviso en la bitácora de envíos; el mismo que su correo.
     * @param bool           $resend     Orden explícita de repetir avisos ya emitidos.
     *
     * @return array{sent: int, devices: int, already: int} Socixs avisados, navegadores alcanzados y socixs que ya constaban avisados.
     */
    public function send(array $notices, string $effectKind, string $logKind, bool $resend = false): array
    {
        // Quien ha apagado este aviso en el móvil queda fuera antes de nada. Se
        // filtra por socix y no por aviso: la lista puede traer dos entregas del
        // mismo día para la misma persona (una cesta extra puntual).
        $wanted = [];
        foreach ($this->preferences->filter(
            $this->partnersOf($notices),
            NotificationTopic::PICKUP,
            NotificationTopic::CHANNEL_PUSH,
        ) as $partner) {
            $wanted[(int) $partner->getId()] = true;
        }

        $notices = array_values(array_filter(
            $notices,
            static fn (PickupNotice $notice): bool => isset($wanted[(int) $notice->partner->getId()])
        ));

        $usersByPartner = $this->usersByPartner($notices);

        // Agrupado por texto del mensaje: dentro de un grupo el aviso es
        // literalmente el mismo, que es la condición para poder mandarlo en un
        // único lote.
        /** @var array<string, array{title: string, body: string, users: list<User>}> $groups */
        $groups = [];
        $already = 0;
        $sent = 0;

        foreach ($notices as $notice) {
            $partnerId = $notice->partner->getId();
            if (null === $partnerId) {
                continue;
            }

            $recipients = $usersByPartner[$partnerId] ?? [];
            if ([] === $recipients) {
                continue;
            }

            // El efecto sólo apunta a quién hay que avisar; el envío va después,
            // agrupado. Reclamar aquí es lo que impide que dos ticks seguidos
            // avisen dos veces, y el callable no puede fallar, así que el
            // apunte nunca se queda reclamado sin efecto.
            $emitted = $this->ledger->once(
                $effectKind,
                sprintf('partner-%d', $partnerId),
                $notice->pickupDate,
                function () use ($notice, $recipients, &$groups): void {
                    $title = $this->title($notice);
                    $body = $this->body($notice);
                    $key = $title . "\n" . $body;

                    $groups[$key] ??= ['title' => $title, 'body' => $body, 'users' => []];
                    foreach ($recipients as $user) {
                        $groups[$key]['users'][] = $user;
                    }
                },
                sprintf('partner-%d', $partnerId),
                $resend,
            );

            $emitted ? ++$sent : ++$already;
        }

        // El destino sale de NotificationLink, el MISMO sitio del que sale el de
        // la fila que este aviso deja en la bandeja. Así no hay forma de mover la
        // pantalla de sitio y arreglar una vía dejando la otra apuntando a donde
        // ya no hay nada.
        $path = $this->link->pathForKind(Notification::KIND_PICKUP_REMINDER);

        $devices = 0;
        foreach ($groups as $group) {
            $devices += $this->push->sendToMany(
                $group['users'],
                $group['title'],
                $group['body'],
                $path,
                // En la bitácora, el aviso al móvil y su correo se llaman IGUAL,
                // y el canal es lo que los separa: quien busca "por qué no le
                // llegó" quiere ver de una vez los dos intentos.
                $logKind,
            );
        }

        return ['sent' => $sent, 'devices' => $devices, 'already' => $already];
    }

    /**
     * Lxs socixs de la lista, sin repetir y sin los que no estén persistidos.
     *
     * @param PickupNotice[] $notices los avisos
     *
     * @return list<Partner> lxs socixs
     */
    private function partnersOf(array $notices): array
    {
        $partners = [];
        foreach ($notices as $notice) {
            if (null !== $notice->partner->getId()) {
                $partners[$notice->partner->getId()] = $notice->partner;
            }
        }

        return array_values($partners);
    }

    /**
     * Las cuentas de acceso de cada socix de la lista, indexadas por id de
     * socix.
     *
     * En una sola consulta y no una por aviso: son doscientas filas en el peor
     * día y el finder acepta la lista entera. Un socix puede tener más de una
     * cuenta (histórico del dump), así que el valor es una lista.
     *
     * @param PickupNotice[] $notices Los avisos.
     *
     * @return array<int, list<User>> Cuentas por id de socix.
     */
    private function usersByPartner(array $notices): array
    {
        $byPartner = [];
        foreach ($this->users->findByPartners($this->partnersOf($notices)) as $user) {
            $partnerId = $user->getPartner()?->getId();
            if (null !== $partnerId) {
                $byPartner[$partnerId][] = $user;
            }
        }

        return $byPartner;
    }

    /**
     * El título: cuándo se recoge y qué, que es lo único que se lee de un vistazo
     * en la pantalla bloqueada. El qué no es adorno: a quien sólo recoge huevos,
     * "recoges tu cesta" le suena a verdura.
     *
     * Quien no recoge (movió su cesta a otra semana) recibe justo eso: que esta
     * semana no tiene recogida, que es lo que confirma que su cambio se aplicó.
     *
     * @param PickupNotice $notice El aviso.
     *
     * @return string El título.
     */
    private function title(PickupNotice $notice): string
    {
        if (null === $notice->contents) {
            return 'Esta semana no tienes recogida';
        }

        $today = new \DateTimeImmutable('today');
        $days = (int) $today->diff($notice->pickupDate)->format('%r%a');

        $when = match (true) {
            0 === $days => 'Hoy',
            1 === $days => 'Mañana',
            default => sprintf('El %s', self::WEEKDAYS[(int) $notice->pickupDate->format('N')]),
        };

        return sprintf('%s recoges tu cesta %s', $when, $notice->contents);
    }

    /**
     * El cuerpo: dónde y qué día exacto. El desplazamiento por festivo va
     * primero porque es lo que rompe la costumbre, y quien va en automático el
     * día de siempre es justo a quien hay que avisar.
     *
     * A quien no recoge no se le pinta el sitio: decirle "viernes 30 ·
     * Torremocha" a quien NO tiene que ir se lee como lo contrario.
     *
     * @param PickupNotice $notice El aviso.
     *
     * @return string El cuerpo.
     */
    private function body(PickupNotice $notice): string
    {
        $date = $notice->pickupDate;
        $day = sprintf('%s %d', self::WEEKDAYS[(int) $date->format('N')], (int) $date->format('j'));

        if (null === $notice->contents) {
            return sprintf('Tu cambio está aplicado: el %s no recoges', $day);
        }

        $parts = [];
        if ($notice->wasShifted) {
            $parts[] = 'OJO, no es el día habitual';
        }

        $parts[] = $day;

        if (null !== $notice->nodeName) {
            $parts[] = $notice->nodeName;
        }

        return implode(' · ', $parts);
    }

    /** Días de la semana en castellano, indexados como los devuelve format('N'). */
    private const WEEKDAYS = [
        1 => 'lunes',
        2 => 'martes',
        3 => 'miércoles',
        4 => 'jueves',
        5 => 'viernes',
        6 => 'sábado',
        7 => 'domingo',
    ];
}
