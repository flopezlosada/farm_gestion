<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * Política única de contraseñas de la aplicación. Vive aquí (y no repartida por
 * cada formulario) para que las tres vías que fijan contraseña —alta de cuenta
 * desde admin ({@see \App\Form\UserType}), cambio del socix
 * ({@see \App\Form\ChangePasswordType}) y (re)establecimiento forzado
 * ({@see \App\Controller\SecurityController::accountPassword})— compartan
 * exactamente las mismas reglas y no vuelvan a desincronizarse.
 *
 * Reglas (deliberadamente suaves por la brecha digital del colectivo):
 *   - al menos 8 caracteres,
 *   - al menos una letra,
 *   - al menos un número.
 *
 * NO se exige mayúscula ni símbolo a propósito: la fricción no compensa para
 * usuarixs poco habituadxs al software, y la vía principal de acceso es el
 * magic-link, no la contraseña. La regla mata el "12345678" puro (no tiene
 * letra) y el "password" puro (no tiene número).
 *
 * Deuda anotada para despliegue: añadir aquí
 * {@see \Symfony\Component\Validator\Constraints\NotCompromisedPassword} para
 * rechazar contraseñas filtradas conocidas. Hace una llamada HTTPS saliente a
 * api.pwnedpasswords.com; antes de activarlo hay que confirmar que el hosting
 * (LAMP solo-FTP) puede salir por HTTPS, o el alta de cuentas se caería.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
class StrongPassword extends Compound
{
    /**
     * @param array<string,mixed> $options
     * @return array<int,\Symfony\Component\Validator\Constraint>
     */
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank(message: 'Indica una contraseña.'),
            new Assert\Length(
                min: 8,
                minMessage: 'La contraseña debe tener al menos {{ limit }} caracteres.',
            ),
            new Assert\Regex(
                pattern: '/\p{L}/u',
                message: 'La contraseña debe incluir al menos una letra.',
            ),
            new Assert\Regex(
                pattern: '/\d/',
                message: 'La contraseña debe incluir al menos un número.',
            ),
        ];
    }
}
