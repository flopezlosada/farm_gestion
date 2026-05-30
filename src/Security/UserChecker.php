<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Rechaza el acceso de las cuentas bloqueadas (enabled = false). Symfony no
 * comprueba `enabled` por su cuenta —lo hacía FOSUser, ya retirado—, así que
 * sin este checker el flag de bloqueo no tendría efecto sobre el login.
 *
 * Se ejecuta en cada autenticación (form_login y magic-link), antes de dar
 * por buenas las credenciales.
 */
class UserChecker implements UserCheckerInterface
{
    /**
     * Comprobaciones previas a validar credenciales: corta si la cuenta está
     * bloqueada.
     *
     * @param UserInterface $user Usuario que intenta autenticarse.
     * @throws CustomUserMessageAccountStatusException Si la cuenta está bloqueada.
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
    }

    /**
     * Comprobaciones posteriores a validar credenciales. No hay ninguna por ahora.
     *
     * @param UserInterface $user Usuario autenticado.
     */
    public function checkPostAuth(UserInterface $user): void
    {
    }
}
