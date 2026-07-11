<?php

namespace App\Form;

use App\Entity\LarOffer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form de una tarjeta de oferta formativa ({@see LarOffer}), usado como entrada
 * de la colección dinámica de la portada del LAR ({@see LarPageType}).
 */
class LarOfferType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Título',
                'required' => false,
            ])
            ->add('hours', TextType::class, [
                'label' => 'Duración',
                'required' => false,
                'help' => 'Ej.: «50 h · de lunes a viernes».',
            ])
            ->add('body', TextareaType::class, [
                'label' => 'Descripción',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LarOffer::class,
        ]);
    }
}
