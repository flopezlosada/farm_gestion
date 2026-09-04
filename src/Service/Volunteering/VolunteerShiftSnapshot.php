<?php

namespace App\Service\Volunteering;

use App\Entity\VolunteerShift;

/**
 * Cómo estaba un turno antes de que alguien lo editara: cuándo era y si seguía
 * en pie.
 *
 * Hermano de {@see VolunteerOfferSnapshot}, que guarda lo de la tarea. El corte
 * es el mismo que hace el modelo: la tarea dice qué y dónde, el turno dice
 * cuándo, y a quien está apuntado le importan las dos cosas por vías distintas.
 *
 * Existe como copia y no como comparación contra la BBDD porque para cuando el
 * formulario se ha validado, la entidad ya lleva los valores nuevos.
 */
final class VolunteerShiftSnapshot
{
    /**
     * @param \DateTimeInterface|null $startsAt  cuándo empezaba
     * @param bool                    $cancelled si estaba anulado
     */
    private function __construct(
        public readonly ?\DateTimeInterface $startsAt,
        public readonly bool $cancelled,
    ) {
    }

    /**
     * Congela el estado actual de un turno.
     *
     * @param VolunteerShift $shift el turno
     */
    public static function of(VolunteerShift $shift): self
    {
        $startsAt = $shift->getStartsAt();

        return new self(
            // Clonada: si se guardara la referencia, el formulario mutaría el
            // mismo objeto DateTime y la "foto" cambiaría con él, así que nunca
            // se detectaría un cambio de fecha.
            null !== $startsAt ? \DateTimeImmutable::createFromInterface($startsAt) : null,
            null !== $shift->getCancelledAt(),
        );
    }

    /**
     * Si el turno se ha anulado desde esta foto. Mira sólo su propia anulación:
     * que la tarea entera se anule lo cuenta {@see VolunteerOfferSnapshot}, y
     * avisar dos veces del mismo hecho es peor que avisar una.
     *
     * @param VolunteerShift $shift el turno, ya con los valores nuevos
     *
     * @return bool true si antes estaba en pie y ahora está anulado
     */
    public function wasCancelledIn(VolunteerShift $shift): bool
    {
        return !$this->cancelled && null !== $shift->getCancelledAt();
    }

    /**
     * Si ha cambiado el momento.
     *
     * @param VolunteerShift $shift el turno, ya con los valores nuevos
     *
     * @return bool true si la fecha u hora de inicio es otra
     */
    public function movedIn(VolunteerShift $shift): bool
    {
        $now = $shift->getStartsAt();

        if (null === $this->startsAt || null === $now) {
            return $this->startsAt !== $now;
        }

        return $this->startsAt->getTimestamp() !== $now->getTimestamp();
    }
}
