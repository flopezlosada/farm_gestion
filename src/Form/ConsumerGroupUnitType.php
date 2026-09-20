<?php

namespace App\Form;

use App\Entity\ConsumerGroupUnit;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form de una unidad de venta del grupo de consumo.
 */
class ConsumerGroupUnitType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Unidad', 'attr' => ['placeholder' => 'kg, L, docena, garrafa de 5 L…']])
            ->add('active', CheckboxType::class, ['label' => 'Activa', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ConsumerGroupUnit::class]);
    }
}
