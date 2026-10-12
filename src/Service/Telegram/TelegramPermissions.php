<?php

namespace App\Service\Telegram;

use App\Entity\User;
use App\Service\Telegram\Destination\TelegramDestination;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Quién puede usar cada apartado del bot.
 *
 * Un permiso por apartado, independiente del acceso a la web: a un trabajador de
 * la huerta se le puede dejar mandar tickets a contabilidad sin que vea la
 * contabilidad. Se asignan a mano en la ficha de cada cuenta, agrupados por el
 * área de cada apartado ({@see TelegramDestination::section()}).
 *
 * El rol sale de la clave del apartado y no lo escribe cada uno: así no hay dos
 * sitios que puedan decir cosas distintas. Admin puede con todos.
 */
class TelegramPermissions
{
    public function __construct(private readonly RoleHierarchyInterface $roleHierarchy)
    {
    }

    /** El rol que da permiso para usar un apartado: `invoice` → `ROLE_BOT_INVOICE`. */
    public static function roleFor(TelegramDestination $destination): string
    {
        return 'ROLE_BOT_' . strtoupper($destination->key());
    }

    /** Si esta persona puede mandar cosas a este apartado. */
    public function allows(User $user, TelegramDestination $destination): bool
    {
        $roles = $this->roleHierarchy->getReachableRoleNames($user->getRoles());

        return \in_array('ROLE_ADMIN', $roles, true) || \in_array(self::roleFor($destination), $roles, true);
    }
}
