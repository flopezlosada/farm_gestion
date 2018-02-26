<?php

namespace Gallinas\AppBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class SeedWorkType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('surface', null, array('label'=>'Superficie','required'=>false))
            ->add('plant', null, array('label'=>'número de plantas'))
            ->add('tray', null, array('label'=>'número de bandejas'))
            ->add('estimated_date', 'text', array('label'=>'Fecha estimada de siembra/plantación', 'attr' => array('class' => 'datepicker2 form-control')))
            ->add('real_date', 'text', array('label'=>'Fecha real de siembra/plantación', 'attr' => array('class' => 'datepicker form-control')))
            ->add('crop_working', null, array('label'=>'Cultivo'))
            ->add('seed_work_type', null, array('label'=>'Tipo'))
            ->add('content', null, array('label'=>'Descripción'))
            ->add('sector',null,array('label'=>'Sector'))
        ;
    }
    
    /**
     * @param OptionsResolverInterface $resolver
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Gallinas\AppBundle\Entity\SeedWork'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'gallinas_appbundle_seedwork';
    }
}
