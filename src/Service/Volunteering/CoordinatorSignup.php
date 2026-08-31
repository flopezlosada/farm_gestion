<?php

namespace App\Service\Volunteering;

use App\Entity\VolunteerOffer;
use App\Entity\VolunteerSignup;

/**
 * Mantiene en pie la inscripción de quien coordina una tarea.
 *
 * Coordinar computa horas, pero quien coordina no se apunta a la tarea: la
 * monta. Sin esta pieza, la gente que más sostiene el voluntariado salía con el
 * contador a cero salvo que alguien se acordara de anotarla a mano después —y no
 * se acordaba nadie—. Ahora se dice al crear la tarea ({@see VolunteerOffer::$coordinator})
 * y su inscripción aparece sola.
 *
 * NACE SIN RESPONDER (`attended` a null), como cualquier otra. Darla por hecha
 * al crear la tarea sería computar horas por un trabajo que todavía no ha
 * ocurrido, y dejaría horas puestas en una tarea que luego se anula.
 *
 * NO OCUPA PLAZA: {@see VolunteerSignup::getHeadcount()} devuelve 0 para el rol
 * de coordinación, así que la tarea sigue pidiendo la gente que necesita.
 */
class CoordinatorSignup
{
    /**
     * Deja la inscripción de coordinación de acuerdo con quién coordina ahora.
     *
     * Se llama al guardar la tarea, y tiene que aguantar que se cambie de
     * persona a mitad de camino:
     *
     *  - Quien coordina y no constaba, pasa a constar.
     *  - Quien ya constaba por haberse apuntado a trabajar, pasa a coordinación
     *    conservando lo que hubiera dicho: es la misma inscripción, el UNIQUE
     *    (offer, partner) no admite dos, y borrarla perdería su respuesta.
     *  - A quien coordinaba y ya no, se le retira la inscripción SÓLO si aún no
     *    había respondido. Si consta que hizo el trabajo, sus horas son suyas y
     *    quitárselas por un cambio administrativo posterior sería robarle una
     *    aportación real.
     *
     * @param VolunteerOffer $offer la tarea recién guardada
     */
    public function sync(VolunteerOffer $offer): void
    {
        $coordinator = $offer->getCoordinator();

        foreach ($offer->getSignups() as $signup) {
            if (!$signup->isCoordination()) {
                continue;
            }

            $isStillTheOne = null !== $coordinator && $signup->getPartner() === $coordinator;

            if ($isStillTheOne) {
                // Ya está: nada que tocar.
                return;
            }

            if (!$signup->isSettled()) {
                $signup->cancel();
            }
        }

        if (null === $coordinator) {
            return;
        }

        foreach ($offer->getSignups() as $signup) {
            if ($signup->getPartner() === $coordinator) {
                $signup->reopen()->setRole(VolunteerSignup::ROLE_COORDINATOR);

                return;
            }
        }

        $offer->addSignup(
            (new VolunteerSignup())
                ->setPartner($coordinator)
                ->setRole(VolunteerSignup::ROLE_COORDINATOR)
        );
    }
}
