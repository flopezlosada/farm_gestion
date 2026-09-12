<?php

namespace App\Service\ConsumerGroup;

use App\Entity\ConsumerGroupRound;
use App\Entity\Notification;
use App\Entity\Partner;
use App\Repository\PartnerRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
use App\Security\PartnerAccessPolicy;
use App\Service\AppSettings;
use App\Service\Cron\EffectLedger;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\NotificationPreferences;
use App\Service\Notification\NotificationTopic;
use App\Service\Push\PushSender;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Cuenta a la asociación que hay un pedido abierto del grupo de consumo.
 *
 * ES LO QUE HACÍA UNA PERSONA EN EL GRUPO DE MENSAJERÍA. Hasta ahora el pedido
 * se abría en un documento compartido y alguien avisaba a mano; sin ese aviso,
 * el pedido existe pero no lo ve nadie. Por eso esto no es un adorno del módulo:
 * es la mitad que lo hace funcionar.
 *
 * VA A TODA SOCIX ACTIVA, no a una lista de apuntadxs. El grupo de consumo está
 * abierto a toda la asociación —se decidió así— de modo que no hay a quién
 * suscribir: lo que hay es quien no quiere enterarse, y eso ya lo resuelve la
 * pantalla de avisos ({@see NotificationTopic::CONSUMER_GROUP}). Con un alta
 * previa, el primer pedido lo habrían visto cero personas, porque nadie entra a
 * darse de alta en algo cuya existencia todavía no conoce.
 *
 * LO DISPARA UN BOTÓN, no el guardado del pedido. Abrir el pedido y dejarlo a
 * medias mientras se ajustan productos y precios es el flujo normal de la
 * comisión; avisar en el momento de crearlo mandaría a la asociación a un
 * catálogo vacío. Quien avisa decide cuándo está presentable.
 *
 * NO SE PUEDE AVISAR DOS VECES SIN QUERER. La protección es doble y cada mitad
 * hace un trabajo distinto: {@see ConsumerGroupRound::getAnnouncedAt()} es la
 * memoria visible (la pantalla dice cuándo se avisó y el botón cambia de texto),
 * y {@see EffectLedger} es la garantía técnica, con un apunte por persona que
 * sobrevive a un lote de correo cortado a la mitad. Repetir a conciencia se pide
 * con `$resend`, y sólo repite el correo: la bandeja dejaría dos filas idénticas
 * y el push gasta un canal que no se recupera —quien recibe dos veces el mismo
 * aviso lo apaga, y el permiso del navegador no se vuelve a pedir—.
 */
class ConsumerGroupAnnouncer
{
    /** Clase de efecto del correo de apertura, uno por socix y pedido. */
    private const EFFECT_EMAIL = 'consumer_group_open_email';

    /** Clase de efecto de la copia en la bandeja, una por pedido. */
    private const EFFECT_INBOX = 'consumer_group_open_inbox';

    /** Clase de efecto del aviso al móvil, uno por pedido. */
    private const EFFECT_PUSH = 'consumer_group_open_push';

    public function __construct(
        private readonly PartnerRepository $partners,
        private readonly UserRepository $users,
        private readonly NotificationInbox $inbox,
        private readonly NotificationLink $link,
        private readonly NotificationPreferences $preferences,
        private readonly PushSender $push,
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly EffectLedger $ledger,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly AppSettings $settings,
        private readonly PartnerAccessPolicy $accessPolicy,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Si este pedido se puede anunciar ahora mismo.
     *
     * Exige plazo abierto y catálogo: un aviso de un pedido cerrado manda a la
     * gente a una pantalla donde no puede hacer nada, y uno sin productos, a una
     * lista vacía. Las dos cosas gastan el aviso —que es de los que sólo se
     * mandan una vez— sin que nadie pueda pedir nada.
     *
     * @param ConsumerGroupRound $round el pedido colectivo
     */
    public function canAnnounce(ConsumerGroupRound $round): bool
    {
        return $round->canReceiveOrders() && \count($round->getItems()) > 0;
    }

    /**
     * A cuánta gente llegaría el aviso por cada vía, para enseñarlo ANTES de
     * mandarlo.
     *
     * EL NÚMERO DEL MÓVIL CUENTA NAVEGADORES DADOS DE ALTA, no cuentas que no lo
     * han silenciado. Son cifras muy distintas —hoy, 36 frente a 2— y la de
     * arriba invita a pensar que el móvil llega a mucha gente y que el correo
     * sobra. Quien va a mandar esto tiene derecho a ver lo que va a pasar de
     * verdad.
     *
     * @return array{total: int, email: int, push: int, emailEnabled: bool}
     *         socixs activxs, y de ellxs quiénes reciben por cada vía
     */
    public function audience(): array
    {
        $active = $this->partners->findActive();
        $emailEnabled = $this->settings->getBool(AppSettings::EMAIL_ENABLED);

        return [
            'total' => \count($active),
            'email' => $emailEnabled ? \count($this->emailRecipients()) : 0,
            'push' => $this->pushReach($active),
            'emailEnabled' => $emailEnabled,
        ];
    }

    /**
     * Cuántas PERSONAS recibirían el aviso en el móvil: las que no lo han
     * silenciado y además tienen al menos un navegador dado de alta.
     *
     * Se cuentan personas y no suscripciones: quien tiene el móvil y el portátil
     * dados de alta es una persona avisada, no dos.
     *
     * @param list<Partner> $active socixs activxs
     */
    private function pushReach(array $active): int
    {
        $users = $this->users->findByPartners($this->preferences->filter(
            $active,
            NotificationTopic::CONSUMER_GROUP,
            NotificationTopic::CHANNEL_PUSH,
        ));

        $reached = [];
        foreach ($this->subscriptions->findByUsers($users) as $subscription) {
            $reached[(int) $subscription->getUser()?->getId()] = true;
        }

        return \count($reached);
    }

    /**
     * Manda el aviso de que el pedido está abierto y deja constancia en el
     * pedido.
     *
     * @param ConsumerGroupRound $round  el pedido que se abre
     * @param bool               $resend repite el correo a quien ya constaba avisadx
     *
     * @return array{inbox: int, email: int, push: int} a cuánta gente llegó por cada vía
     */
    public function announce(ConsumerGroupRound $round, bool $resend = false): array
    {
        $active = $this->partners->findActive();

        $result = [
            'inbox' => $this->deliverInbox($round, $active),
            'email' => $this->deliverEmail($round, $resend),
            'push' => $this->deliverPush($round, $active),
        ];

        // AL FINAL, cuando ya se ha intentado todo: la marca es lo que la
        // comisión lee para saber si el aviso salió, y ponerla antes daría por
        // avisado un envío que pudo quedarse a medias.
        $round->setAnnouncedAt(new \DateTime());
        $this->entityManager->flush();

        return $result;
    }

    /**
     * La copia en la bandeja, a toda cuenta de socix activx.
     *
     * SIN CONSULTAR PREFERENCIAS: la bandeja es el suelo de los avisos
     * ({@see NotificationInbox}), no un canal más. Quien apagó el correo y el
     * móvil sigue encontrándolo al entrar, y es justo lo que hace aceptable que
     * los apague.
     *
     * @param ConsumerGroupRound $round  el pedido que se abre
     * @param list<Partner>      $active socixs activxs
     *
     * @return int cuántas copias se escribieron
     */
    private function deliverInbox(ConsumerGroupRound $round, array $active): int
    {
        $written = 0;

        // Una sola escritura para todo el lote: si se corta a mitad, el flush no
        // llega a la base y el reintento la repite entera sin duplicar nada.
        $this->onceOrLog(self::EFFECT_INBOX, (string) $round->getId(), $round, function () use ($round, $active, &$written): void {
            $written = $this->inbox->deliver(
                $this->users->findByPartners($active),
                Notification::KIND_CONSUMER_GROUP_OPEN,
                $this->headline($round),
                $this->body($round),
            );
        });

        return $written;
    }

    /**
     * El correo, uno por persona y con su propio apunte.
     *
     * El apunte va POR SOCIX porque es el único envío que se hace de uno en uno:
     * si el proceso se queda sin tiempo en el correo ochenta, el reintento
     * arranca en el ochenta y uno en vez de reescribir a los ochenta primeros.
     *
     * Uno por persona y no un envío con todo el mundo en copia: las direcciones
     * de lxs socixs no se enseñan unas a otras.
     *
     * @param ConsumerGroupRound $round  el pedido que se abre
     * @param bool               $resend repite a quien ya constaba avisadx
     *
     * @return int cuántos correos salieron en esta pasada
     */
    private function deliverEmail(ConsumerGroupRound $round, bool $resend): int
    {
        if (!$this->settings->getBool(AppSettings::EMAIL_ENABLED)) {
            return 0;
        }

        $url = $this->urls->generate(
            'panel_consumer_group_show',
            ['id' => $round->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        $preferencesUrl = $this->urls->generate('panel_notifications', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $sent = 0;
        foreach ($this->emailRecipients() as $partner) {
            $address = (string) $partner->getEmail();
            if ('' === $address) {
                continue;
            }

            $done = $this->onceOrLog(
                self::EFFECT_EMAIL,
                $round->getId() . ':' . $partner->getId(),
                $round,
                fn () => $this->mailer->send(
                    (new TemplatedEmail())
                        ->to($address)
                        ->subject($this->subject($round))
                        ->htmlTemplate('email/consumer_group_open.html.twig')
                        ->textTemplate('email/consumer_group_open.txt.twig')
                        ->context([
                            'partner' => $partner,
                            'round' => $round,
                            'url' => $url,
                            'preferences_url' => $preferencesUrl,
                            // Los enlaces exigen sesión: a quien no puede entrar
                            // no se le pinta un botón que sólo le llevaría al
                            // login. Mismo criterio que el recordatorio de la
                            // cesta y el aviso de voluntariado.
                            'can_act' => $this->accessPolicy->canUseActionLinks($partner),
                        ])
                ),
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
     * El aviso al móvil, en un solo lote.
     *
     * @param ConsumerGroupRound $round  el pedido que se abre
     * @param list<Partner>      $active socixs activxs
     *
     * @return int a cuántas cuentas llegó
     */
    private function deliverPush(ConsumerGroupRound $round, array $active): int
    {
        $recipients = $this->users->findByPartners($this->preferences->filter(
            $active,
            NotificationTopic::CONSUMER_GROUP,
            NotificationTopic::CHANNEL_PUSH,
        ));
        if ([] === $recipients) {
            return 0;
        }

        $sent = 0;
        $this->onceOrLog(self::EFFECT_PUSH, (string) $round->getId(), $round, function () use ($round, $recipients, &$sent): void {
            // El destino sale de NotificationLink, el MISMO sitio del que sale
            // el de la fila de la bandeja, para que no puedan llevar a pantallas
            // distintas.
            $sent = $this->push->sendToMany(
                $recipients,
                $this->headline($round),
                $this->body($round),
                $this->link->pathForKind(Notification::KIND_CONSUMER_GROUP_OPEN),
                'consumer_group',
            );
        });

        return $sent;
    }

    /**
     * Socixs activxs con correo que no han silenciado este tema.
     *
     * @return list<Partner>
     */
    private function emailRecipients(): array
    {
        return $this->preferences->filter(
            $this->partners->findActiveWithEmail(),
            NotificationTopic::CONSUMER_GROUP,
            NotificationTopic::CHANNEL_EMAIL,
        );
    }

    /**
     * Produce el efecto una sola vez y se traga el fallo.
     *
     * El ledger relanza la excepción para que quien llama decida; aquí la
     * decisión es siempre la misma: un correo que falla no puede abortar el
     * lote. Son más de cien direcciones, y una caída del SMTP a mitad dejaría a
     * media asociación sin enterarse de que hay pedido. El apunte ya se ha
     * retirado, así que un reenvío posterior lo recoge.
     *
     * La fecha de negocio del apunte es la de CREACIÓN DEL PEDIDO y no la de
     * hoy: forma parte de la clave, así que con la de hoy el mismo aviso del
     * mismo pedido se podría repetir entero al día siguiente, que es justo lo
     * que esto tiene que impedir.
     *
     * @param string             $kind      clase de efecto
     * @param string             $reference referencia única del efecto
     * @param ConsumerGroupRound $round     el pedido, de donde sale la fecha de negocio
     * @param callable           $effect    lo que hay que hacer
     * @param string|null        $target    destino, para el registro
     * @param bool               $resend    lo produce aunque ya constara emitido
     *
     * @return bool true si el efecto se produjo en esta pasada
     */
    private function onceOrLog(
        string $kind,
        string $reference,
        ConsumerGroupRound $round,
        callable $effect,
        ?string $target = null,
        bool $resend = false,
    ): bool {
        try {
            return $this->ledger->once(
                $kind,
                $reference,
                $round->getCreated() ?? new \DateTimeImmutable('today'),
                $effect,
                $target,
                $resend,
            );
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo entregar el aviso de pedido abierto', [
                'round' => $round->getId(),
                'kind' => $kind,
                'reference' => $reference,
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * El asunto del correo. Lleva el título del pedido: un asunto genérico
     * («novedades del grupo de consumo») no dice si es el aceite o la fruta, que
     * es lo único que decide si se abre o no.
     */
    private function subject(ConsumerGroupRound $round): string
    {
        return sprintf('Pedido abierto: %s', $round->getTitle());
    }

    /**
     * El titular de la bandeja y del móvil.
     */
    private function headline(ConsumerGroupRound $round): string
    {
        return sprintf('Nuevo pedido: %s', $round->getTitle());
    }

    /**
     * El cuerpo corto, el de la bandeja y el móvil.
     *
     * Lleva la FECHA DE CIERRE y no la de entrega: es la única que obliga a
     * hacer algo, y una notificación del sistema se recorta a dos líneas —lo que
     * sobra no se lee—. La entrega ya la cuenta el correo y la pantalla.
     */
    private function body(ConsumerGroupRound $round): string
    {
        $closes = $round->getOrdersCloseAt();

        return null === $closes
            ? 'Ya puedes apuntarte.'
            : sprintf('Puedes apuntarte hasta el %s.', $closes->format('j/n'));
    }
}
