<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GalleryType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder


            ->add('translations', 'a2lix_translations_gedmo', array(
                    'translatable_class' => "App\Entity\Gallery",
                    'fields' => array(
                        'slug'  => array(
                            'display' => false
                        ),
                        'title' => array(
                            'locale_options' => array(            // [3.b]
                                'es' => array(
                                    'label' => 'Título'
                                ),
                                'en' => array(
                                    'label' => 'Title',
                                    'required'=>false
                                ),
                            )
                        ),
                        'content' => array(
                            'attr'=>array(
                                'class'=>'tinymce',
                                'data-theme' => 'custom'
                            ),
                            'locale_options' => array(            // [3.b]
                                'es' => array(
                                    'label' => 'Descripción'
                                ),
                                'en' => array(
                                    'label' => 'Description',
                                    'required'=>false
                                ),
                            )
                        ),

                    )
                )
            )
        ;
    }
    
    /**
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\Gallery'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'appbundle_gallery';
    }
}
