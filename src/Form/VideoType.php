<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class VideoType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder

            ->add('translations', 'a2lix_translations_gedmo', array(
                    'translatable_class' => "App\Entity\Video",
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
                        )
                    )
                )
            )
            ->add('video_id' , TextType::class, array('label' => 'Youtube video ID'))

        ;
    }
    
    /**
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\Video'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'appbundle_video';
    }
}
