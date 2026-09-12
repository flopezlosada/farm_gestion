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
 * SE ANUNCIA UNA TANDA, NO UNA NOVEDAD. Todo lo que está sin anunciar sale en
 * un único correo y un único aviso. Tres correos seguidos por tres cosas
 * pequeñas es exactamente lo que enseña a la gente a archivar sin leer lo que
 * mandamos, y aquí el contenido nunca es urgente: puede esperar a que haya algo
 * que contar.
 *
 * EL CORREO SE BASTA SOLO, y es la decisión que ordena todo lo demás. De lxs
 * socixs activxs, la gran mayoría tiene dirección de correo y sólo una minoría
 * tiene cuenta para entrar: un correo que dijera "entra en la web a ver las
 * novedades" sería, para casi todo el mundo, una puerta cerrada. Así que el
 * texto completo viaja en el correo, y el enlace es un extra para quien puede
 * usarlo.
 *
 * LO ANUNCIADO SE MARCA ANTES DE ENVIAR, misma regla que
 * {@see \App\Service\Volunteering\VolunteerCallNotifier}: si un fallo a mitad
 * del lote dejara el puntero sin mover, el siguiente intento volvería a escribir
 * a quien ya lo había recibido. Entre perder un anuncio y mandarlo dos veces,
 * se prefiere perderlo — y no se pierde gran cosa, porque las novedades siguen
 * en la web y el correo se puede volver a lanzar bajando el puntero a mano.
 *
 * NO HAY INTERRUPTOR PROPIO en {@see AppSettings}, a diferencia de los avisos
 * automáticos. Aquí el interruptor es el botón: nadie manda esto sin querer.
 * Lo que sí se respeta es el corte general del correo ({@see AppSettings::EMAIL_ENABLED})
 * y lo que cada socix haya dicho en su pantalla de avisos.
 */
class NewsAnnouncer
{
    /**
     * Hasta qué novedad se ha anunciado ya. Vive en la tabla de ajustes pero NO
     * en el catálogo de {@see AppSettings}, y es a propósito: no es algo que
     * administración deba tocar en la pantalla de configuración, es el estado
     * interno del módulo. Ponerlo allí sería ofrecer un campo de texto capaz de
     * reenviar un anuncio a toda la asociación por una errata.
     */
    private const POINTER = 'news.announced_through';

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
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly PartnerAccessPolicy $accessPolicy,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * El id de la última novedad anunciada, o 0 si nunca se ha anunciado nada.
     */
    public function announcedThrough(): int
    {
        return (int) ($this->settings->findOneBy(['name' => self::POINTER])?->getValue() ?? 0);
    }

    /**
     * Lo que está escrito y todavía no se ha contado.
     *
     * @return list<NewsEntry> de la más reciente a la más antigua
     */
    public function pending(): array
    {
        return $this->catalog->since($this->announcedThrough());
    }

    /**
     * Lo ya anunciado, que es lo que se enseña en la web.
     *
     * @return list<NewsEntry> de la más reciente a la más antigua
     */
    public function published(): array
    {
        return $this->catalog->through($this->announcedThrough());
    }

    /**
     * Cuenta las novedades pendientes a quien toca y mueve el puntero.
     *
     * TRES VÍAS, Y CADA UNA LLEGA A GENTE DISTINTA. El correo es el que alcanza
     * a casi toda la asociación; la bandeja, sólo a quien tiene cuenta; el push,
     * sólo a quien además ha activado los avisos en algún navegador. Por eso el
     * correo se basta solo y las otras dos son un empujón para que quien usa la
     * web se entere antes.
     *
     * @return array{entries: list<NewsEntry>, inbox: int, email: int, push: int}
     *         qué se contó y a cuánta gente por cada vía
     */
    public function announce(): array
    {
        $entries = $this->pending();
        if ([] === $entries) {
            return ['entries' => [], 'inbox' => 0, 'email' => 0, 'push' => 0];
        }

        $this->movePointer($this->catalog->latestId());

        $active = $this->partners->findActive();

        // La copia en la bandeja va a toda cuenta de socix activx SIN consultar
        // preferencias: la bandeja es el suelo de los avisos, no un canal más
        // ({@see NotificationInbox}). Quien apagó el correo y el móvil sigue
        // encontrándolo al entrar, y por eso apagarlos se le puede permitir.
        $inbox = $this->inbox->deliver(
            $this->users->findByPartners($active),
            Notification::KIND_NEWS,
            $this->inboxTitle($entries),
            $this->inboxBody($entries),
        );

        $email = $this->emailEnabled()
            ? $this->email($entries, $this->preferences->filter(
                $this->partners->findActiveWithEmail(),
                NotificationTopic::NEWS,
                NotificationTopic::CHANNEL_EMAIL,
            ))
            : 0;

        // Al móvil sólo el titular: un push no es sitio para contar nada, es
        // para que quien ya usa la web sepa que hay algo y entre a leerlo. El
        // destino sale de NotificationLink, el MISMO sitio del que sale el de la
        // fila de la bandeja, para que no puedan llevar a pantallas distintas.
        $push = $this->push->sendToMany(
            $this->users->findByPartners($this->preferences->filter(
                $active,
                NotificationTopic::NEWS,
                NotificationTopic::CHANNEL_PUSH,
            )),
            $this->inboxTitle($entries),
            $this->pushBody($entries),
            $this->link->pathForKind(Notification::KIND_NEWS),
            'news',
        );

        return ['entries' => $entries, 'inbox' => $inbox, 'email' => $email, 'push' => $push];
    }

    /**
     * Guarda hasta dónde se ha anunciado.
     *
     * @param int $id id de la última novedad contada
     */
    private function movePointer(int $id): void
    {
        $setting = $this->settings->findOneBy(['name' => self::POINTER]) ?? (new Setting())->setName(self::POINTER);
        $setting->setValue((string) $id);

        $this->entityManager->persist($setting);
        $this->entityManager->flush();
    }

    /**
     * Manda el correo, uno por persona.
     *
     * Uno por persona y no un envío con todo el mundo en copia: las direcciones
     * de lxs socixs no se enseñan unas a otras.
     *
     * @param list<NewsEntry> $entries  lo que se cuenta
     * @param list<Partner>   $partners a quiénes
     *
     * @return int cuántos correos salieron
     */
    private function email(array $entries, array $partners): int
    {
        if ([] === $partners) {
            return 0;
        }

        $url = $this->urlGenerator->generate('news_index', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $preferencesUrl = $this->urlGenerator->generate('panel_notifications', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $subject = $this->subject($entries);

        $sent = 0;
        foreach ($partners as $partner) {
            $address = $partner->getEmail();
            if (!$address) {
                continue;
            }

            try {
                $this->mailer->send(
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
                );
                ++$sent;
            } catch (\Throwable $e) {
                // Un correo que falla no aborta la tanda: son doscientas
                // direcciones y una caída de SMTP a mitad dejaría a media
                // asociación sin enterarse de nada.
                $this->logger->error('No se pudo enviar el correo de novedades', [
                    'partner' => $partner->getId(),
                    'exception' => $e,
                ]);
            }
        }

        return $sent;
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
     * El titular del aviso de la bandeja.
     *
     * @param list<NewsEntry> $entries lo que se cuenta
     */
    private function inboxTitle(array $entries): string
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

    /**
     * Si el correo saliente está permitido. El tema no tiene interruptor propio
     * —el botón lo es—, pero el corte general manda sobre todo.
     */
    private function emailEnabled(): bool
    {
        return $this->appSettings->getBool(AppSettings::EMAIL_ENABLED);
    }
}
