<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CulturalWorkTypeType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        // 'created' y 'updated' son Gedmo Timestampable y se gestionan
        // automáticamente — no tiene sentido exponerlos al formulario.
        $builder
            ->add('title')
        ;
    }
    
    /**
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\CulturalWorkType'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_culturalworktype';
    }
}
