<?php

namespace App\Security;

use App\Entity\User;
use App\Service\AppSettings;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
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
    /**
     * Roles que dan acceso a la zona de gestión (espejo del access_control de
     * `^/gestion` en security.yaml). Un User que tenga al menos uno es "del
     * equipo" y entra siempre; el resto son socixs, sujetos al feature-flag de
     * acceso. ROLE_ADMIN es alias de todos, pero se almacena explícito en el
     * User, así que basta con mirar los roles directos.
     */
    private const TEAM_ROLES = [
        'ROLE_ADMIN',
        'ROLE_GESTION_GRANJA',
        'ROLE_GESTION_SOCIXS',
        'ROLE_GESTION_REPARTO',
        'ROLE_BLOG',
        'ROLE_GESTION_ENCUESTAS',
    ];

    public function __construct(
        private readonly AppSettings $settings,
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

        if (!$this->settings->getBool(AppSettings::FEATURE_PARTNER_LOGIN) && !$this->belongsToTeam($user)) {
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
     * ¿El User pertenece al equipo de gestión (tiene algún rol de {@see self::TEAM_ROLES})?
     *
     * OJO: `getRoles()` devuelve los roles DIRECTOS del usuario (los de BBDD +
     * ROLE_USER), NO los derivados de la jerarquía de `security.yaml` — ésa la
     * resuelve el RoleHierarchyVoter durante la AUTORIZACIÓN, no aquí (preAuth).
     * Hoy es correcto porque los roles de equipo se asignan directamente; si en
     * el futuro un rol de equipo pasara a derivarse sólo por jerarquía (p. ej.
     * ROLE_X → ROLE_GESTION_*), este chequeo no lo vería y habría que inyectar
     * el RoleHierarchyInterface y expandir aquí.
     *
     * @param User $user Usuario a comprobar.
     * @return bool true si entra a /gestion, false si es socix puro.
     */
    private function belongsToTeam(User $user): bool
    {
        return array_intersect(self::TEAM_ROLES, $user->getRoles()) !== [];
    }
}
