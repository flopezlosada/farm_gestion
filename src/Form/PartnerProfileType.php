<?php

namespace App\Form;

use App\Entity\Partner;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form que un socix usa para editar sus propios datos desde /panel/perfil.
 *
 * Es un subset reducido de PartnerType:
 * - Edita identidad y contacto.
 * - NO toca: forma de pago, grupo de recogida, fecha de inscripción,
 *   consumo de cárnicos, ficha de socix. Eso lo gestiona admin.
 * - El email aquí ES el del Partner (campo de contacto), distinto del
 *   email del User (que sirve para el login).
 */
class PartnerProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nombre'])
            ->add('surname', TextType::class, ['label' => 'Apellidos'])
            ->add('DNI', TextType::class, ['label' => 'DNI', 'required' => false])
            ->add('celular', TextType::class, ['label' => 'Teléfono', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'Email de contacto', 'required' => false])
            ->add('address', TextType::class, ['label' => 'Dirección', 'required' => false])
            ->add('state', TextType::class, ['label' => 'Provincia', 'required' => false])
            ->add('city', TextType::class, ['label' => 'Municipio', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Partner::class]);
    }
}
