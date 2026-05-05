<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ImageType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('file',null,array(
                "required"=>null===$builder->getData()->getId()?true:false,
                "label"=>"Imagen"
            ))
            ->add('title',null,array('label'=>'Título'))
        ;
    }
    
    /**
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\Image'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'appbundle_image';
    }
}
