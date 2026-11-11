<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupOrder;
use App\Entity\Node;
use App\Entity\Notification;
use App\Entity\Partner;
use App\Repository\UserRepository;
use App\Security\PartnerAccessPolicy;
use App\Service\AppSettings;
use App\Service\Cron\EffectLedger;
use App\Service\Delivery\DeliverySheetSchedule;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\NotificationPreferences;
use App\Service\Notification\NotificationTopic;
use App\Service\Push\PushSender;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Avisa a cada familia de que tiene un pedido del grupo de consumo por recoger:
 * qué, dónde, qué día y cuánto.
 *
 * ES UN AVISO APARTE DEL DE LA CESTA, a propósito. El de la cesta sólo llega a
 * quincenales y mensuales y calla con el plazo cerrado; meter ahí el pedido
 * dejaba sin aviso a quien recoge cada semana, y a quien pide sin tener cesta ese
 * día. Además, un pedido del grupo de consumo se olvida justo porque no es lo de
 * siempre.
 *
 * DÓNDE Y CUÁNDO LO DECIDE LA HOJA DEL REPARTO. El pedido se recoge en el nodo
 * habitual de la familia, el día que ese nodo reparte la semana de entrega de la
 * ronda ({@see NodeConsumerGroupDeliveries::ordersForNodeAndDate()}). Se pregunta
 * a la misma regla para que el aviso no anuncie un pedido que no va en la hoja.
 * Qué nodos reparten un día concreto, con festivos y traslados, lo dice
 * {@see DeliverySheetSchedule}.
 *
 * TRES VÍAS, COMO EL RESTO DE AVISOS: la bandeja siempre (es el suelo,
 * {@see NotificationInbox}), y el correo y el móvil según la pantalla de avisos
 * ({@see NotificationTopic::CONSUMER_GROUP}). Cada envío lleva su apunte en el
 * {@see EffectLedger}, así que una segunda pasada del reloj no repite nada.
 */
class ConsumerGroupPickupNotifier
{
    /** Clase de efecto del correo, uno por persona y pedido. */
    public const EFFECT_EMAIL = 'consumer_group_pickup_email';

    /** Clase de efecto de la copia en la bandeja, una por pedido. */
    public const EFFECT_INBOX = 'consumer_group_pickup_inbox';

    /** Clase de efecto del aviso al móvil, uno por pedido. */
    public const EFFECT_PUSH = 'consumer_group_pickup_push';

    public function __construct(
        private readonly DeliverySheetSchedule $schedule,
        private readonly NodeConsumerGroupDeliveries $deliveries,
        private readonly UserRepository $users,
        private readonly NotificationInbox $inbox,
        private readonly NotificationLink $link,
        private readonly NotificationPreferences $preferences,
        private readonly PushSender $push,
        private readonly EffectLedger $ledger,
        private readonly MailerInterface $mailer,
        private readonly AppSettings $settings,
        private readonly PartnerAccessPolicy $accessPolicy,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        private readonly ConsumerGroupEmailPhotos $photos,
    ) {
    }

    /**
     * Los pedidos que se recogen ese día, con su nodo.
     *
     * @param \DateTimeImmutable $pickupDate fecha física de reparto
     *
     * @return list<array{order: ConsumerGroupOrder, node: Node, date: \DateTimeImmutable}>
     */
    public function pickupsOn(\DateTimeImmutable $pickupDate): array
    {
        $pickups = [];
        foreach ($this->schedule->pending($pickupDate) as $delivery) {
            foreach ($this->deliveries->ordersForNodeAndDate($delivery['node'], $delivery['physical_date']) as $order) {
                $pickups[] = ['order' => $order, 'node' => $delivery['node'], 'date' => $delivery['physical_date']];
            }
        }

        return $pickups;
    }

    /**
     * Manda el aviso de cada recogida por las tres vías.
     *
     * @param list<array{order: ConsumerGroupOrder, node: Node, date: \DateTimeImmutable}> $pickups lo de {@see pickupsOn()}
     * @param bool                                                                          $resend  repite el correo a quien ya constaba avisadx
     *
     * @return array{inbox: int, email: int, push: int} cuántos avisos salieron por cada vía
     */
    public function notify(array $pickups, bool $resend = false): array
    {
        $result = ['inbox' => 0, 'email' => 0, 'push' => 0];
        foreach ($pickups as $pickup) {
            $members = $pickup['order']->getPartner()?->getFamilyMembers() ?? [];
            $result['inbox'] += $this->deliverInbox($pickup, $members);
            $result['email'] += $this->deliverEmail($pickup, $resend);
            $result['push'] += $this->deliverPush($pickup, $members);
        }

        return $result;
    }

    /**
     * La copia en la bandeja de cada persona de la familia, sin mirar preferencias.
     *
     * @param array{order: ConsumerGroupOrder, node: Node, date: \DateTimeImmutable} $pickup  la recogida
     * @param list<Partner>                                                           $members la familia
     *
     * @return int cuántas copias se escribieron
     */
    private function deliverInbox(array $pickup, array $members): int
    {
        $written = 0;
        $this->onceOrLog(self::EFFECT_INBOX, $pickup, (string) $pickup['order']->getId(), function () use ($pickup, $members, &$written): void {
            $written = $this->inbox->deliver(
                $this->users->findByPartners($members),
                Notification::KIND_CONSUMER_GROUP_PICKUP,
                $this->headline($pickup),
                $this->body($pickup),
            );
        });

        return $written;
    }

    /**
     * Un correo por persona de la familia con dirección y que no lo ha silenciado.
     *
     * @param array{order: ConsumerGroupOrder, node: Node, date: \DateTimeImmutable} $pickup la recogida
     * @param bool                                                                    $resend repite a quien ya constaba avisadx
     *
     * @return int cuántos correos salieron en esta pasada
     */
    private function deliverEmail(array $pickup, bool $resend): int
    {
        // Antes de llamar al mailer, no confiando en el KillSwitchMailer: el
        // ledger apunta el efecto ANTES de enviar, así que un correo descartado en
        // silencio constaría emitido y no saldría nunca al reencender los envíos.
        if (!$this->settings->getBool(AppSettings::EMAIL_ENABLED)) {
            return 0;
        }

        $order = $pickup['order'];
        $recipients = $this->preferences->filter(
            ConsumerGroupNotifier::familyRecipients($order),
            NotificationTopic::CONSUMER_GROUP,
            NotificationTopic::CHANNEL_EMAIL,
        );
        $url = $this->urls->generate('panel_consumer_group_show', ['id' => $order->getRound()?->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        $preferencesUrl = $this->urls->generate('panel_notifications', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $sent = 0;
        foreach ($recipients as $member) {
            $address = trim((string) $member->getEmail());
            $done = $this->onceOrLog(
                self::EFFECT_EMAIL,
                $pickup,
                $order->getId() . ':' . $member->getId(),
                fn () => $this->mailer->send($this->pickupEmail($pickup, $member, $address, $url, $preferencesUrl)),
                $address,
                $resend,
            );
            if ($done) {
                ++$sent;
            }
        }

        return $sent;
    }

    /**
     * El correo de recogida para una persona de la familia, con las miniaturas
     * de lo que recoge.
     *
     * @param array{order: ConsumerGroupOrder, node: Node, date: \DateTimeImmutable} $pickup         la recogida
     * @param Partner                                                                 $member         destinataria
     * @param string                                                                  $address        su dirección
     * @param string                                                                  $url            ficha del pedido en el panel
     * @param string                                                                  $preferencesUrl pantalla de avisos
     */
    private function pickupEmail(array $pickup, Partner $member, string $address, string $url, string $preferencesUrl): TemplatedEmail
    {
        $order = $pickup['order'];
        $email = (new TemplatedEmail())
            ->to($address)
            ->subject(sprintf('Recoge tu pedido: %s', $order->getRound()?->getTitle()))
            ->htmlTemplate('email/consumer_group_pickup.html.twig')
            ->textTemplate('email/consumer_group_pickup.txt.twig');

        return $email->context([
            'partner' => $member,
            'order' => $order,
            'round' => $order->getRound(),
            'node' => $pickup['node'],
            'pickup_date' => $pickup['date'],
            'payment_info' => $this->settings->getString(AppSettings::CONSUMER_GROUP_PAYMENT_INFO),
            'url' => $url,
            'preferences_url' => $preferencesUrl,
            'photos' => $this->photos->embed($email, ConsumerGroupNotifier::orderedProducts($order)),
            // Los enlaces exigen sesión: a quien no puede entrar no se le pinta
            // un botón que sólo le llevaría al login.
            'can_act' => $this->accessPolicy->canUseActionLinks($member),
        ]);
    }

    /**
     * El aviso al móvil de la familia, a quien no lo ha silenciado.
     *
     * @param array{order: ConsumerGroupOrder, node: Node, date: \DateTimeImmutable} $pickup  la recogida
     * @param list<Partner>                                                           $members la familia
     *
     * @return int a cuántas cuentas llegó
     */
    private function deliverPush(array $pickup, array $members): int
    {
        $recipients = $this->users->findByPartners($this->preferences->filter(
            $members,
            NotificationTopic::CONSUMER_GROUP,
            NotificationTopic::CHANNEL_PUSH,
        ));
        if ([] === $recipients) {
            return 0;
        }

        $sent = 0;
        $this->onceOrLog(self::EFFECT_PUSH, $pickup, (string) $pickup['order']->getId(), function () use ($pickup, $recipients, &$sent): void {
            $sent = $this->push->sendToMany(
                $recipients,
                $this->headline($pickup),
                $this->body($pickup),
                $this->link->pathForKind(Notification::KIND_CONSUMER_GROUP_PICKUP),
                'consumer_group',
            );
        });

        return $sent;
    }

    /**
     * Produce el efecto una sola vez y se traga el fallo: un correo que falla no
     * puede dejar sin aviso al resto de familias. El ledger ya retira el apunte, así
     * que la pasada siguiente lo recoge.
     *
     * La fecha de negocio es la de la RECOGIDA: con la de hoy, el mismo aviso se
     * repetiría al día siguiente.
     *
     * @param string                                                                  $kind      clase de efecto
     * @param array{order: ConsumerGroupOrder, node: Node, date: \DateTimeImmutable} $pickup    la recogida
     * @param string                                                                  $reference referencia única del efecto
     * @param callable                                                                $effect    lo que hay que hacer
     * @param string|null                                                             $target    destino, para el registro
     * @param bool                                                                    $resend    lo produce aunque ya constara emitido
     *
     * @return bool true si el efecto se produjo en esta pasada
     */
    private function onceOrLog(string $kind, array $pickup, string $reference, callable $effect, ?string $target = null, bool $resend = false): bool
    {
        try {
            return $this->ledger->once($kind, $reference, $pickup['date'], $effect, $target, $resend);
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo entregar el aviso de recogida del grupo de consumo', [
                'order' => $pickup['order']->getId(),
                'kind' => $kind,
                'reference' => $reference,
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * El titular de la bandeja y del móvil.
     *
     * @param array{order: ConsumerGroupOrder, node: Node, date: \DateTimeImmutable} $pickup la recogida
     */
    private function headline(array $pickup): string
    {
        return sprintf('Recoge tu pedido: %s', $pickup['order']->getRound()?->getTitle());
    }

    /**
     * El cuerpo corto: cuándo, dónde, cuánto y si falta pagar. Es lo único que se lee
     * en el móvil, así que lleva todo lo que hace falta para ir a por él.
     *
     * @param array{order: ConsumerGroupOrder, node: Node, date: \DateTimeImmutable} $pickup la recogida
     */
    private function body(array $pickup): string
    {
        $order = $pickup['order'];
        $day = (new \IntlDateFormatter('es_ES', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, "EEEE d 'de' MMMM"))
            ->format($pickup['date']);

        return sprintf(
            'El %s en %s. Importe: %s €%s.',
            $day,
            $pickup['node']->getName(),
            number_format($order->getTotal(), 2, ',', '.'),
            match (true) {
                $order->isPaid() => ', pagado',
                $order->willBeChargedByReceipt() => ', va en tu recibo',
                default => ', pendiente de pago',
            },
        );
    }
}
