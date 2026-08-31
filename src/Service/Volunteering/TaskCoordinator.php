<?php

namespace App\Service\Volunteering;

use App\Entity\Partner;
use App\Entity\VolunteerOffer;

/**
 * Quién coordina una tarea cuando no hace falta preguntarlo.
 *
 * POR QUÉ LA TAREA GUARDA SU COORDINADOR en vez de mirar quién coordina el área:
 * porque la coordinación de un área CAMBIA. Si la ficha leyera quién lleva
 * Reparto hoy, el día que esa persona lo deje todas las tareas del año pasado
 * dirían que las coordinó quien acaba de entrar. Es el mismo motivo por el que
 * {@see \App\Entity\VolunteerSignup::$creditedMinutes} congela las horas en vez
 * de leerlas de la oferta: el histórico no se reescribe solo.
 *
 * Y POR QUÉ NO HAY QUE RELLENARLO A MANO: mientras un área tenga una sola
 * persona coordinándola —el caso de hoy— preguntar quién coordina la tarea es
 * pedir un dato que ya se sabe, y un campo obligatorio que siempre se contesta
 * igual acaba rellenándose mal o no rellenándose. Con varias, ahí sí hay algo
 * que decidir y el formulario lo pregunta.
 */
class TaskCoordinator
{
    /**
     * Pone coordinador a la tarea si se puede deducir sin ambigüedad.
     *
     * No toca nada si ya tiene: lo que se haya elegido a mano manda, y volver a
     * guardar la tarea no puede cambiarle el coordinador por debajo.
     *
     * Sólo con UNA candidata en total. Si la tarea es de dos áreas con
     * coordinadoras distintas, tampoco está claro quién la monta: se deja vacío
     * y que lo diga quien la crea.
     *
     * La candidata tiene que tener socix: la coordinación de un área cuelga de
     * la cuenta ({@see \App\Entity\VolunteerCategory::$coordinators} son `User`)
     * y aquí hace falta un `Partner`, porque de lo que se trata es de a quién se
     * le atribuye el trabajo. Quien coordina sin ser socix no se puede poner.
     *
     * @param VolunteerOffer $offer la tarea recién rellenada
     */
    public function assignIfObvious(VolunteerOffer $offer): void
    {
        if (null !== $offer->getCoordinator()) {
            return;
        }

        $candidates = $this->candidatesFor($offer);

        if (1 === \count($candidates)) {
            $offer->setCoordinator(reset($candidates));
        }
    }

    /**
     * Si esta tarea tiene más de una persona que podría coordinarla, que es
     * cuando el formulario debe preguntar.
     *
     * @param VolunteerOffer $offer la tarea
     *
     * @return bool true si hay que elegir
     */
    public function needsChoosing(VolunteerOffer $offer): bool
    {
        return \count($this->candidatesFor($offer)) > 1;
    }

    /**
     * Quién podría coordinar esta tarea: quien coordina alguna de sus áreas, más
     * quien ya conste en ella.
     *
     * Un solo sitio a propósito. Esto lo consultan el formulario —para saber si
     * pregunta, y qué ofrece— y los dos métodos de arriba; con una copia en cada
     * uno, el desplegable acabaría ofreciendo un juego de gente distinto del que
     * el sistema considera al asignar solo, y nadie lo notaría hasta ver una
     * tarea atribuida a quien no la llevó.
     *
     * La candidata tiene que tener socix: la coordinación de un área cuelga de
     * la cuenta ({@see \App\Entity\VolunteerCategory::$coordinators} son `User`)
     * y aquí hace falta un `Partner`, porque de lo que se trata es de a quién se
     * le atribuye el trabajo. Quien coordina sin ser socix no se puede poner.
     *
     * @param VolunteerOffer $offer la tarea
     *
     * @return array<int, Partner> candidatas indexadas por id de socix
     */
    public function candidatesFor(VolunteerOffer $offer): array
    {
        /** @var array<int, Partner> $candidates */
        $candidates = [];

        foreach ($offer->getCategories() as $category) {
            foreach ($category->getCoordinators() as $user) {
                $partner = $user->getPartner();

                if (null === $partner || null === $partner->getId()) {
                    continue;
                }

                // Indexado por id: la misma persona coordinando dos de las áreas
                // de la tarea es una sola candidata, no dos.
                $candidates[$partner->getId()] = $partner;
            }
        }

        // Quien ya está puesto entra en la lista aunque haya dejado de coordinar
        // el área: si no, editar cualquier otra cosa de la tarea le borraría de
        // ella sin avisar, y con él la única constancia de quién la llevó.
        $current = $offer->getCoordinator();
        if (null !== $current && null !== $current->getId()) {
            $candidates[$current->getId()] = $current;
        }

        return $candidates;
    }
}
