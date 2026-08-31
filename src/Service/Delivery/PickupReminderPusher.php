<?php

namespace App\Service\Delivery;

use App\Entity\User;
use App\Entity\WeeklyBasket;
use App\Repository\UserRepository;
use App\Service\Cron\EffectLedger;
use App\Service\Push\PushSender;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * El aviso de recogida al móvil. Hermano de {@see PickupReminderMailer} y
 * deliberadamente separado de él: son dos canales con dos fallos distintos
 * —quien no tiene email y quien no tiene el móvil suscrito no son la misma
 * gente— y mezclarlos obligaría a que un canal caído se llevara por delante al
 * otro.
 *
 * NO DECIDE DESTINATARIOS. Recibe las WeeklyBasket ya resueltas por el comando
 * (misma lista que el correo, ya filtrada de repartos cancelados), así que las
 * dos vías avisan exactamente de lo mismo y no hay forma de que el push diga un
 * día y el correo otro.
 *
 * SE ENVÍA POR GRUPOS, no de uno en uno ni todo en un lote. El mensaje lleva la
 * fecha física y el nodo de cada cual —Madrid recoge el miércoles en Cascorro,
 * la Sierra el viernes en Torremocha—, así que un único payload para todo el
 * mundo mentiría a la mitad. Pero dentro de un mismo (día, nodo) el texto es
 * idéntico, y ahí sí se manda en un solo lote: son dos o tres grupos por
 * ejecución en vez de doscientas firmas VAPID y doscientas peticiones HTTPS,
 * que es la diferencia entre unos segundos y un timeout en el hosting.
 *
 * LA IDEMPOTENCIA ES POR SOCIX Y DÍA, con su propia clase de efecto. Comparte
 * criterio con el correo (la clave es el socix, no la cesta, para que una cesta
 * extra puntual el mismo día no dispare dos avisos) pero NO comparte el apunte:
 * con la misma clase de efecto, mandar el correo dejaría el push por enviado
 * para siempre.
 */
class PickupReminderPusher
{
    /**
     * Clase de efecto con la que se apuntan estos avisos en el guardián de
     * idempotencia ({@see EffectLedger}). Distinta de la del correo a
     * propósito: son dos efectos, no dos formas del mismo.
     */
    public const EFFECT_KIND = 'pickup_reminder_push';

    public function __construct(
        private readonly PushSender $push,
        private readonly UserRepository $users,
        private readonly PickupReminderMailer $mailer,
        private readonly EffectLedger $ledger,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Manda el aviso al móvil de cada socix de la lista que aún no lo hubiera
     * recibido para ese día.
     *
     * Quien no tiene cuenta de acceso —o la tiene pero no ha activado los avisos
     * en ningún navegador— sencillamente no recibe nada: el push es un extra
     * sobre el correo, no su sustituto, y no hay nada que reportar por ello.
     *
     * @param WeeklyBasket[] $weeklyBaskets Las cestas a avisar, ya resueltas.
     * @param bool           $resend        Orden explícita de repetir avisos ya emitidos.
     *
     * @return array{sent: int, devices: int, already: int} Socixs avisados, navegadores alcanzados y socixs que ya constaban avisados.
     */
    public function send(array $weeklyBaskets, bool $resend = false): array
    {
        $usersByPartner = $this->usersByPartner($weeklyBaskets);

        // Agrupado por texto del mensaje: dentro de un grupo el aviso es
        // literalmente el mismo, que es la condición para poder mandarlo en un
        // único lote.
        /** @var array<string, array{title: string, body: string, users: list<User>}> $groups */
        $groups = [];
        $already = 0;
        $sent = 0;

        foreach ($weeklyBaskets as $wb) {
            $partner = $wb->getPartner();
            $partnerId = $partner?->getId();
            if (null === $partnerId) {
                continue;
            }

            $recipients = $usersByPartner[$partnerId] ?? [];
            if ([] === $recipients) {
                continue;
            }

            $context = $this->mailer->contextFor($wb);

            // El efecto sólo apunta a quién hay que avisar; el envío va después,
            // agrupado. Reclamar aquí es lo que impide que dos ticks seguidos
            // avisen dos veces, y el callable no puede fallar, así que el
            // apunte nunca se queda reclamado sin efecto.
            $emitted = $this->ledger->once(
                self::EFFECT_KIND,
                sprintf('partner-%d', $partnerId),
                $context['pickup_date'],
                function () use ($context, $recipients, &$groups): void {
                    $title = $this->title($context);
                    $body = $this->body($context);
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

        // Al panel y no al calendario: el aviso dice "te toca el miércoles", y
        // el panel es la pantalla del "qué me toca" —próxima entrega, nodo y
        // hora arriba del todo—. Quien quiera además mover la cesta tiene el
        // calendario a un clic; quien sólo quiera confirmar el día no tiene que
        // leerse una rejilla de doce semanas para encontrarlo.
        $path = $this->urlGenerator->generate('panel');

        $devices = 0;
        foreach ($groups as $group) {
            $devices += $this->push->sendToMany(
                $group['users'],
                $group['title'],
                $group['body'],
                $path,
            );
        }

        return ['sent' => $sent, 'devices' => $devices, 'already' => $already];
    }

    /**
     * Las cuentas de acceso de cada socix de la lista, indexadas por id de
     * socix.
     *
     * En una sola consulta y no una por cesta: son doscientas filas en el peor
     * día y el finder acepta la lista entera. Un socix puede tener más de una
     * cuenta (histórico del dump), así que el valor es una lista.
     *
     * @param WeeklyBasket[] $weeklyBaskets Las cestas a avisar.
     *
     * @return array<int, list<User>> Cuentas por id de socix.
     */
    private function usersByPartner(array $weeklyBaskets): array
    {
        $partners = [];
        foreach ($weeklyBaskets as $wb) {
            $partner = $wb->getPartner();
            if (null !== $partner && null !== $partner->getId()) {
                $partners[$partner->getId()] = $partner;
            }
        }

        $byPartner = [];
        foreach ($this->users->findByPartners(array_values($partners)) as $user) {
            $partnerId = $user->getPartner()?->getId();
            if (null !== $partnerId) {
                $byPartner[$partnerId][] = $user;
            }
        }

        return $byPartner;
    }

    /**
     * El título: cuándo se recoge, que es lo único que se lee de un vistazo en
     * la pantalla bloqueada.
     *
     * @param array<string, mixed> $context Contexto del aviso.
     *
     * @return string El título.
     */
    private function title(array $context): string
    {
        /** @var \DateTimeImmutable $date */
        $date = $context['pickup_date'];
        $today = new \DateTimeImmutable('today');
        $days = (int) $today->diff($date)->format('%r%a');

        return match (true) {
            0 === $days => 'Hoy recoges tu cesta',
            1 === $days => 'Mañana recoges tu cesta',
            default => sprintf('El %s recoges tu cesta', self::WEEKDAYS[(int) $date->format('N')]),
        };
    }

    /**
     * El cuerpo: dónde y qué día exacto. El desplazamiento por festivo va
     * primero porque es lo que rompe la costumbre, y quien va en automático el
     * día de siempre es justo a quien hay que avisar.
     *
     * @param array<string, mixed> $context Contexto del aviso.
     *
     * @return string El cuerpo.
     */
    private function body(array $context): string
    {
        /** @var \DateTimeImmutable $date */
        $date = $context['pickup_date'];

        $parts = [];
        if (true === $context['was_shifted']) {
            $parts[] = 'OJO, no es el día habitual';
        }

        $parts[] = sprintf(
            '%s %d',
            self::WEEKDAYS[(int) $date->format('N')],
            (int) $date->format('j'),
        );

        if (null !== $context['node_name']) {
            $parts[] = $context['node_name'];
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
