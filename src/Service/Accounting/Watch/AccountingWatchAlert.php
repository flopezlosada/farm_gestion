<?php

namespace App\Service\Accounting\Watch;

use App\Entity\Notification;
use App\Service\Accounting\Agenda\AgendaItem;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\StaffAudience;
use App\Service\Push\PushSender;

/**
 * Los avisos de la vigilancia de contabilidad: al móvil de quien lleva la contabilidad
 * (sin el admin por jerarquía) y a la bandeja de todo el que puede escribir en ella,
 * admins incluidos, como el aviso de una notificación oficial
 * ({@see \App\Service\Accounting\OfficialNotice\OfficialNoticeAlert}). Los dos llevan
 * a la portada, que es donde están los botones para resolverlo.
 */
class AccountingWatchAlert
{
    /** Quien puede resolver las tareas: el permiso de escribir en contabilidad. */
    public const ROLE = 'ROLE_GESTION_CONTABILIDAD_EDIT';

    /** Clase de efecto y de push del aviso de una tarea urgente. */
    public const KIND_URGENT = 'accounting_watch_urgent';

    /** Clase de efecto y de push del resumen semanal. */
    public const KIND_WEEKLY = 'accounting_watch_weekly';

    /** Tareas que se nombran en el resumen; el resto se cuenta. */
    public const WEEKLY_LISTED = 8;

    public function __construct(
        private readonly StaffAudience $staff,
        private readonly NotificationInbox $inbox,
        private readonly PushSender $push,
        private readonly NotificationLink $link,
    ) {
    }

    /**
     * Avisa de una tarea urgente.
     *
     * @param AgendaItem $item La tarea.
     *
     * @return int Cuántos avisos se han dejado en bandejas.
     */
    public function urgent(AgendaItem $item): int
    {
        return $this->send($item->title, implode(' ', $item->details), self::KIND_URGENT);
    }

    /**
     * Manda el resumen de la semana.
     *
     * @param list<AgendaItem> $items Las tareas que no apremian; al menos una.
     *
     * @return int Cuántos avisos se han dejado en bandejas.
     */
    public function weekly(array $items): int
    {
        $count = \count($items);

        return $this->send(
            1 === $count ? 'Contabilidad: una tarea pendiente' : sprintf('Contabilidad: %d tareas pendientes', $count),
            self::weeklyBody($items),
            self::KIND_WEEKLY,
        );
    }

    /**
     * El texto del resumen: las primeras tareas por su título y, si hay más, cuántas.
     *
     * @param list<AgendaItem> $items Las tareas; al menos una.
     *
     * @return string
     */
    public static function weeklyBody(array $items): string
    {
        $titles = array_map(static fn (AgendaItem $item): string => $item->title, \array_slice($items, 0, self::WEEKLY_LISTED));
        $rest = \count($items) - \count($titles);

        return implode('. ', $titles) . '.' . ($rest > 0 ? sprintf(' Y %d más en la portada.', $rest) : '');
    }

    /**
     * Deja el aviso en las bandejas y lo manda al móvil de los responsables.
     *
     * @param string $title Título.
     * @param string $body  Cuerpo.
     * @param string $kind  Clase de push, para la bitácora.
     *
     * @return int Cuántos avisos se han dejado en bandejas.
     */
    private function send(string $title, string $body, string $kind): int
    {
        $delivered = $this->inbox->deliver($this->staff->withRole(self::ROLE), Notification::KIND_ACCOUNTING_AGENDA, $title, $body);
        $this->push->sendToMany(
            $this->staff->responsiblesFor(self::ROLE),
            $title,
            $body,
            $this->link->pathForKind(Notification::KIND_ACCOUNTING_AGENDA),
            $kind,
        );

        return $delivered;
    }
}
