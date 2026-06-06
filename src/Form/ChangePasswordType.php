<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Form para que un socix cambie su propia contraseña desde /panel/perfil.
 * Pide la contraseña actual + la nueva (repetida). El controller valida
 * que la actual es correcta antes de aplicar el cambio.
 */
class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Contraseña actual',
                'mapped' => false,
                'constraints' => [new NotBlank(['message' => 'Indica tu contraseña actual.'])],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => ['label' => 'Nueva contraseña'],
                'second_options' => ['label' => 'Repetir nueva contraseña'],
                'invalid_message' => 'Las contraseñas nuevas no coinciden.',
                'constraints' => [
                    new NotBlank(['message' => 'Indica la nueva contraseña.']),
                    new Length(['min' => 6, 'minMessage' => 'Al menos 6 caracteres.']),
                ],
            ]);
    }
}
