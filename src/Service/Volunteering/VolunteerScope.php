<?php

namespace App\Service\Volunteering;

use App\Entity\User;
use App\Entity\VolunteerCategory;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Qué parte del voluntariado ve quien está mirando.
 *
 * Dos figuras distintas y conviene no confundirlas:
 *
 *  - Quien lleva el voluntariado entero (`ROLE_GESTION_VOLUNTARIADO_EDIT`, y
 *    por jerarquía ROLE_ADMIN): ve y toca todo.
 *  - Quien coordina un área: ve y toca LO SUYO. Ni las tareas de otras áreas ni
 *    su gente. "Sin mezcla" es el requisito.
 *
 * Vive en un servicio y no en cada controller porque la regla se aplica en
 * cuatro sitios —tareas, quién hay, eventos y el desplegable de áreas— y
 * repetirla es garantizar que un día uno de los cuatro se quede sin ella. El
 * que se olvide es el que filtra datos de más, y ése no da error: simplemente
 * enseña lo que no debía.
 */
class VolunteerScope
{
    public function __construct(private readonly Security $security)
    {
    }

    /**
     * Si esta persona ve todo el voluntariado, sin filtrar por área.
     *
     * @return bool true para administración
     */
    public function seesEverything(): bool
    {
        return $this->security->isGranted('ROLE_GESTION_VOLUNTARIADO_EDIT');
    }

    /**
     * Las áreas que coordina, o null si las ve todas.
     *
     * El null es deliberado y no un "array vacío = todas": vacío significa
     * "no coordina nada y no es administración", que es un caso real —alguien a
     * quien han marcado como candidata en Usuarias pero a quien todavía no han
     * asignado ningún área— y tiene que ver una lista vacía, no la de todos.
     *
     * @return list<VolunteerCategory>|null las áreas propias; null si no hay filtro
     */
    public function categories(): ?array
    {
        if ($this->seesEverything()) {
            return null;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        return $user->getCoordinatedVolunteerCategories()->toArray();
    }

    /**
     * Si coordina al menos un área. Lo usan las pantallas para explicar el vacío
     * en vez de enseñar una tabla sin filas y sin motivo aparente.
     *
     * @return bool true si tiene algún área asignada
     */
    public function coordinatesSomething(): bool
    {
        $categories = $this->categories();

        return null === $categories || [] !== $categories;
    }
}
