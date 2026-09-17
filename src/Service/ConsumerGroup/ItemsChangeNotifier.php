<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupRound;
use App\Entity\Notification;
use App\Entity\Partner;
use App\Repository\UserRepository;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\NotificationPreferences;
use App\Service\Notification\NotificationTopic;
use App\Service\Push\PushSender;

/**
 * Avisa a las socias que YA TENÍAN APUNTADO ALGO en una ronda cuando la
 * comisión cambia sus productos (quita uno que habían pedido, o añade uno
 * nuevo). Sin este aviso, la única forma de enterarse era entrar por su
 * cuenta a revisar el pedido.
 *
 * SÓLO BANDEJA Y MÓVIL, no correo. A diferencia de la apertura de la ronda
 * ({@see ConsumerGroupAnnouncer}), esto puede repetirse varias veces mientras
 * la comisión ajusta el pedido, y un correo por cada retoque es spam; la
 * bandeja y el push, que se ignoran solos si no interesan, no lo son.
 *
 * NO ES IDEMPOTENTE A PROPÓSITO: cada llamada es un cambio real que la
 * comisión acaba de guardar, a diferencia del aviso de apertura (que se
 * puede volver a intentar sin querer repetirlo). Si se aplican dos cambios
 * seguidos, se avisa dos veces, que es lo correcto.
 */
class ItemsChangeNotifier
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly NotificationInbox $inbox,
        private readonly NotificationPreferences $preferences,
        private readonly PushSender $push,
        private readonly NotificationLink $link,
    ) {
    }

    /**
     * @param list<Partner> $partners a quién avisar (ya filtrado a quien tiene
     *                                pedido en esta ronda)
     * @param string        $body     qué ha cambiado, en una frase
     */
    public function notify(ConsumerGroupRound $round, array $partners, string $body): void
    {
        if ([] === $partners) {
            return;
        }

        $title = sprintf('Cambios en tu pedido: %s', $round->getTitle());

        $this->inbox->deliver(
            $this->users->findByPartners($partners),
            Notification::KIND_CONSUMER_GROUP_ITEMS_CHANGED,
            $title,
            $body,
        );

        $pushRecipients = $this->users->findByPartners($this->preferences->filter(
            $partners,
            NotificationTopic::CONSUMER_GROUP,
            NotificationTopic::CHANNEL_PUSH,
        ));
        if ([] !== $pushRecipients) {
            $this->push->sendToMany(
                $pushRecipients,
                $title,
                $body,
                $this->link->pathForKind(Notification::KIND_CONSUMER_GROUP_ITEMS_CHANGED),
                'consumer_group',
            );
        }
    }
}
