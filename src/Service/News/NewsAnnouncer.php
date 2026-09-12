<?php

namespace App\Service\News;

use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\Setting;
use App\Repository\PartnerRepository;
use App\Repository\SettingRepository;
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
 * Cuenta a lxs socixs lo que hay de nuevo en la web.
 *
 * PEDIRLO Y MANDARLO SON DOS MOMENTOS, y esto es lo que más condiciona el
 * diseño. Quien administra pulsa un botón y la petición web termina ahí: lo
 * único que se guarda es hasta qué novedad se quiere contar. El envío lo hace
 * después el planificador ({@see \App\Command\AnnounceNewsCommand}).
 *
 * No es ceremonia: son ~130 direcciones, el mayor envío del sistema, y cada
 * correo es una transacción SMTP. Lanzarlo dentro del POST significa que en un
 * hosting compartido el proceso se muere a mitad de lote por tiempo de
 * ejecución; quien pulsó ve un error y no hay forma de saber a quién le llegó.
 * {@see \App\Service\Push\PushSender} ya lo deja escrito para el push: un aviso
 * masivo no se lanza desde una petición web.
 *
 * DOS PUNTEROS Y NO UNO. `news.requested_through` es lo que se ha pedido contar;
 * `news.announced_through`, lo que ya salió. Entre uno y otro está la tanda en
 * camino. Con un solo puntero no habría forma de distinguir "pedido pero aún sin
 * mandar" de "mandado", y al reanudar tras un corte no se sabría si volver a
 * intentarlo.
 *
 * CADA CORREO SE APUNTA POR SEPARADO en {@see EffectLedger}, con la tanda y el
 * socix en la referencia. Es lo que hace que un corte a mitad no sea un
 * problema: el tick siguiente reintenta la tanda entera y el ledger se salta a
 * quien ya recibió. Por eso —y a diferencia de un envío de un solo golpe— el
 * puntero de lo anunciado se mueve AL FINAL: moverlo antes daría la tanda por
 * contada sin saber a cuánta gente llegó.
 *
 * EL CORREO SE BASTA SOLO. De lxs socixs activxs, la gran mayoría tiene
 * dirección y sólo una minoría tiene cuenta para entrar: un correo que dijera
 * "entra en la web a ver las novedades" sería, para casi todo el mundo, una
 * puerta cerrada. El texto completo viaja en el correo y el enlace es un extra
 * para quien puede usarlo.
 *
 * NO HAY INTERRUPTOR PROPIO en {@see AppSettings} para el correo, a diferencia
 * de los avisos automáticos: aquí el interruptor es el botón, nadie manda esto
 * sin querer. Sí se respetan el corte general del correo
 * ({@see AppSettings::EMAIL_ENABLED}) y lo que cada socix haya dicho en su
 * pantalla de avisos.
 */
class NewsAnnouncer
{
    /**
     * Hasta qué novedad se ha PEDIDO contar. La escribe el botón de
     * administración.
     *
     * Vive en la tabla de ajustes pero NO en el catálogo de {@see AppSettings},
     * y es a propósito: no es algo que se deba tocar en la pantalla de
     * configuración, es el estado interno del módulo. Allí sería un campo de
     * texto capaz de relanzar un anuncio a toda la asociación por una errata.
     */
    private const REQUESTED = 'news.requested_through';

    /** Hasta qué novedad se ha contado ya de verdad. La escribe el planificador. */
    private const ANNOUNCED = 'news.announced_through';

    /** Clase de efecto del correo de novedades, una por socix y tanda. */
    private const EFFECT_EMAIL = 'news_email';

    /** Clase de efecto de la copia en la bandeja, una por tanda. */
    private const EFFECT_INBOX = 'news_inbox';

    /** Clase de efecto del aviso al móvil, una por tanda. */
    private const EFFECT_PUSH = 'news_push';

    public function __construct(
        private readonly NewsCatalog $catalog,
        private readonly SettingRepository $settings,
        private readonly AppSettings $appSettings,
        private readonly PartnerRepository $partners,
        private readonly UserRepository $users,
        private readonly NotificationInbox $inbox,
        private readonly NotificationLink $link,
        private readonly NotificationPreferences $preferences,
        private readonly PushSender $push,
        private readonly EffectLedger $ledger,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly PartnerAccessPolicy $accessPolicy,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * El id de la última novedad que se ha pedido contar, 0 si ninguna.
     */
    public function requestedThrough(): int
    {
        return $this->pointer(self::REQUESTED);
    }

    /**
     * El id de la última novedad ya contada, 0 si ninguna.
     */
    public function announcedThrough(): int
    {
        return $this->pointer(self::ANNOUNCED);
    }

    /**
     * Lo escrito que todavía no se ha pedido contar: lo que hay que revisar
     * antes de darle al botón.
     *
     * @return list<NewsEntry> de la más reciente a la más antigua
     */
    public function pending(): array
    {
        return $this->catalog->since($this->requestedThrough());
    }

    /**
     * Lo pedido que aún no ha salido: la tanda esperando al planificador.
     *
     * @return list<NewsEntry> de la más reciente a la más antigua
     */
    public function inFlight(): array
    {
        $announced = $this->announcedThrough();
        $requested = $this->requestedThrough();

        return array_values(array_filter(
            $this->catalog->all(),
            static fn (NewsEntry $entry): bool => $entry->id > $announced && $entry->id <= $requested
        ));
    }

    /**
     * Lo ya contado, que es lo que se enseña en la web.
     *
     * Lo pedido pero aún sin mandar NO se publica: si apareciera en la página
     * antes de que saliera el aviso, quien pasara por ahí lo leería antes de que
     * se lo contaran, y el aviso llegaría a hablar de algo ya visto.
     *
     * @return list<NewsEntry> de la más reciente a la más antigua
     */
    public function published(): array
    {
        return $this->catalog->through($this->announcedThrough());
    }

    /**
     * Pide que se cuente todo lo escrito. Es lo que hace el botón.
     *
     * NO ENVÍA NADA y devuelve en el acto. Es idempotente por construcción: dos
     * pulsaciones seguidas escriben el mismo número, así que un doble clic o dos
     * pestañas abiertas no pueden provocar dos anuncios.
     *
     * @return list<NewsEntry> lo que quedará en camino tras la petición
     */
    public function request(): array
    {
        $latest = $this->catalog->latestId();
        if ($latest > $this->requestedThrough()) {
            $this->movePointer(self::REQUESTED, $latest);
        }

        return $this->inFlight();
    }

    /**
     * Manda la tanda pedida. Es lo que hace el planificador.
     *
     * EL REENVÍO ES SÓLO DEL CORREO, y no por descuido. Repetir la copia de la
     * bandeja deja dos filas idénticas esperando a la misma persona, y repetir
     * el push gasta un canal que no se recupera: quien recibe dos veces el mismo
     * aviso del móvil lo apaga, y el permiso del navegador no se puede volver a
     * pedir. Lo que se rescata con esto es el caso real —un lote de correo que
     * se cortó a mitad— sin pagar por los otros dos.
     *
     * Para repetir una tanda YA CERRADA hay que bajar a mano el puntero
     * `news.announced_through`: aquí sólo se reintenta lo que sigue en camino.
     *
     * @param bool $resend repite el correo a quien ya constaba avisadx
     *
     * @return array{entries: list<NewsEntry>, inbox: int, email: int, push: int}
     *         qué se contó y a cuánta gente por cada vía
     */
    public function deliver(bool $resend = false): array
    {
        $entries = $this->inFlight();
        if ([] === $entries) {
            return ['entries' => [], 'inbox' => 0, 'email' => 0, 'push' => 0];
        }

        $batch = $this->requestedThrough();
        $active = $this->partners->findActive();

        $inbox = $this->deliverInbox($entries, $batch, $active);
        $email = $this->deliverEmail($entries, $batch, $resend);
        $push = $this->deliverPush($entries, $batch, $active);

        // AL FINAL, cuando ya se ha intentado todo: si el proceso muere antes de
        // llegar aquí, el tick siguiente reintenta la tanda y el ledger se salta
        // a quien ya recibió. Moverlo antes daría por contada una tanda que
        // pudo quedarse en la mitad de la lista.
        $this->movePointer(self::ANNOUNCED, $batch);

        return ['entries' => $entries, 'inbox' => $inbox, 'email' => $email, 'push' => $push];
    }

    /**
     * La copia en la bandeja, a toda cuenta de socix activx.
     *
     * SIN CONSULTAR PREFERENCIAS: la bandeja es el suelo de los avisos, no un
     * canal más ({@see NotificationInbox}). Quien apagó el correo y el móvil
     * sigue encontrándolo al entrar, y es justo lo que permite que apagarlos sea
     * aceptable.
     *
     * @param list<NewsEntry> $entries lo que se cuenta
     * @param int             $batch   id de la tanda, para el apunte
     * @param list<Partner>   $active  socixs activxs
     *
     * @return int cuántas copias se escribieron
     */
    private function deliverInbox(array $entries, int $batch, array $active): int
    {
        $written = 0;

        // Una sola escritura para toda la tanda: si se corta a mitad, el flush
        // no llega a la base y el reintento la repite entera sin duplicar nada.
        $this->onceOrLog(self::EFFECT_INBOX, (string) $batch, function () use ($entries, $active, &$written): void {
            $written = $this->inbox->deliver(
                $this->users->findByPartners($active),
                Notification::KIND_NEWS,
                $this->headline($entries),
                $this->inboxBody($entries),
            );
        });

        return $written;
    }

    /**
     * El correo, uno por persona y con su propio apunte.
     *
     * El apunte va POR SOCIX y no por tanda porque es el único envío que se hace
     * de uno en uno: si el proceso se queda sin tiempo en el correo ochenta, el
     * tick siguiente arranca en el ochenta y uno en vez de volver a escribir a
     * los ochenta primeros.
     *
     * Uno por persona y no un envío con todo el mundo en copia: las direcciones
     * de lxs socixs no se enseñan unas a otras.
     *
     * @param list<NewsEntry> $entries lo que se cuenta
     * @param int             $batch   id de la tanda, para el apunte
     * @param bool            $resend  repite a quien ya constaba avisadx
     *
     * @return int cuántos correos salieron en esta pasada
     */
    private function deliverEmail(array $entries, int $batch, bool $resend): int
    {
        if (!$this->appSettings->getBool(AppSettings::EMAIL_ENABLED)) {
            return 0;
        }

        $partners = $this->preferences->filter(
            $this->partners->findActiveWithEmail(),
            NotificationTopic::NEWS,
            NotificationTopic::CHANNEL_EMAIL,
        );
        if ([] === $partners) {
            return 0;
        }

        $url = $this->urlGenerator->generate('news_index', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $preferencesUrl = $this->urlGenerator->generate('panel_notifications', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $subject = $this->subject($entries);

        $sent = 0;
        foreach ($partners as $partner) {
            $address = $partner->getemail();
            if (!$address) {
                continue;
            }

            $done = $this->onceOrLog(
                self::EFFECT_EMAIL,
                $batch . ':' . $partner->getId(),
                fn () => $this->mailer->send(
                    (new TemplatedEmail())
                        ->to($address)
                        ->subject($subject)
                        ->htmlTemplate('email/news.html.twig')
                        ->textTemplate('email/news.txt.twig')
                        ->context([
                            'entries' => $entries,
                            'partner' => $partner,
                            'url' => $url,
                            'preferences_url' => $preferencesUrl,
                            // Los enlaces exigen sesión: a quien no puede entrar
                            // no se le pinta un botón que sólo le llevaría a una
                            // pantalla de login. Mismo criterio que el aviso de
                            // voluntariado y el recordatorio de la cesta.
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
     * @param list<NewsEntry> $entries lo que se cuenta
     * @param int             $batch   id de la tanda, para el apunte
     * @param list<Partner>   $active  socixs activxs
     *
     * @return int a cuántas cuentas llegó
     */
    private function deliverPush(array $entries, int $batch, array $active): int
    {
        $recipients = $this->users->findByPartners($this->preferences->filter(
            $active,
            NotificationTopic::NEWS,
            NotificationTopic::CHANNEL_PUSH,
        ));
        if ([] === $recipients) {
            return 0;
        }

        $sent = 0;
        $this->onceOrLog(self::EFFECT_PUSH, (string) $batch, function () use ($entries, $recipients, &$sent): void {
            // Al móvil sólo el titular: un push no es sitio para contar nada, es
            // para que quien ya usa la web sepa que hay algo y entre a leerlo. El
            // destino sale de NotificationLink, el MISMO sitio del que sale el de
            // la fila de la bandeja, para que no puedan llevar a pantallas
            // distintas.
            $sent = $this->push->sendToMany(
                $recipients,
                $this->headline($entries),
                $this->pushBody($entries),
                $this->link->pathForKind(Notification::KIND_NEWS),
                'news',
            );
        });

        return $sent;
    }

    /**
     * Produce el efecto una sola vez y se traga el fallo.
     *
     * El ledger relanza la excepción para que quien llama decida; aquí la
     * decisión es siempre la misma: un correo que falla no puede abortar la
     * tanda. Son ~130 direcciones y una caída de SMTP a mitad dejaría a media
     * asociación sin enterarse. El apunte ya se ha retirado, así que el tick
     * siguiente lo reintenta.
     *
     * @param string      $kind      clase de efecto
     * @param string      $reference referencia única del efecto
     * @param callable    $effect    lo que hay que hacer
     * @param string|null $target    destino, para el registro
     * @param bool        $resend    lo produce aunque ya constara emitido
     *
     * @return bool true si el efecto se produjo en esta pasada
     */
    private function onceOrLog(string $kind, string $reference, callable $effect, ?string $target = null, bool $resend = false): bool
    {
        try {
            return $this->ledger->once($kind, $reference, new \DateTimeImmutable('today'), $effect, $target, $resend);
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo entregar el aviso de novedades', [
                'kind' => $kind,
                'reference' => $reference,
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * El valor de un puntero, 0 si todavía no tiene fila.
     */
    private function pointer(string $name): int
    {
        return (int) ($this->settings->findOneBy(['name' => $name])?->getValue() ?? 0);
    }

    /**
     * Guarda un puntero.
     */
    private function movePointer(string $name, int $id): void
    {
        $setting = $this->settings->findOneBy(['name' => $name]) ?? (new Setting())->setName($name);
        $setting->setValue((string) $id);

        $this->entityManager->persist($setting);
        $this->entityManager->flush();
    }

    /**
     * El asunto del correo.
     *
     * Con una sola novedad lleva su título: un asunto genérico no dice nada, y
     * el título ya está escrito para que se entienda de un vistazo. Con varias
     * no se puede elegir una, así que se dice cuántas son.
     *
     * @param list<NewsEntry> $entries lo que se cuenta
     */
    private function subject(array $entries): string
    {
        return 1 === \count($entries)
            ? $entries[0]->title
            : sprintf('%d novedades en la web de la CSA', \count($entries));
    }

    /**
     * El titular del aviso de la bandeja y del móvil.
     *
     * @param list<NewsEntry> $entries lo que se cuenta
     */
    private function headline(array $entries): string
    {
        return 1 === \count($entries)
            ? $entries[0]->title
            : sprintf('%d novedades en la web', \count($entries));
    }

    /**
     * El cuerpo del aviso de la bandeja: los titulares, para que se vea qué hay
     * sin tener que abrir la página.
     *
     * @param list<NewsEntry> $entries lo que se cuenta
     */
    private function inboxBody(array $entries): string
    {
        if (1 === \count($entries)) {
            return $entries[0]->text;
        }

        return implode("\n", array_map(
            static fn (NewsEntry $entry): string => '· ' . $entry->title,
            $entries
        ));
    }

    /**
     * El cuerpo del aviso del móvil.
     *
     * Más corto que el de la bandeja a propósito: una notificación del sistema
     * se recorta a dos líneas y lo que sobra no se lee. Con una sola novedad no
     * lleva cuerpo —el titular ya lo dice todo y repetirlo es ruido—; con
     * varias, invita a entrar.
     *
     * @param list<NewsEntry> $entries lo que se cuenta
     */
    private function pushBody(array $entries): ?string
    {
        return 1 === \count($entries) ? null : 'Entra a verlas.';
    }
}
