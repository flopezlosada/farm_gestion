<?php

namespace App\Form;

use App\Entity\LarPage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form del contenido editable de la portada del LAR ({@see LarPage}): la
 * introducción (TinyMCE), los datos de contacto de la coordinación y la
 * colección dinámica de tarjetas de oferta formativa ({@see LarOfferType}).
 * Todos los campos son opcionales; los que se dejen vacíos no se pintan.
 */
class LarPageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('intro', TextareaType::class, [
                'label' => 'Introducción',
                'required' => false,
                'attr' => ['class' => 'tinymce', 'data-theme' => 'advanced'],
                'help' => 'Texto de presentación del LAR en la portada. Admite formato.',
            ])
            ->add('coordName', TextType::class, [
                'label' => 'Coordinación · nombre',
                'required' => false,
            ])
            ->add('coordPhone', TextType::class, [
                'label' => 'Coordinación · teléfono',
                'required' => false,
            ])
            ->add('coordEmail', EmailType::class, [
                'label' => 'Coordinación · email',
                'required' => false,
                'help' => 'Se muestra en la portada y es el buzón al que llega el formulario de petición de información.',
            ])
            ->add('offers', CollectionType::class, [
                'entry_type' => LarOfferType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'label' => false,
                'prototype' => true,
                'prototype_name' => '__offer__',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LarPage::class,
        ]);
    }
}
