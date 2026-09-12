<?php

namespace App\Twig\Extension;

use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * El número de la campanita: cuántos avisos sin abrir tiene quien está mirando.
 *
 * NO HAY AQUÍ UNA FUNCIÓN PARA EL DESTINO DE UN AVISO, y es a propósito. La
 * bandeja no enlaza a la pantalla de cada aviso: enlaza a `notification_open`,
 * que lo marca leído y desde ahí redirige. Así el destino lo resuelve
 * {@see \App\Service\Notification\NotificationLink} en un único punto —el
 * controlador— y no hay una segunda copia de esa decisión en el Twig que pueda
 * discrepar del payload del push.
 *
 * ES UNA CONSULTA POR PÁGINA del panel, y está bien que lo sea: un COUNT sobre el
 * índice (recipient_id, read_at) de una tabla pequeña. Cachearlo en sesión sería
 * más rápido y peor: la campanita se quedaría marcando avisos ya leídos, o peor,
 * sin marcar uno recién llegado.
 */
class NotificationExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly NotificationRepository $notifications,
    ) {
    }

    /**
     * Cómo se llama cada aviso en castellano.
     *
     * La clave técnica sale del nombre de la plantilla de correo, así que en el
     * registro se leía `pickup_reminder` o `staff_open_shift_alert`: exacto,
     * pero ilegible para quien abre la pantalla a ver por qué a una socia no le
     * llegó su recordatorio.
     *
     * Es una lista fija y no un catálogo en base de datos porque estos nombres
     * los pone el código al enviar, no la administración. Un aviso nuevo sin
     * entrada aquí se enseña con su clave cruda: se ve raro, pero se ve.
     */
    private const KIND_LABELS = [
        'pickup_reminder' => 'Recordatorio de recogida',
        'delivery_confirmation' => 'Confirmación de cambio de cesta',
        'delivery_sheet' => 'Listado del reparto',
        'admin_delivery_changes_summary' => 'Resumen de cambios para administración',
        'coverage_alert' => 'Aviso de gente sin avisar',
        'egg_reschedule' => 'Cambio en los huevos',
        'news' => 'Novedades de la web',
        'magic_link' => 'Enlace de acceso',
        'consumer_group_confirmed' => 'Pedido del grupo de consumo',
        'albergue_reminder' => 'Recordatorio del albergue',
        'closure_shift_cancelled' => 'Turno cancelado por cierre',
        'lar_contact' => 'Contacto desde el LAR',
        'shared_basket_request' => 'Cesta compartida · petición',
        'shared_basket_agreed' => 'Cesta compartida · aceptada',
        'shared_basket_rejected' => 'Cesta compartida · rechazada',
        'shared_basket_moved' => 'Cesta compartida · cambio de día',
        'shared_basket_relocated' => 'Cesta compartida · cambio de punto',
        'shared_basket_change' => 'Cesta compartida · cambio',
        'staff_gaps_digest' => 'Resumen de huecos del cuadrante',
        'staff_open_shift_alert' => 'Turno sin cubrir',
        'volunteer_call' => 'Convocatoria de voluntariado',
        'volunteer_change' => 'Cambio en un turno de voluntariado',
        'volunteer_reminder' => 'Recordatorio de voluntariado',
        // Lo que apunta RecordingMailer cuando el correo no sale de una
        // plantilla y no hay de dónde deducir qué aviso es.
        'sin_clasificar' => 'Sin clasificar',
    ];

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notifications', $this->unreadCount(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('notification_kind', self::kindLabel(...)),
        ];
    }

    /**
     * Traduce la clave técnica de un aviso a su nombre en castellano.
     *
     * @param string $kind clave del registro, p. ej. "pickup_reminder"
     * @return string el nombre legible, o la propia clave si no está en la lista
     */
    public static function kindLabel(string $kind): string
    {
        return self::KIND_LABELS[$kind] ?? $kind;
    }

    /**
     * Cuántos avisos sin abrir tiene quien está mirando la página.
     *
     * Devuelve 0 sin sesión iniciada en vez de fallar: el layout del panel lo
     * llama en cada carga, y las pantallas de error se renderizan con el mismo
     * shell y sin usuario.
     *
     * @return int cuántos sin abrir
     */
    public function unreadCount(): int
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->notifications->countUnreadFor($user) : 0;
    }
}
