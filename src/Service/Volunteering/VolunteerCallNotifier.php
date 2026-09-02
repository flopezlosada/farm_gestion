<?php

namespace App\Service\Volunteering;

use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\User;
use App\Entity\VolunteerCall;
use App\Entity\VolunteerShift;
use App\Repository\UserRepository;
use App\Repository\VolunteerShiftRepository;
use App\Service\AppSettings;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Notification\NotificationPreferences;
use App\Service\Notification\NotificationTopic;
use App\Security\PartnerAccessPolicy;
use App\Service\Push\PushSender;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * El "por dónde": convierte una decisión de avisar en avisos enviados y en un
 * {@see VolunteerCall} registrado.
 *
 * Junta las tres piezas y no decide ninguna: el "cuándo" es de
 * {@see VolunteerCallEscalator}, el "quiénes" de
 * {@see VolunteerAudienceResolver} y el envío de {@see PushSender}. Aquí sólo
 * se orquesta, se redacta el mensaje y se deja constancia.
 *
 * TRES VÍAS: la copia en la bandeja de avisos, el push y el correo. La bandeja no
 * es un canal más sino el suelo de los otros dos —se escribe sin mirar
 * preferencias y antes de intentar ningún envío—, y quien no tiene cuenta de
 * acceso a la web no recibe nada por ninguna: para esa gente el canal sigue siendo
 * el panel del nodo y el boca a boca.
 *
 * EL REGISTRO SE ESCRIBE ANTES DE ENVIAR. Si se escribiera después, un fallo a
 * mitad del lote dejaría el aviso mandado a media asociación y sin constancia,
 * y el siguiente tick lo repetiría desde cero. Con la fila escrita primero, el
 * UNIQUE (shift, scope) impide la repetición aunque el envío se tuerza: es
 * preferible un aviso que no salió a un aviso que salió dos veces.
 *
 * SE PIDE GENTE PARA UN TURNO, no para una tarea. "Hace falta gente para el
 * reparto" no se puede contestar; "faltan dos personas el viernes a las cinco",
 * sí. Y con el registro por tarea, pedir gente para un viernes habría gastado el
 * aviso de todos los viernes del año.
 */
class VolunteerCallNotifier
{
    public function __construct(
        private readonly VolunteerShiftRepository $shifts,
        private readonly UserRepository $users,
        private readonly VolunteerAudienceResolver $audience,
        private readonly VolunteerCallEscalator $escalator,
        private readonly PushSender $push,
        private readonly NotificationInbox $inbox,
        private readonly NotificationLink $link,
        private readonly NotificationPreferences $preferences,
        private readonly EntityManagerInterface $entityManager,
        private readonly AppSettings $settings,
        private readonly VolunteerOfferFormatter $formatter,
        private readonly LoggerInterface $logger,
        private readonly MailerInterface $mailer,
        private readonly PartnerAccessPolicy $accessPolicy,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Manda los avisos que toquen ahora mismo, recorriendo los turnos abiertos.
     * Es lo que llama el planificador.
     *
     * @param \DateTimeImmutable $now momento de referencia
     *
     * @return int número de llamadas enviadas
     */
    public function dispatchDue(\DateTimeImmutable $now): int
    {
        if (!$this->settings->getBool(AppSettings::FEATURE_VOLUNTEERING)) {
            return 0;
        }

        $sent = 0;
        foreach ($this->shifts->findUpcoming($now) as $shift) {
            $scope = $this->escalator->nextScope($shift, $now);
            if (null === $scope) {
                continue;
            }

            if (null !== $this->dispatch($shift, $scope, null, $now)) {
                ++$sent;
            }
        }

        return $sent;
    }

    /**
     * Manda UNA llamada de un alcance concreto y la registra.
     *
     * Devuelve null cuando no había a quien avisar: sin destinatarios no se
     * registra nada, para que el alcance siga disponible si más adelante entra
     * gente nueva que sí encaje.
     *
     * @param VolunteerShift     $shift       el turno por el que se pide gente
     * @param string             $scope       uno de VolunteerCall::SCOPE_*
     * @param User|null          $triggeredBy quién lo lanza; null si es automático
     * @param \DateTimeImmutable $now         momento de referencia
     *
     * @return VolunteerCall|null la llamada registrada, o null si no había a quien avisar
     */
    public function dispatch(
        VolunteerShift $shift,
        string $scope,
        ?User $triggeredBy,
        \DateTimeImmutable $now,
    ): ?VolunteerCall {
        $offer = $shift->getOffer();
        if (null === $offer) {
            return null;
        }

        // Quiénes encajan con la tarea. Es una pregunta del dominio —a quién le
        // sirve— y no de canal. Las preferencias se marcan por área, que es de la
        // tarea; el turno sólo dice cuándo.
        $partners = $this->audience->resolve($offer, $scope);
        if ([] === $partners) {
            return null;
        }

        // Y de esos, quiénes quieren enterarse por cada vía. Son listas
        // distintas a propósito: hay quien sólo quiere el correo y quien sólo
        // quiere el móvil, y mandar a la unión de ambas es exactamente lo que
        // hace que la gente apague los avisos.
        $byPush = $this->preferences->filter($partners, NotificationTopic::VOLUNTEERING, NotificationTopic::CHANNEL_PUSH);
        $byEmail = $this->emailEnabled()
            ? $this->preferences->filter($partners, NotificationTopic::VOLUNTEERING, NotificationTopic::CHANNEL_EMAIL)
            : [];

        // La copia de la bandeja va a TODA la audiencia que tenga cuenta, sin
        // pasar por las preferencias: es el suelo del aviso, y quien ha apagado el
        // móvil es justo quien más necesita encontrarlo al entrar. Quien no tiene
        // cuenta queda fuera porque no tiene bandeja donde mirar.
        $inboxRecipients = $this->users->findByPartners($partners);

        // SE REGISTRA LA LLAMADA SI HAY ALGUIEN A QUIEN AVISAR POR CUALQUIER VÍA,
        // Y LA BANDEJA CUENTA COMO UNA. Antes, con toda la audiencia sin push ni
        // correo, esto devolvía null y no registraba nada para que el alcance
        // siguiera disponible; ahora ese caso SÍ avisa —la copia se escribe— y
        // registrarlo es lo único que impide que el tick de la hora siguiente
        // vuelva a dejar la misma fila en la bandeja de todo el mundo. Sin
        // destinatarios de ninguna vía se sigue devolviendo null, que es el caso
        // de una audiencia sin cuentas de acceso.
        if ([] === $byPush && [] === $byEmail && [] === $inboxRecipients) {
            return null;
        }

        $recipients = $this->users->findByPartners($byPush);

        $call = (new VolunteerCall())
            ->setShift($shift)
            ->setScope($scope)
            ->setTriggeredBy($triggeredBy)
            // Cuenta PERSONAS EMPUJADAS y no filas escritas: quien recibe correo y
            // push es una sola persona a la que se ha pedido ayuda, y es lo que la
            // pantalla de gestión enseña. La bandeja NO suma aquí a propósito: una
            // copia esperando en la web no es haber pedido nada a nadie, y este
            // número es el que se mira para decidir si hace falta escalar el
            // aviso a más gente.
            ->setRecipients(\count($this->union($byPush, $byEmail)));

        try {
            $this->entityManager->persist($call);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Otro tick, u otra pestaña de gestión, ganó la carrera y ya avisó
            // de esto. La constancia existe igual, así que no se manda nada:
            // repetir el aviso es exactamente lo que el UNIQUE evita.
            $this->logger->info('Aviso de voluntariado ya enviado por otra vía', [
                'shift' => $shift->getId(),
                'scope' => $scope,
            ]);

            return null;
        }

        // La copia de la bandeja se escribe ANTES de los dos empujones: es la que
        // no se pierde, así que no puede depender de que el push o el correo
        // salgan bien. El registro ya está escrito, de modo que ni ésta ni los
        // envíos pueden repetirse.
        $this->inbox->deliver(
            $inboxRecipients,
            Notification::KIND_VOLUNTEERING_CALL,
            $this->title($shift),
            $this->body($shift),
        );

        $this->push->sendToMany(
            $recipients,
            $this->title($shift),
            $this->body($shift),
            // El destino ya no va escrito a mano aquí: sale de NotificationLink,
            // el mismo sitio del que sale el de la fila de la bandeja. Esa cadena
            // '/panel/voluntariado' estaba copiada en dos ficheros, y era
            // exactamente la forma de que un día llevaran a sitios distintos.
            $this->link->pathForKind(Notification::KIND_VOLUNTEERING_CALL),
        );

        $this->email($shift, $byEmail);

        return $call;
    }

    /**
     * Manda el aviso por correo a quienes lo quieren por ahí.
     *
     * BEST-EFFORT, igual que el push: un correo que no sale no puede tumbar la
     * tanda del planificador ni dejar el {@see VolunteerCall} a medias. El
     * registro ya está escrito cuando se llega aquí, así que un fallo se traga
     * con su traza y no se reintenta: repetir el aviso es peor que perderlo.
     *
     * Uno por persona y no un envío con copia oculta: el cuerpo lleva el enlace
     * para apuntarse y el pie para cambiar sus avisos, y los dos son de quien lo
     * recibe.
     *
     * @param VolunteerShift $shift    el turno
     * @param list<Partner>  $partners quienes lo quieren por correo
     */
    private function email(VolunteerShift $shift, array $partners): void
    {
        if ([] === $partners) {
            return;
        }

        $offer = $shift->getOffer();
        if (null === $offer) {
            return;
        }

        $title = $this->title($shift);
        $when = $this->formatter->date($shift->getStartsAt());
        $where = $this->formatter->place($offer);
        $url = $this->urlGenerator->generate('panel_volunteering', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $notificationsUrl = $this->urlGenerator->generate('panel_notifications', [], UrlGeneratorInterface::ABSOLUTE_URL);

        foreach ($partners as $partner) {
            $address = $partner->getEmail();
            if (!$address) {
                continue;
            }

            try {
                $this->mailer->send(
                    (new TemplatedEmail())
                        ->to($address)
                        ->subject($title . ': ' . $offer->getTitle())
                        ->htmlTemplate('email/volunteer_call.html.twig')
                        ->textTemplate('email/volunteer_call.txt.twig')
                        ->context([
                            'offer' => $offer,
                            'shift' => $shift,
                            'title' => $title,
                            'when' => $when,
                            'where' => $where,
                            'url' => $url,
                            'notifications_url' => $notificationsUrl,
                            // Los enlaces exigen sesión: a quien no puede entrar
                            // se le da una vía humana en vez de un botón que le
                            // deja en una pantalla de login.
                            'can_act' => $this->accessPolicy->canUseActionLinks($partner),
                        ])
                );
            } catch (\Throwable $e) {
                $this->logger->error('No se pudo enviar el aviso de voluntariado por correo', [
                    'shift' => $shift->getId(),
                    'partner' => $partner->getId(),
                    'exception' => $e,
                ]);
            }
        }
    }

    /**
     * Si los avisos de voluntariado por correo están encendidos.
     *
     * Se mira aquí y no en el `requires` del cron porque esta tarea entrega por
     * dos canales: allí inhibiría la ejecución entera y dejaría también sin
     * aviso a quien lo quiere en el móvil. Y se mira ANTES de resolver la
     * audiencia de correo para no pagar la consulta cuando está apagado.
     */
    private function emailEnabled(): bool
    {
        return $this->settings->getBool(AppSettings::EMAIL_ENABLED)
            && $this->settings->getBool(AppSettings::EMAIL_VOLUNTEERING);
    }

    /**
     * Las personas de las dos listas, sin repetir.
     *
     * @param list<Partner> $a una lista
     * @param list<Partner> $b la otra
     *
     * @return list<Partner> la unión
     */
    private function union(array $a, array $b): array
    {
        $all = [];
        foreach ([...$a, ...$b] as $partner) {
            $all[(int) $partner->getId()] = $partner;
        }

        return array_values($all);
    }

    /**
     * El título del aviso: qué hace falta, en cinco palabras.
     *
     * @param VolunteerShift $shift el turno
     *
     * @return string el título
     */
    private function title(VolunteerShift $shift): string
    {
        $remaining = $shift->getRemainingSlots();

        if (1 === $remaining) {
            return 'Falta una persona';
        }

        return null !== $remaining
            ? sprintf('Faltan %d personas', $remaining)
            : 'Hace falta gente';
    }

    /**
     * El cuerpo: qué, cuándo y dónde. En ese orden porque es el orden en que se
     * decide si puedes ir.
     *
     * @param VolunteerShift $shift el turno
     *
     * @return string el cuerpo del aviso
     */
    private function body(VolunteerShift $shift): string
    {
        $offer = $shift->getOffer();

        $parts = [$offer?->getTitle() ?? 'Voluntariado', $this->formatter->date($shift->getStartsAt())];

        $where = null !== $offer ? $this->formatter->place($offer) : null;
        if (null !== $where) {
            $parts[] = $where;
        }

        return implode(' · ', $parts);
    }
}
