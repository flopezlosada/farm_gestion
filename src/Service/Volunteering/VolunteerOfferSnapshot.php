<?php

namespace App\Service\Volunteering;

use App\Entity\VolunteerOffer;

/**
 * Cómo estaba una tarea antes de que alguien la editara, en los datos por los
 * que una persona apuntada querría enterarse: si sigue en pie y dónde es.
 *
 * NO LLEVA LA FECHA, y antes sí: el momento ya no es de la tarea sino del turno,
 * y su foto vive en {@see VolunteerShiftSnapshot}. Mover "el reparto" no
 * significa nada; lo que se mueve es el reparto del viernes 12.
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
     * @param string      $status    el estado en que estaba
     * @param int|null    $nodeId    en qué punto de recogida era
     * @param int|null    $placeId   en qué sitio del catálogo era
     * @param string|null $placeNote la precisión sobre el sitio
     * @param bool        $remote    si se hacía desde casa
     */
    private function __construct(
        public readonly string $status,
        public readonly ?int $nodeId,
        public readonly ?int $placeId,
        public readonly ?string $placeNote,
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
            $offer->getNode()?->getId(),
            $offer->getPlace()?->getId(),
            $offer->getPlaceNote(),
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
     * Si la tarea se ha puesto en pausa desde esta foto.
     *
     * Se avisa igual que de una anulación, aunque sea más suave: quien tenía
     * apuntado el sábado que viene necesita saber que ese sábado no se hace.
     *
     * @param VolunteerOffer $offer la tarea, ya con los valores nuevos
     *
     * @return bool true si antes no estaba en pausa y ahora sí
     */
    public function wasPausedIn(VolunteerOffer $offer): bool
    {
        return VolunteerOffer::STATUS_PAUSED !== $this->status
            && VolunteerOffer::STATUS_PAUSED === $offer->getStatus();
    }

    /**
     * Si ha cambiado el sitio, en cualquiera de sus formas.
     *
     * @param VolunteerOffer $offer la tarea, ya con los valores nuevos
     *
     * @return bool true si el sitio es otro
     */
    public function relocatedIn(VolunteerOffer $offer): bool
    {
        return $this->nodeId !== $offer->getNode()?->getId()
            || $this->placeId !== $offer->getPlace()?->getId()
            || $this->placeNote !== $offer->getPlaceNote()
            || $this->remote !== $offer->isRemote();
    }
}
