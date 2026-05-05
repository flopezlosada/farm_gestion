<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TaskImageType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('title',null, array('label'=>'Título'))
            ->add('file',null,array(
                "required"=>null===$builder->getData()->getId()?true:false,
                "label"=>"Imagen"
            ))
        ;
    }
    
    /**
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\TaskImage'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_taskimage';
    }
}
