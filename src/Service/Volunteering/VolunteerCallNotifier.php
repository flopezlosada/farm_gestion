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

        // Quiénes encajan con el trabajo. Es una pregunta del dominio —a quién le
        // sirve— y no de canal.
        //
        // Se le pasa el TURNO y no la tarea: las preferencias se marcan por área
        // —que es de la tarea, y el resolver la saca de ahí— pero quién ya está
        // apuntado depende del día. Con la tarea, descontar a quien vino el
        // martes dejaría sin aviso del sábado justo a la gente que más colabora.
        $partners = $this->audience->resolve($shift, $scope);
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
            'volunteer_call',
        );

        $this->email($shift, $byEmail);

        return $call;
    }

    /**
     * Pedírselo a UNA persona concreta, desde la ficha del turno.
     *
     * NO ES UN ALCANCE Y NO ESCRIBE {@see VolunteerCall}, y eso es lo que lo
     * separa de {@see dispatch()}. Aquel registro existe para que el
     * planificador no repita un aviso masivo, y su UNIQUE (shift, scope) es lo
     * que lo garantiza; meter aquí un ámbito "personal" que hubiera que exceptuar
     * de esa unicidad rompería la única invariante que protege a la asociación de
     * recibir el mismo aviso dos veces. El rastro de esto es un
     * {@see VolunteerEvent}, que es donde vive el "quién hizo qué" del módulo.
     *
     * TAMPOCO ESCALA NADA. La escalada automática se guía por los ámbitos ya
     * enviados, así que pedírselo a una persona no adelanta ni consume ningún
     * paso: el aviso a quien tiene marcada el área saldrá igual cuando le toque.
     * Es deliberado — esto es una conversación, no un canal.
     *
     * VA POR LAS TRES VÍAS, como el aviso de ámbito, y respetando las mismas
     * preferencias: quien pidió que no se le avise por el móvil no recibe push
     * porque alguien se lo pida a mano. La copia en la bandeja es el suelo, y por
     * eso es la que hace que esto sirva para quien nunca se suscribió al push.
     *
     * SIN CUENTA TODAVÍA PUEDE HABER CORREO, y conviene no confundirlo: la
     * bandeja y el push cuelgan de la cuenta de acceso, pero la dirección de
     * correo vive en la ficha del socix. Alguien que nunca ha entrado en la web
     * pero tiene su correo puesto sí se entera — es el mismo caso que ya cubre el
     * aviso de ámbito.
     *
     * 🔴 COMPRUEBA EL OPT-OUT DURO AUNQUE LA PANTALLA YA LO FILTRE, y no es
     * redundancia. Son DOS mecanismos distintos y sólo uno de los dos lo miran
     * las preferencias:
     *
     *  - `Partner::isVolunteeringOptOut()` es la columna dedicada, el "no me
     *    avises de voluntariado" que el socix marca en su panel. La consultan las
     *    tres consultas que alimentan {@see VolunteerAudienceResolver}, así que
     *    todo el camino automático lo respeta.
     *  - `NotificationOptOut`, que es lo que lee {@see NotificationPreferences},
     *    es otra tabla y es fino por tema y canal. **No mira esa columna.**
     *
     * Quien usó el interruptor duro es justo quien no va a tener fila en la tabla
     * fina, así que confiar sólo en las preferencias dejaba pasar el aviso
     * precisamente a quien más claro lo había dicho. Y la lista de la pantalla no
     * sirve de garantía: la filtra un GET, pero el POST recibe un id y basta con
     * tener la ficha cargada de antes de que esa persona lo marcara.
     *
     * Es el daño que todo el escalado existe para evitar —el permiso del
     * navegador se pierde una vez y para siempre— y llegaba por la puerta de
     * atrás.
     *
     * @param VolunteerShift $shift   el turno para el que se pide ayuda
     * @param Partner        $partner a quién se le pide
     *
     * @return list<string> las vías por las que salió: 'inbox', 'push', 'email'
     */
    public function ask(VolunteerShift $shift, Partner $partner): array
    {
        if (null === $shift->getOffer() || !$this->canBeAsked($partner)) {
            return [];
        }

        $partners = [$partner];
        $ways = [];

        $inboxRecipients = $this->users->findByPartners($partners);
        if ([] !== $inboxRecipients) {
            $this->inbox->deliver(
                $inboxRecipients,
                Notification::KIND_VOLUNTEERING_CALL,
                $this->askTitle($shift),
                $this->askBody($shift),
            );
            $ways[] = 'inbox';
        }

        // El push cuelga de la CUENTA, no de la persona: sin cuenta no hay
        // navegador que se haya podido suscribir, y preguntarlo sería una
        // consulta para no encontrar nada.
        if ([] !== $inboxRecipients
            && [] !== $this->preferences->filter($partners, NotificationTopic::VOLUNTEERING, NotificationTopic::CHANNEL_PUSH)
        ) {
            // El envío devuelve NAVEGADORES alcanzados, no personas: cero
            // significa que esta persona no tiene ningún dispositivo suscrito, y
            // entonces no se puede decir que le haya llegado por aquí.
            $reached = $this->push->sendToMany(
                $inboxRecipients,
                $this->askTitle($shift),
                $this->askBody($shift),
                $this->link->pathForKind(Notification::KIND_VOLUNTEERING_CALL),
                'volunteer_call',
            );

            if ($reached > 0) {
                $ways[] = 'push';
            }
        }

        // La dirección se comprueba AQUÍ y no se deja para `email()`, que salta
        // en silencio a quien no la tiene: con una sola persona delante, esa
        // comprobación es exacta, y sin ella la pantalla diría «le llega por
        // correo» a quien no tiene correo en su ficha. Lo que se le cuenta a
        // quien acaba de pulsar el botón decide si además coge el teléfono.
        if ($this->emailEnabled() && $partner->getEmail()) {
            $byEmail = $this->preferences->filter($partners, NotificationTopic::VOLUNTEERING, NotificationTopic::CHANNEL_EMAIL);
            if ([] !== $byEmail) {
                $this->email($shift, $byEmail, $this->askTitle($shift));
                $ways[] = 'email';
            }
        }

        return $ways;
    }

    /**
     * Si a esta persona se le puede pedir algo, sea quien sea quien lo pida.
     *
     * Dos condiciones, las mismas que respetan los finders de
     * {@see \App\Repository\PartnerRepository} de los que sale la audiencia
     * automática: que no haya pedido que no se le avise de voluntariado, y que
     * siga siendo socix activx. A quien se dio de baja no se le pide ayuda.
     *
     * Vive aquí y no sólo en el controlador porque es política de a-quién-se-le-
     * manda, y este servicio es el único que manda: una comprobación en la
     * pantalla se salta con un POST, una aquí no.
     *
     * @param Partner $partner a quién se le iba a pedir
     *
     * @return bool true si se le puede pedir
     */
    public function canBeAsked(Partner $partner): bool
    {
        return !$partner->isVolunteeringOptOut()
            && Partner::STATUS_ACTIVO === $partner->getStatus();
    }

    /**
     * El título del aviso que se le pide a mano a una persona.
     *
     * LLEVA EL NOMBRE DE LA TAREA, a diferencia del de ámbito («Faltan 2
     * personas»), y por eso el cuerpo de este aviso NO lo repite: en una
     * notificación del móvil el título es lo único que se lee seguro, así que
     * ahí va lo que identifica de qué se trata.
     *
     * NO LLEVA «CSA» ni el nombre de la asociación: eso ya lo pone el navegador
     * —el origen debajo del texto, o el nombre de la app si está instalada— y el
     * icono del `sw.js` lo dice sin gastar ni una letra. Metido en el título sólo
     * empujaría el nombre de la tarea fuera de lo que se ve.
     *
     * @param VolunteerShift $shift el turno para el que se pide ayuda
     *
     * @return string el título
     */
    private function askTitle(VolunteerShift $shift): string
    {
        $title = $shift->getOffer()?->getTitle();

        return null !== $title
            ? sprintf('Hace falta gente para la tarea: %s, ¿puedes unirte?', $title)
            : 'Hace falta gente, ¿puedes unirte?';
    }

    /**
     * El cuerpo del aviso pedido a mano: cuándo y dónde, sin repetir la tarea
     * —que ya va en el título— para no gastar en eco las dos líneas que el móvil
     * enseña.
     *
     * @param VolunteerShift $shift el turno
     *
     * @return string el cuerpo
     */
    private function askBody(VolunteerShift $shift): string
    {
        $offer = $shift->getOffer();
        $parts = [$this->formatter->date($shift->getStartsAt())];

        $where = null !== $offer ? $this->formatter->place($offer) : null;
        if (null !== $where) {
            $parts[] = $where;
        }

        return implode(' · ', $parts);
    }

    /**
     * Si hay alguna vía por la que pedirle algo a esta persona.
     *
     * La pantalla la necesita para no pintar un botón que no puede hacer nada:
     * quien lo pulsa se queda creyendo que ya se lo pidió y no coge el teléfono.
     *
     * 🔴 EL CORREO SÓLO CUENTA SI EL CANAL ESTÁ ENCENDIDO, y ése es el detalle
     * que se me escapó: `EMAIL_VOLUNTEERING` viene **apagado de fábrica**
     * (`AppSettings`, default false), así que dar por alcanzable a quien sólo
     * tiene correo pintaba el botón y al pulsarlo no salía nada. Lo cazó el test
     * funcional, que corre con los defaults del catálogo igual que producción.
     *
     * @param Partner $partner    a quién se le iba a pedir
     * @param bool    $hasAccount si tiene cuenta para entrar en la web
     *
     * @return bool true si le llegaría por algún sitio
     */
    public function canReach(Partner $partner, bool $hasAccount): bool
    {
        // Con cuenta hay bandeja, que es el suelo y no depende de ningún ajuste.
        return $hasAccount || ($this->emailEnabled() && (bool) $partner->getEmail());
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
     * @param string|null    $subject  título propio; por defecto, el del aviso de ámbito
     */
    private function email(VolunteerShift $shift, array $partners, ?string $subject = null): void
    {
        if ([] === $partners) {
            return;
        }

        $offer = $shift->getOffer();
        if (null === $offer) {
            return;
        }

        // El aviso pedido a mano tiene su propio título y el correo lleva el
        // mismo: recibir un push que dice una cosa y un correo que dice otra por
        // la misma petición se lee como dos peticiones distintas.
        $title = $subject ?? $this->title($shift);
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
                        // El de ámbito («Faltan 2 personas») necesita que se le
                        // pegue la tarea para que el asunto diga de qué va; el
                        // pedido a mano ya la lleva dentro, y pegarla otra vez la
                        // repetía en la misma línea.
                        ->subject(null !== $subject ? $subject : $title . ': ' . $offer->getTitle())
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
