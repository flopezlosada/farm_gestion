<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerOffer;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Qué tarea de voluntariado puede tocar cada quien.
 *
 * Existe porque el alcance no cabe en un rol. Quien coordina el reparto de los
 * viernes tiene que poder cerrar y pedir gente para SUS tareas, y no para las de
 * la huerta; y crear un rol por área obligaría a tocar `security.yaml` y
 * desplegar cada vez que la asociación abre un área nueva o cambia quién la
 * lleva, que es lo que más va a pasar.
 *
 * Dos vías de acceso, y en este orden:
 *
 *  1. `ROLE_GESTION_VOLUNTARIADO_EDIT` — quien lleva el voluntariado entero
 *     (administración). Puede con todo.
 *  2. Ser coordinadora de alguna de las categorías de esa tarea. Puede con la
 *     suya y con ninguna más.
 *
 * UNA TAREA SIN CATEGORÍAS SÓLO LA TOCA QUIEN TIENE EL ROL GLOBAL, y no es un
 * descuido: sin categoría no hay área, así que no hay nadie de quien se pueda
 * decir que es "su" tarea. Lo contrario —que cualquier coordinador pudiera con
 * ella— haría que crear una tarea sin marcar su tipo abriera la puerta a todos.
 */
class VolunteerOfferVoter extends Voter
{
    /** Ver la tarea y quién se ha apuntado a ella. */
    public const VIEW = 'VOLUNTEER_OFFER_VIEW';

    /** Editarla, cerrarla y pedir gente para ella. */
    public const EDIT = 'VOLUNTEER_OFFER_EDIT';

    public function __construct(private readonly AccessDecisionManagerInterface $accessDecisionManager)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT], true)
            && $subject instanceof VolunteerOffer;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var VolunteerOffer $subject */
        if ($this->isGranted($token, 'ROLE_GESTION_VOLUNTARIADO_EDIT')) {
            return true;
        }

        if ($this->coordinates($user, $subject)) {
            return true;
        }

        // Sin coordinar el área, el rol de lectura llega para mirar pero no para
        // tocar: administración puede necesitar ver cómo va un área que no
        // lleva, sin poder cerrarla por ella.
        return self::VIEW === $attribute
            && $this->isGranted($token, 'ROLE_GESTION_VOLUNTARIADO');
    }

    /**
     * Si esta persona coordina alguna de las áreas de esta tarea.
     *
     * @param User           $user  quien pregunta
     * @param VolunteerOffer $offer la tarea
     *
     * @return bool true si coordina al menos una de sus categorías
     */
    private function coordinates(User $user, VolunteerOffer $offer): bool
    {
        foreach ($offer->getCategories() as $category) {
            /** @var VolunteerCategory $category */
            if ($category->isCoordinatedBy($user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Comprueba un rol respetando la jerarquía de `security.yaml` (que _EDIT
     * incluye la lectura, y que ROLE_ADMIN incluye todo). Con
     * `$token->getRoleNames()` a pelo no se vería la jerarquía y un admin se
     * quedaría fuera.
     *
     * @param TokenInterface $token el token de la petición
     * @param string         $role  el rol a comprobar
     *
     * @return bool true si lo tiene
     */
    private function isGranted(TokenInterface $token, string $role): bool
    {
        return $this->accessDecisionManager->decide($token, [$role]);
    }
}
