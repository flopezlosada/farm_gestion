<?php

namespace App\Service\Volunteering;

use App\Entity\VolunteerOffer;
use App\Entity\VolunteerSignup;
use App\Repository\UserRepository;
use App\Service\Push\PushSender;

/**
 * Avisa a quien YA está apuntadx cuando su tarea se anula, se mueve de fecha o
 * cambia de sitio.
 *
 * Es lo contrario del resto de avisos del módulo: aquéllos piden gente, éste
 * informa a quien ya dijo que sí. Y es el que menos se puede saltar. Sin él,
 * anular una tarea deja a alguien plantándose allí para nada, y esa persona —que
 * es exactamente la que sí colabora— no vuelve. Es peor que no tener módulo.
 *
 * NO PASA POR {@see \App\Entity\VolunteerCall} y no es un olvido: aquel registro
 * existe para el escalado de "hace falta gente" y su unicidad (offer, scope)
 * impide repetir. Aquí hay que poder avisar tantas veces como cambie la tarea:
 * si se mueve dos veces, hay que avisar dos veces.
 *
 * Tampoco respeta el opt-out de voluntariado, a propósito. "No me avises de
 * voluntariado" significa "no me ofrezcáis tareas", no "no me contéis que la
 * tarea a la que me apunté se ha anulado". Quien se apuntó pidió esa información
 * al apuntarse.
 */
class VolunteerOfferChangeNotifier
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PushSender $push,
    ) {
    }

    /**
     * Manda el aviso que corresponda, si es que ha cambiado algo que importe.
     *
     * @param VolunteerOffer         $offer  la tarea, ya con los valores nuevos
     * @param VolunteerOfferSnapshot $before cómo estaba antes de editarla
     *
     * @return int a cuánta gente se avisó
     */
    public function notifyChanges(VolunteerOffer $offer, VolunteerOfferSnapshot $before): int
    {
        $message = $this->describe($offer, $before);
        if (null === $message) {
            return 0;
        }

        $partners = $this->activeParticipants($offer);
        if ([] === $partners) {
            return 0;
        }

        $recipients = $this->users->findByPartners($partners);
        if ([] === $recipients) {
            return 0;
        }

        $this->push->sendToMany($recipients, $message[0], $message[1], '/panel/voluntariado');

        return \count($recipients);
    }

    /**
     * Qué decir, o null si no ha cambiado nada que merezca molestar a nadie.
     *
     * El orden importa: si una tarea se anula, da igual que además se hubiera
     * movido de fecha. Y si se mueve y cambia de sitio a la vez, un solo aviso
     * con las dos cosas es mejor que dos avisos.
     *
     * @param VolunteerOffer         $offer  la tarea, ya con los valores nuevos
     * @param VolunteerOfferSnapshot $before cómo estaba antes
     *
     * @return array{0: string, 1: string}|null título y cuerpo, o null si no hay nada que decir
     */
    private function describe(VolunteerOffer $offer, VolunteerOfferSnapshot $before): ?array
    {
        if ($before->wasCancelledIn($offer)) {
            return [
                'Se ha anulado una tarea',
                sprintf('%s. Ya no hace falta que vayas.', $offer->getTitle()),
            ];
        }

        // Una tarea que ya estaba anulada, o que sigue en borrador, no genera
        // avisos por moverse: nadie cuenta con ella.
        if (VolunteerOffer::STATUS_PUBLISHED !== $offer->getStatus()) {
            return null;
        }

        $moved = $before->movedIn($offer);
        $relocated = $before->relocatedIn($offer);

        if (!$moved && !$relocated) {
            return null;
        }

        $what = [];
        if ($moved) {
            $what[] = sprintf('ahora es el %s', $this->formatDate($offer->getStartsAt()));
        }
        if ($relocated) {
            $what[] = sprintf('ahora es %s', $this->formatPlace($offer));
        }

        return [
            'Cambia una tarea a la que te apuntaste',
            sprintf('%s: %s.', $offer->getTitle(), implode(', y ', $what)),
        ];
    }

    /**
     * Lxs socixs apuntadxs que no se han dado de baja.
     *
     * @param VolunteerOffer $offer la tarea
     *
     * @return list<\App\Entity\Partner> quienes cuentan con ir
     */
    private function activeParticipants(VolunteerOffer $offer): array
    {
        $partners = [];
        foreach ($offer->getSignups() as $signup) {
            /** @var VolunteerSignup $signup */
            if (!$signup->isCancelled() && null !== $signup->getPartner()) {
                $partners[] = $signup->getPartner();
            }
        }

        return $partners;
    }

    /**
     * La fecha en cristiano y en la zona de aquí.
     *
     * @param \DateTimeInterface|null $date la fecha
     *
     * @return string la fecha legible
     */
    private function formatDate(?\DateTimeInterface $date): string
    {
        if (null === $date) {
            return 'sin fecha';
        }

        $formatter = new \IntlDateFormatter(
            'es_ES',
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::SHORT,
            'Europe/Madrid',
            \IntlDateFormatter::GREGORIAN,
            "EEEE d 'de' MMMM 'a las' HH:mm"
        );

        return (string) $formatter->format($date);
    }

    /**
     * Dónde es ahora.
     *
     * @param VolunteerOffer $offer la tarea
     *
     * @return string el sitio, legible
     */
    private function formatPlace(VolunteerOffer $offer): string
    {
        if ($offer->isRemote()) {
            return 'desde casa';
        }

        if (null !== $offer->getNode()) {
            return sprintf('en %s', $offer->getNode());
        }

        return null !== $offer->getPlace() ? sprintf('en %s', $offer->getPlace()) : 'en otro sitio';
    }
}
