<?php

namespace App\Service\Survey;

use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\Survey;
use App\Repository\PartnerRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UserRepository;
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
 * Cuenta a la asociación que hay una encuesta abierta.
 *
 * SIN ESTO LA ENCUESTA NO LA RESPONDE NADIE. Antes, abrirla sólo la ponía en
 * un menú del panel que casi nadie abre —y que la mayoría de socias ni
 * siquiera puede abrir, porque no tiene cuenta—. El aviso es la mitad que hace
 * funcionar el módulo, igual que en el grupo de consumo.
 *
 * EL CORREO ES EL CANAL QUE LLEGA, y por eso lleva un enlace personal firmado
 * ({@see SurveyLink}) con el que se responde sin entrar en la web. La bandeja y
 * el móvil son para quien ya usa el panel.
 *
 * VA A TODA SOCIA ACTIVA. No hay a quién suscribir: lo que hay es quien no
 * quiere enterarse, y eso lo resuelve la pantalla de avisos
 * ({@see NotificationTopic::SURVEYS}).
 *
 * SE DISPARA SOLO AL ABRIR la encuesta. No se puede avisar dos veces sin
 * querer: {@see Survey::getAnnouncedAt()} es la memoria visible y
 * {@see EffectLedger} la garantía técnica, con un apunte por persona. Volver a
 * llamar a {@see announce()} sólo completa a quien se quedó sin correo en un
 * envío cortado; no repite a nadie. El único reenvío es a UNA socia que lo
 * pide ({@see resendTo()}): el aviso a toda la asociación no se repite.
 *
 * Mismo esquema que {@see \App\Service\ConsumerGroup\ConsumerGroupAnnouncer} y
 * {@see \App\Service\News\NewsAnnouncer}, con la diferencia del enlace
 * personal en el correo.
 */
class SurveyAnnouncer
{
    /** Clase de efecto del correo, uno por socia y encuesta. */
    private const EFFECT_EMAIL = 'survey_open_email';

    /** Clase de efecto de la copia en la bandeja, una por encuesta. */
    private const EFFECT_INBOX = 'survey_open_inbox';

    /** Clase de efecto del aviso al móvil, uno por encuesta. */
    private const EFFECT_PUSH = 'survey_open_push';

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
        private readonly SurveyLink $surveyLink,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * A cuánta gente llegaría el aviso por cada vía, para enseñarlo ANTES de
     * abrir la encuesta.
     *
     * El número del móvil cuenta PERSONAS con un navegador dado de alta, no
     * cuentas que no lo han silenciado: son cifras muy distintas, y la grande
     * haría pensar que el móvil llega a mucha gente.
     *
     * @return array{total: int, email: int, push: int, emailEnabled: bool}
     *         socias activas, y de ellas quiénes reciben por cada vía
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
     * Manda el aviso de que la encuesta está abierta y deja constancia en ella.
     *
     * @param Survey $survey la encuesta que se abre
     *
     * @return array{inbox: int, email: int, push: int} a cuánta gente llegó en esta pasada por cada vía
     */
    public function announce(Survey $survey): array
    {
        $active = $this->partners->findActive();

        $result = [
            'inbox' => $this->deliverInbox($survey, $active),
            'email' => $this->deliverEmail($survey),
            'push' => $this->deliverPush($survey, $active),
        ];

        // AL FINAL, cuando ya se ha intentado todo: ponerla antes daría por
        // avisado un envío que pudo quedarse a medias.
        $survey->setAnnouncedAt(new \DateTime());
        $this->entityManager->flush();

        return $result;
    }

    /**
     * La copia en la bandeja, a toda cuenta de socia activa, SIN consultar
     * preferencias: la bandeja es el suelo de los avisos, no un canal más.
     *
     * @param Survey        $survey la encuesta
     * @param list<Partner> $active socias activas
     *
     * @return int cuántas copias se escribieron
     */
    private function deliverInbox(Survey $survey, array $active): int
    {
        $written = 0;

        $this->onceOrLog(self::EFFECT_INBOX, (string) $survey->getId(), $survey, function () use ($survey, $active, &$written): void {
            $written = $this->inbox->deliver(
                $this->users->findByPartners($active),
                Notification::KIND_SURVEY_OPEN,
                $this->headline($survey),
                $this->body($survey),
            );
        });

        return $written;
    }

    /**
     * El correo, uno por persona, con su enlace personal y su propio apunte:
     * si el proceso se corta en el correo ochenta, el reintento arranca en el
     * ochenta y uno. Uno por persona y no en copia: las direcciones no se
     * enseñan unas a otras, y cada enlace es de quien lo recibe.
     *
     * @param Survey $survey la encuesta
     *
     * @return int cuántos correos salieron en esta pasada
     */
    private function deliverEmail(Survey $survey): int
    {
        if (!$this->settings->getBool(AppSettings::EMAIL_ENABLED)) {
            return 0;
        }

        $sent = 0;
        foreach ($this->emailRecipients() as $partner) {
            if ($this->sendEmailTo($survey, $partner, false)) {
                ++$sent;
            }
        }

        return $sent;
    }

    /**
     * Reenvía el correo, con su enlace personal, a UNA socia concreta.
     *
     * Es la respuesta a «no me llegó» o «lo borré sin querer», que es el único
     * reenvío que tiene sentido: el aviso general no se repite nunca a nadie.
     * Sale aunque ella hubiera silenciado el tema, porque lo pide ella a través
     * del equipo. Queda apuntado en el registro de envíos como cualquier otro.
     *
     * @param Survey  $survey  la encuesta
     * @param Partner $partner la socia
     *
     * @return bool true si el correo salió; false con el correo apagado, sin
     *              dirección o si el envío falló (queda en el log)
     */
    public function resendTo(Survey $survey, Partner $partner): bool
    {
        if (!$this->settings->getBool(AppSettings::EMAIL_ENABLED)) {
            return false;
        }

        return $this->sendEmailTo($survey, $partner, true);
    }

    /**
     * El correo de una socia, con su propio apunte en el registro de efectos.
     *
     * @param Survey  $survey  la encuesta
     * @param Partner $partner la destinataria
     * @param bool    $resend  sale aunque ya constara enviado
     *
     * @return bool true si salió en esta pasada
     */
    private function sendEmailTo(Survey $survey, Partner $partner, bool $resend): bool
    {
        $address = trim((string) $partner->getEmail());
        if ('' === $address) {
            return false;
        }

        return $this->onceOrLog(
            self::EFFECT_EMAIL,
            $survey->getId() . ':' . $partner->getId(),
            $survey,
            fn () => $this->mailer->send(
                (new TemplatedEmail())
                    ->to($address)
                    ->subject($this->subject($survey))
                    ->htmlTemplate('email/survey_open.html.twig')
                    ->textTemplate('email/survey_open.txt.twig')
                    ->context([
                        'partner' => $partner,
                        'survey' => $survey,
                        'url' => $this->surveyLink->forPartner($survey, $partner),
                        'preferences_url' => $this->urls->generate('panel_notifications', [], UrlGeneratorInterface::ABSOLUTE_URL),
                    ])
            ),
            $address,
            $resend,
        );
    }

    /**
     * El aviso al móvil, en un solo lote.
     *
     * @param Survey        $survey la encuesta
     * @param list<Partner> $active socias activas
     *
     * @return int a cuántas cuentas llegó
     */
    private function deliverPush(Survey $survey, array $active): int
    {
        $recipients = $this->users->findByPartners($this->preferences->filter(
            $active,
            NotificationTopic::SURVEYS,
            NotificationTopic::CHANNEL_PUSH,
        ));
        if ([] === $recipients) {
            return 0;
        }

        $sent = 0;
        $this->onceOrLog(self::EFFECT_PUSH, (string) $survey->getId(), $survey, function () use ($survey, $recipients, &$sent): void {
            // El destino sale de NotificationLink, el mismo del que sale el de la
            // bandeja, para que no puedan llevar a pantallas distintas.
            $sent = $this->push->sendToMany(
                $recipients,
                $this->headline($survey),
                $this->body($survey),
                $this->link->pathForKind(Notification::KIND_SURVEY_OPEN),
                'survey',
            );
        });

        return $sent;
    }

    /**
     * Cuántas PERSONAS lo recibirían en el móvil: las que no lo han silenciado
     * y tienen al menos un navegador dado de alta.
     *
     * @param list<Partner> $active socias activas
     */
    private function pushReach(array $active): int
    {
        $users = $this->users->findByPartners($this->preferences->filter(
            $active,
            NotificationTopic::SURVEYS,
            NotificationTopic::CHANNEL_PUSH,
        ));

        $reached = [];
        foreach ($this->subscriptions->findByUsers($users) as $subscription) {
            $reached[(int) $subscription->getUser()?->getId()] = true;
        }

        return \count($reached);
    }

    /**
     * Socias activas con correo que no han silenciado este tema.
     *
     * @return list<Partner>
     */
    private function emailRecipients(): array
    {
        return $this->preferences->filter(
            $this->partners->findActiveWithEmail(),
            NotificationTopic::SURVEYS,
            NotificationTopic::CHANNEL_EMAIL,
        );
    }

    /**
     * Produce el efecto una sola vez y se traga el fallo: un correo que falla no
     * puede dejar a media asociación sin aviso. El apunte ya se ha retirado, así
     * que el reintento lo recoge.
     *
     * La fecha de negocio del apunte es la de CREACIÓN DE LA ENCUESTA y no la de
     * hoy: forma parte de la clave, y con la de hoy el mismo aviso se podría
     * repetir entero al día siguiente.
     *
     * @param string      $kind      clase de efecto
     * @param string      $reference referencia única del efecto
     * @param Survey      $survey    la encuesta, de donde sale la fecha de negocio
     * @param callable    $effect    lo que hay que hacer
     * @param string|null $target    destino, para el registro
     * @param bool        $resend    lo produce aunque ya constara emitido
     *
     * @return bool true si el efecto se produjo en esta pasada
     */
    private function onceOrLog(string $kind, string $reference, Survey $survey, callable $effect, ?string $target = null, bool $resend = false): bool
    {
        try {
            return $this->ledger->once($kind, $reference, $survey->getCreatedAt(), $effect, $target, $resend);
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo entregar el aviso de encuesta abierta', [
                'survey' => $survey->getId(),
                'kind' => $kind,
                'reference' => $reference,
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * El asunto del correo. Lleva el título: es lo único que decide si se abre.
     */
    private function subject(Survey $survey): string
    {
        return sprintf('La CSA te pregunta: %s', $survey->getTitle());
    }

    /**
     * El titular de la bandeja y del móvil.
     */
    private function headline(Survey $survey): string
    {
        return sprintf('Nueva encuesta: %s', $survey->getTitle());
    }

    /**
     * El cuerpo corto de la bandeja y del móvil. Lleva el plazo si lo hay, que
     * es lo único que obliga a algo; una notificación se recorta a dos líneas.
     */
    private function body(Survey $survey): string
    {
        $closes = $survey->getClosesAt();

        return null === $closes
            ? 'Ya puedes responder. Es anónima.'
            : sprintf('Puedes responder hasta el %s. Es anónima.', $closes->format('j/n'));
    }
}
