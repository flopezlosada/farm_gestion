<?php

namespace App\Form;

use App\Entity\DonationInitiative;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Alta o cambio de una iniciativa a la que se puede destinar lo que se paga de más. */
class DonationInitiativeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['attr' => ['maxlength' => 120]])
            ->add('description', TextareaType::class, ['required' => false, 'attr' => ['rows' => 3]])
            ->add('open', CheckboxType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DonationInitiative::class]);
    }
}
