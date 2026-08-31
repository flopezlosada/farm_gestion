<?php

namespace App\Service\Volunteering;

use App\Entity\VolunteerOffer;

/**
 * Cómo estaba una tarea antes de que alguien la editara, en los cuatro datos por
 * los que una persona apuntada querría enterarse: si sigue en pie, cuándo,
 * dónde y si se hace desde casa.
 *
 * Existe como copia y no como comparación contra la BBDD porque para cuando el
 * formulario se ha validado, la entidad ya lleva los valores nuevos: el original
 * hay que haberlo guardado antes o se ha perdido.
 *
 * Deliberadamente NO recoge título, descripción, plazas ni categorías. Avisar de
 * que a la tarea le han corregido una falta de ortografía es la forma de que el
 * siguiente aviso, el que sí importa, ya no lo lea nadie.
 */
final class VolunteerOfferSnapshot
{
    /**
     * @param string                  $status   el estado en que estaba
     * @param \DateTimeInterface|null $startsAt cuándo empezaba
     * @param int|null                $nodeId   en qué punto de recogida era
     * @param string|null             $place    en qué lugar era
     * @param bool                    $remote   si se hacía desde casa
     */
    private function __construct(
        public readonly string $status,
        public readonly ?\DateTimeInterface $startsAt,
        public readonly ?int $nodeId,
        public readonly ?string $place,
        public readonly bool $remote,
    ) {
    }

    /**
     * Congela el estado actual de una tarea.
     *
     * @param VolunteerOffer $offer la tarea
     */
    public static function of(VolunteerOffer $offer): self
    {
        return new self(
            $offer->getStatus(),
            // Clonada: si se guardara la referencia, el formulario mutaría el
            // mismo objeto DateTime y la "foto" cambiaría con él, así que nunca
            // se detectaría un cambio de fecha.
            null !== $offer->getStartsAt() ? \DateTimeImmutable::createFromInterface($offer->getStartsAt()) : null,
            $offer->getNode()?->getId(),
            $offer->getPlace(),
            $offer->isRemote(),
        );
    }

    /**
     * Si la tarea se ha anulado desde esta foto.
     *
     * @param VolunteerOffer $offer la tarea, ya con los valores nuevos
     *
     * @return bool true si antes no estaba anulada y ahora sí
     */
    public function wasCancelledIn(VolunteerOffer $offer): bool
    {
        return VolunteerOffer::STATUS_CANCELLED !== $this->status
            && VolunteerOffer::STATUS_CANCELLED === $offer->getStatus();
    }

    /**
     * Si ha cambiado el momento.
     *
     * @param VolunteerOffer $offer la tarea, ya con los valores nuevos
     *
     * @return bool true si la fecha u hora de inicio es otra
     */
    public function movedIn(VolunteerOffer $offer): bool
    {
        $now = $offer->getStartsAt();

        if (null === $this->startsAt || null === $now) {
            return $this->startsAt !== $now;
        }

        return $this->startsAt->getTimestamp() !== $now->getTimestamp();
    }

    /**
     * Si ha cambiado el sitio, en cualquiera de sus tres formas.
     *
     * @param VolunteerOffer $offer la tarea, ya con los valores nuevos
     *
     * @return bool true si el sitio es otro
     */
    public function relocatedIn(VolunteerOffer $offer): bool
    {
        return $this->nodeId !== $offer->getNode()?->getId()
            || $this->place !== $offer->getPlace()
            || $this->remote !== $offer->isRemote();
    }
}
