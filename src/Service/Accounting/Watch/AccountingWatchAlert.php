<?php

namespace App\Service\Accounting\Watch;

use App\Entity\Notification;
use App\Service\Accounting\Agenda\AgendaItem;
use App\Service\AppSettings;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\StaffAudience;
use App\Service\Push\PushSender;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Los avisos de la vigilancia de contabilidad: al móvil y al correo de quien lleva la
 * contabilidad (sin el admin por jerarquía) y a la bandeja de todo el que puede
 * escribir en ella, admins incluidos, como el aviso de una notificación oficial
 * ({@see \App\Service\Accounting\OfficialNotice\OfficialNoticeAlert}). Todos llevan
 * a la portada, que es donde están los botones para resolverlo.
 *
 * El correo existe porque el móvil depende de que cada cual haya activado el push en
 * su navegador y la bandeja sólo se ve entrando: sin él, un aviso urgente podía no
 * salir de la app.
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
        private readonly MailerInterface $mailer,
        private readonly AppSettings $settings,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * ¿Hay alguien que lleve la contabilidad? Sin nadie con el permiso, los avisos sólo
     * llegarían a la bandeja de los admins, que nadie mira a diario: la tarea no debe
     * darlos por dados.
     *
     * @return bool
     */
    public function hasResponsibles(): bool
    {
        return [] !== $this->staff->responsiblesFor(self::ROLE);
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
        return $this->send($item->title, $item->details, self::KIND_URGENT);
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
            self::weeklyLines($items),
            self::KIND_WEEKLY,
        );
    }

    /**
     * El texto del resumen en una línea, para el móvil y la bandeja.
     *
     * @param list<AgendaItem> $items Las tareas; al menos una.
     *
     * @return string
     */
    public static function weeklyBody(array $items): string
    {
        return self::sentence(self::weeklyLines($items));
    }

    /**
     * Las líneas del resumen: las primeras tareas por su título y, si hay más, cuántas.
     *
     * @param list<AgendaItem> $items Las tareas; al menos una.
     *
     * @return list<string>
     */
    private static function weeklyLines(array $items): array
    {
        $titles = array_map(static fn (AgendaItem $item): string => $item->title.'.', \array_slice($items, 0, self::WEEKLY_LISTED));
        $rest = \count($items) - \count($titles);

        return $rest > 0 ? [...$titles, sprintf('Y %d más en la portada.', $rest)] : $titles;
    }

    /**
     * Varias líneas en una sola, como las quiere el móvil.
     *
     * @param list<string> $lines Las líneas.
     *
     * @return string
     */
    private static function sentence(array $lines): string
    {
        return implode(' ', $lines);
    }

    /**
     * Manda el aviso por correo y lo deja en las bandejas y en el móvil.
     *
     * El correo va primero porque es lo único que puede fallar con excepción (el
     * servidor de correo caído): si falla, no se ha dejado nada en ningún sitio y el
     * siguiente intento lo manda entero, sin duplicar la bandeja ni el móvil.
     *
     * @param string       $title Título.
     * @param list<string> $lines Cuerpo, una línea por idea.
     * @param string       $kind  Clase de push, para la bitácora.
     *
     * @return int Cuántos avisos se han dejado en bandejas.
     */
    private function send(string $title, array $lines, string $kind): int
    {
        $this->email($title, $lines);

        $body = self::sentence($lines);
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

    /**
     * El correo a los responsables, si están encendidos el interruptor general de
     * correo y el suyo. Se miran aquí, y no en el `requires` de la tarea, porque
     * apagar el correo no puede apagar el móvil ni la bandeja (lo explica
     * {@see \App\Tests\Service\Cron\CronManifestTest}).
     *
     * @param string       $title Asunto.
     * @param list<string> $lines Cuerpo, una línea por idea.
     */
    private function email(string $title, array $lines): void
    {
        $to = $this->staff->responsibleEmailsFor(self::ROLE);
        if ([] === $to || !$this->emailEnabled()) {
            return;
        }

        $this->mailer->send((new TemplatedEmail())
            ->to(...$to)
            ->subject('CSA Vega · '.$title)
            ->htmlTemplate('email/accounting_watch.html.twig')
            ->textTemplate('email/accounting_watch.txt.twig')
            ->context([
                'title' => $title,
                'lines' => $lines,
                'url' => $this->urls->generate('accounting_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]));
    }

    /**
     * ¿Sale el correo? El interruptor general y el de la vigilancia.
     *
     * @return bool
     */
    private function emailEnabled(): bool
    {
        return $this->settings->getBool(AppSettings::EMAIL_ENABLED)
            && $this->settings->getBool(AppSettings::EMAIL_ACCOUNTING_WATCH);
    }
}
