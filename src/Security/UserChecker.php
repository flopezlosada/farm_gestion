<?php

namespace App\Security;

use App\Entity\User;
use App\Service\AppSettings;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Rechaza el acceso de las cuentas bloqueadas (enabled = false). Symfony no
 * comprueba `enabled` por su cuenta —lo hacía FOSUser, ya retirado—, así que
 * sin este checker el flag de bloqueo no tendría efecto sobre el login.
 *
 * Es además el punto único donde se aplica el feature-flag de acceso de socixs
 * ({@see AppSettings::FEATURE_PARTNER_LOGIN}): se ejecuta en cada autenticación
 * (form_login, magic-link y Google), así que con un solo guard se cierran las
 * tres vías a la vez.
 */
class UserChecker implements UserCheckerInterface
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    /**
     * Comprobaciones previas a validar credenciales: corta si la cuenta está
     * bloqueada o si es un socix y el acceso de socixs aún no está abierto.
     *
     * @param UserInterface $user Usuario que intenta autenticarse.
     * @throws CustomUserMessageAccountStatusException Si la cuenta está bloqueada
     *         o el acceso de socixs está cerrado.
     */
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isEnabled()) {
            throw new CustomUserMessageAccountStatusException(
                'Tu cuenta está bloqueada. Ponte en contacto con la gestión de la cooperativa.'
            );
        }

        // Acceso de socixs cerrado: solo se rechaza a quien no es del equipo NI
        // tiene acceso anticipado concedido a dedo por la administración (el
        // botón "Dar acceso" de la ficha). Así el grifo global sigue cerrado
        // para todo el mundo salvo los socixs autorizados uno a uno.
        //
        // Los TRABAJADORES quedan exentos: este gate es del rollout de socixs y
        // un trabajador (faceta laboral) no es un socix — su acceso a fichar no
        // depende de que el acceso de socixs esté abierto. Quien es socix Y
        // trabajador entra igualmente (es personal de confianza, no el caso
        // masivo que el gate frena).
        if (
            !$this->settings->getBool(AppSettings::FEATURE_PARTNER_LOGIN)
            && !$this->belongsToTeam($user)
            && !$user->isEarlyAccess()
            && $user->getWorker() === null
        ) {
            throw new CustomUserMessageAccountStatusException(
                'El acceso de socixs a la web todavía no está disponible. Te avisaremos en cuanto puedas entrar.'
            );
        }
    }

    /**
     * Comprobaciones posteriores a validar credenciales. No hay ninguna por ahora.
     *
     * @param UserInterface $user Usuario autenticado.
     * @param TokenInterface|null $token Token de la autenticación en curso (la
     *        interfaz lo exigirá en la próxima major de symfony/security-core).
     */
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }

    /**
     * ¿El User pertenece al equipo de gestión (alcanza algún rol de
     * {@see ManagementRoles})?
     *
     * `getRoles()` devuelve los roles ALMACENADOS (más los derivados de sus
     * vínculos), sin la jerarquía de security.yaml, que sólo resuelve el voter
     * durante la autorización. Aquí (preAuth) se expande a mano: a quien escribe
     * en una sección se le guarda sólo el `_EDIT`, y la lectura le llega por
     * jerarquía.
     *
     * @param User $user Usuario a comprobar.
     * @return bool true si entra a /gestion, false si no.
     */
    private function belongsToTeam(User $user): bool
    {
        return ManagementRoles::anyIn($this->roleHierarchy->getReachableRoleNames($user->getRoles()));
    }
}
