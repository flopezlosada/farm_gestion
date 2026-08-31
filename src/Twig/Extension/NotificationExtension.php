<?php

namespace App\Twig\Extension;

use App\Entity\User;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
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

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notifications', $this->unreadCount(...)),
        ];
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
