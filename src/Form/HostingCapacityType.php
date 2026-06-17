<?php

namespace App\Form;

use App\Entity\HostingCapacity;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form para fijar el aforo OPERATIVO de un mes ({@see HostingCapacity}). El
 * año y el mes se fijan en el controller (se edita un mes concreto del
 * calendario de aforo), aquí sólo se tocan apertura y plazas.
 */
class HostingCapacityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('open', CheckboxType::class, [
                'label' => 'El albergue está abierto este mes',
                'required' => false,
                'help' => 'Si lo cierras, no se admite ninguna estancia ese mes (aforo 0), y se pinta distinto en el calendario.',
            ])
            ->add('capacity', IntegerType::class, [
                'label' => 'Plazas (aforo operativo)',
                'help' => 'Camas que ofrecéis ese mes. Debe ser ≤ el aforo físico de la casa.',
                'attr' => ['min' => 0],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => HostingCapacity::class,
        ]);
    }
}
