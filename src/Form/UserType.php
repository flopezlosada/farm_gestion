<?php

namespace App\Form;

use App\Entity\Partner;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserType extends AbstractType
{
    /**
     * Catálogo de roles asignables desde la UI de admin. ROLE_USER NO está
     * porque se añade automáticamente (en User::getRoles()), y ROLE_PARTNER
     * suele ir junto a tener un Partner vinculado abajo.
     */
    public const ROLE_CHOICES = [
        'Administradorx (acceso a todo)' => 'ROLE_ADMIN',
        'Gestión de granja' => 'ROLE_GESTION_GRANJA',
        'Gestión de socixs' => 'ROLE_GESTION_SOCIXS',
        'Gestión de reparto' => 'ROLE_GESTION_REPARTO',
        'Blog y comunicación' => 'ROLE_BLOG',
        'Socix (panel propio)' => 'ROLE_PARTNER',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, ['label' => 'Nombre de usuaria'])
            ->add('email', EmailType::class, ['label' => 'Email']);

        // El campo de contraseña solo aparece en alta (require_password=true).
        // En edición admin NO se expone: el reseteo lo hace el propio usuario
        // por el flujo de magic-link en /login/forgot, no admin a mano.
        if ($options['require_password']) {
            $builder->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => ['label' => 'Contraseña'],
                'second_options' => ['label' => 'Repetir contraseña'],
                'invalid_message' => 'Las contraseñas no coinciden.',
                'required' => true,
                'constraints' => [
                    new NotBlank(),
                    new Length(['min' => 6]),
                ],
            ]);
        }

        $builder
            ->add('roles', ChoiceType::class, [
                'label' => 'Roles',
                'choices' => self::ROLE_CHOICES,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ]);

        // El vínculo con socix sólo se establece al crear la cuenta. Es 1:1 y
        // permanente (una cuenta es de una persona concreta), así que en
        // edición NO se expone: se muestra como solo lectura en la plantilla.
        if ($options['include_partner']) {
            $builder->add('partner', EntityType::class, [
                'label' => 'Socix vinculadx',
                'class' => Partner::class,
                'choice_label' => fn (Partner $p) => $p->getName() . ' ' . $p->getSurname(),
                'required' => false,
                'placeholder' => '— sin vínculo —',
                'help' => 'Solo necesario si esta cuenta corresponde a una persona socia y va a usar el panel /panel.',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'require_password' => true,
            'include_partner' => true,
        ]);
        $resolver->setAllowedTypes('require_password', 'bool');
        $resolver->setAllowedTypes('include_partner', 'bool');
    }
}
