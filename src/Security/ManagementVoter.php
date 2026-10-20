<?php

namespace App\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Responde a `is_granted('MANAGEMENT')`: ¿puede esta persona entrar a la zona de
 * gestión? Es la pregunta que hacen el dispatcher de después del login, el
 * conmutador de áreas y la cabecera de la web pública; con el voter ninguno
 * necesita conocer la lista de roles, que vive sólo en {@see ManagementRoles}.
 *
 * Resuelve la jerarquía de security.yaml, así que quien sólo tiene el `_EDIT` de
 * una sección también cuenta.
 */
class ManagementVoter extends Voter
{
    public const MANAGEMENT = 'MANAGEMENT';

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    /**
     * Sólo vota el atributo MANAGEMENT.
     *
     * @param string $attribute
     * @param mixed  $subject
     * @return bool
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::MANAGEMENT;
    }

    /**
     * Concede si los roles de la sesión, con la jerarquía resuelta, alcanzan
     * alguno de gestión.
     *
     * @param string         $attribute
     * @param mixed          $subject
     * @param TokenInterface $token
     * @return bool
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        return ManagementRoles::anyIn($this->roleHierarchy->getReachableRoleNames($token->getRoleNames()));
    }
}
