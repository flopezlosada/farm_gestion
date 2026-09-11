<?php

namespace App\Service\Notification;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Quién, del equipo, tiene un permiso. La respuesta única a «¿a quién de dentro
 * hay que avisar de esto?».
 *
 * EXISTE PORQUE LA FORMA EVIDENTE DE HACERLO ESTÁ MAL Y FALLA EN SILENCIO.
 * Buscar el rol en la columna `roles` con un LIKE sólo encuentra a quien lo
 * tiene escrito LITERALMENTE, y en esta aplicación casi nadie lo tiene: se
 * llega a la mayoría de permisos por jerarquía (ROLE_ADMIN →
 * ROLE_GESTION_SOCIXS_EDIT → ROLE_GESTION_SOCIXS). Un aviso resuelto así no le
 * llega a nadie y la tarea que lo manda sigue saliendo en verde, que es el
 * peor modo de fallo que tiene este sistema.
 *
 * La jerarquía vive en `security.yaml` y no en la base, así que no se puede
 * resolver en DQL: se traen las cuentas habilitadas y se filtra aquí, con el
 * mismo servicio de Symfony que usa el firewall. Son unas decenas de filas.
 */
class StaffAudience
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    /**
     * Las cuentas habilitadas que alcanzan ese rol, por jerarquía o por
     * tenerlo puesto.
     *
     * @param string $role Rol buscado, p. ej. "ROLE_ADMIN".
     * @return list<User>
     */
    public function withRole(string $role): array
    {
        $found = [];

        foreach ($this->users->findEnabled() as $user) {
            if (\in_array($role, $this->roleHierarchy->getReachableRoleNames($user->getRoles()), true)) {
                $found[] = $user;
            }
        }

        return $found;
    }

    /**
     * Las direcciones de correo de quienes alcanzan ese rol, sin repetir y sin
     * las vacías.
     *
     * Se devuelven ya limpias porque quien manda un aviso interno no tiene por
     * qué saber que una cuenta puede no tener correo: si eso se resolviera en
     * cada envío, en algún sitio acabaría colándose una dirección vacía y el
     * correo fallaría entero.
     *
     * @param string $role Rol buscado.
     * @return list<string>
     */
    public function emailsWithRole(string $role): array
    {
        $emails = [];

        foreach ($this->withRole($role) as $user) {
            $email = trim((string) $user->getEmail());
            if ($email !== '') {
                $emails[$email] = true;
            }
        }

        return array_keys($emails);
    }
}
