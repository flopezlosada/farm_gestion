<?php

namespace App\Service\Volunteering;

use App\Entity\Notification;
use App\Entity\Partner;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerShift;
use App\Entity\VolunteerSignup;
use App\Repository\UserRepository;
use App\Service\Notification\NotificationInbox;
use App\Service\Notification\NotificationLink;
use App\Service\Push\PushSender;

/**
 * Avisa a quien YA está apuntadx cuando su trabajo se anula, se para, se mueve
 * de fecha o cambia de sitio.
 *
 * Es lo contrario del resto de avisos del módulo: aquéllos piden gente, éste
 * informa a quien ya dijo que sí. Y es el que menos se puede saltar. Sin él,
 * anular una tarea deja a alguien plantándose allí para nada, y esa persona —que
 * es exactamente la que sí colabora— no vuelve. Es peor que no tener módulo.
 *
 * DOS PUERTAS, PORQUE HAY DOS COSAS QUE CAMBIAN. Editar la TAREA (el sitio, o
 * pararla) afecta a todo el mundo que tenga un turno por venir; mover o anular
 * UN TURNO afecta sólo a quien iba ese día. Antes esto era una sola llamada
 * porque tarea y momento eran la misma fila, y con turnos mandar el aviso de
 * "cambia de sitio" a los doscientos apuntados de un año de tarea sería ruido
 * hasta que nadie lea ninguno.
 *
 * NO PASA POR {@see \App\Entity\VolunteerCall} y no es un olvido: aquel registro
 * existe para el escalado de "hace falta gente" y su unicidad impide repetir.
 * Aquí hay que poder avisar tantas veces como cambie la cosa: si se mueve dos
 * veces, hay que avisar dos veces.
 *
 * Tampoco respeta el opt-out de voluntariado, a propósito. "No me avises de
 * voluntariado" significa "no me ofrezcáis tareas", no "no me contéis que la
 * tarea a la que me apunté se ha anulado". Quien se apuntó pidió esa información
 * al apuntarse.
 *
 * Y DEJA COPIA EN LA BANDEJA, que era el agujero grande de este servicio. Salía
 * SÓLO por push, así que quien no lo tenía activado en ningún navegador —la
 * mayoría— no se enteraba de que su tarea se había anulado, justo el silencio
 * que el párrafo de arriba dice que es peor que no tener módulo. La copia se
 * escribe primero y sin condiciones: es la que no se pierde.
 */
class VolunteerOfferChangeNotifier
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PushSender $push,
        private readonly NotificationInbox $inbox,
        private readonly NotificationLink $link,
        private readonly VolunteerOfferFormatter $formatter,
    ) {
    }

    /**
     * Manda el aviso que corresponda tras editar la TAREA, si ha cambiado algo
     * que importe.
     *
     * @param VolunteerOffer         $offer  la tarea, ya con los valores nuevos
     * @param VolunteerOfferSnapshot $before cómo estaba antes de editarla
     *
     * @return int a cuánta gente se avisó
     */
    public function notifyChanges(VolunteerOffer $offer, VolunteerOfferSnapshot $before): int
    {
        $message = $this->describeOffer($offer, $before);
        if (null === $message) {
            return 0;
        }

        return $this->dispatch($this->upcomingParticipants($offer), $message);
    }

    /**
     * Manda el aviso que corresponda tras editar UN TURNO.
     *
     * @param VolunteerShift         $shift  el turno, ya con los valores nuevos
     * @param VolunteerShiftSnapshot $before cómo estaba antes de editarlo
     *
     * @return int a cuánta gente se avisó
     */
    public function notifyShiftChanges(VolunteerShift $shift, VolunteerShiftSnapshot $before): int
    {
        $message = $this->describeShift($shift, $before);
        if (null === $message) {
            return 0;
        }

        return $this->dispatch($this->activeParticipants($shift), $message);
    }

    /**
     * Deja la copia en la bandeja y manda el push. Común a las dos puertas para
     * que no puedan divergir en el orden ni en el destino.
     *
     * @param list<Partner>       $partners a quién avisar
     * @param array{0: string, 1: string} $message título y cuerpo
     *
     * @return int a cuánta gente se avisó
     */
    private function dispatch(array $partners, array $message): int
    {
        if ([] === $partners) {
            return 0;
        }

        $recipients = $this->users->findByPartners($partners);
        if ([] === $recipients) {
            return 0;
        }

        // La copia PRIMERO: es el suelo del aviso, y escribirla antes es lo que
        // impide que un push que no sale se lleve por delante la única
        // constancia.
        $this->inbox->deliver($recipients, Notification::KIND_VOLUNTEERING_CHANGE, $message[0], $message[1]);

        $this->push->sendToMany(
            $recipients,
            $message[0],
            $message[1],
            // El destino sale de NotificationLink, igual que el de la fila de la
            // bandeja: era la TERCERA copia de '/panel/voluntariado' escrita a
            // mano en el módulo.
            $this->link->pathForKind(Notification::KIND_VOLUNTEERING_CHANGE),
        );

        return \count($recipients);
    }

    /**
     * Qué decir de un cambio en la tarea, o null si no hay nada que merezca
     * molestar a nadie.
     *
     * El orden importa: si la tarea se anula, da igual que además haya cambiado
     * de sitio.
     *
     * @param VolunteerOffer         $offer  la tarea, ya con los valores nuevos
     * @param VolunteerOfferSnapshot $before cómo estaba antes
     *
     * @return array{0: string, 1: string}|null título y cuerpo, o null
     */
    private function describeOffer(VolunteerOffer $offer, VolunteerOfferSnapshot $before): ?array
    {
        if ($before->wasCancelledIn($offer)) {
            return [
                'Se ha anulado una tarea',
                sprintf('%s. Ya no hace falta que vayas.', $offer->getTitle()),
            ];
        }

        if ($before->wasPausedIn($offer)) {
            return [
                'Se ha parado una tarea',
                sprintf('%s se para por ahora. No hace falta que vayas a los próximos turnos.', $offer->getTitle()),
            ];
        }

        // Una tarea que ya estaba anulada o parada, o que sigue en borrador, no
        // genera avisos por cambiar de sitio: nadie cuenta con ella.
        if (!$offer->isPublished()) {
            return null;
        }

        if (!$before->relocatedIn($offer)) {
            return null;
        }

        return [
            'Cambia una tarea a la que te apuntaste',
            sprintf('%s: ahora es %s.', $offer->getTitle(), $this->describePlace($offer)),
        ];
    }

    /**
     * Qué decir de un cambio en el turno, o null si no hay nada que decir.
     *
     * @param VolunteerShift         $shift  el turno, ya con los valores nuevos
     * @param VolunteerShiftSnapshot $before cómo estaba antes
     *
     * @return array{0: string, 1: string}|null título y cuerpo, o null
     */
    private function describeShift(VolunteerShift $shift, VolunteerShiftSnapshot $before): ?array
    {
        $offer = $shift->getOffer();
        if (null === $offer) {
            return null;
        }

        if ($before->wasCancelledIn($shift)) {
            $reason = $shift->getCancelledReason();

            return [
                'Se ha anulado un turno',
                sprintf(
                    '%s del %s%s. Ya no hace falta que vayas.',
                    $offer->getTitle(),
                    $this->formatter->dateInSentence($before->startsAt),
                    null !== $reason ? sprintf(' (%s)', $reason) : '',
                ),
            ];
        }

        // Un turno de una tarea que no está publicada no genera avisos: nadie
        // cuenta con él.
        if (!$offer->isPublished() || $shift->isCancelled()) {
            return null;
        }

        if (!$before->movedIn($shift)) {
            return null;
        }

        return [
            'Cambia la fecha de un turno',
            sprintf(
                '%s: era el %s y ahora es el %s.',
                $offer->getTitle(),
                $this->formatter->dateInSentence($before->startsAt),
                $this->formatter->dateInSentence($shift->getStartsAt()),
            ),
        ];
    }

    /**
     * Lxs socixs con algún turno POR VENIR de esta tarea, sin repetir.
     *
     * A quien vino en mayo no le importa que en septiembre se cambie el sitio, y
     * avisarle sería mandarle un push por algo que ya hizo.
     *
     * MIRA LA ANULACIÓN PROPIA DEL TURNO Y NO {@see VolunteerShift::isCancelled()},
     * y ahí estaba el fallo: aquél devuelve true en cuanto la TAREA está
     * anulada, así que al anularla sus turnos dejaban de contar y el aviso de la
     * anulación —el que menos se puede saltar— no le llegaba a nadie. Lo cazó el
     * CI. El estado de la tarea ya lo ha decidido quien llama; aquí sólo hay que
     * saber a quién le afectaba ese día.
     *
     * @param VolunteerOffer $offer la tarea
     *
     * @return list<Partner> quienes tenían algún día por venir
     */
    private function upcomingParticipants(VolunteerOffer $offer): array
    {
        $now = new \DateTime();
        $partners = [];

        foreach ($offer->getShifts() as $shift) {
            /** @var VolunteerShift $shift */
            if (null !== $shift->getCancelledAt() || $shift->isPast($now)) {
                continue;
            }

            foreach ($this->activeParticipants($shift) as $partner) {
                $partners[spl_object_id($partner)] = $partner;
            }
        }

        return array_values($partners);
    }

    /**
     * Lxs socixs apuntadxs a un turno que no se han dado de baja.
     *
     * @param VolunteerShift $shift el turno
     *
     * @return list<Partner> quienes cuentan con ir
     */
    private function activeParticipants(VolunteerShift $shift): array
    {
        $partners = [];
        foreach ($shift->getSignups() as $signup) {
            /** @var VolunteerSignup $signup */
            if (!$signup->isCancelled() && null !== $signup->getPartner()) {
                $partners[] = $signup->getPartner();
            }
        }

        return $partners;
    }

    /**
     * Dónde es ahora, dentro de una frase. "desde casa" ya se lee bien tal cual;
     * un sitio con nombre necesita el "en" delante.
     *
     * @param VolunteerOffer $offer la tarea
     *
     * @return string el sitio, legible
     */
    private function describePlace(VolunteerOffer $offer): string
    {
        $place = $this->formatter->place($offer);

        if (null === $place) {
            return 'en otro sitio';
        }

        return $offer->isRemote() ? $place : sprintf('en %s', $place);
    }
}
