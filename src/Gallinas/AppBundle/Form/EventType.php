<?php

namespace Gallinas\AppBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class EventType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('title',null,array('label'=>'Título'))
            ->add('content',null,array('label'=>'Descripción'))
            ->add('start_date','text', array('label'=>'Fecha de inicio', 'attr'=>array('class'=>'datepicker form-control')))
            ->add('end_date','text', array('label'=>'Fecha de fin', 'required'=>false, 'attr'=>array('class'=>'datepicker2 form-control')))
            ->add('all_day_event')
        ;
    }
    
    /**
     * @param OptionsResolverInterface $resolver
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Gallinas\AppBundle\Entity\Event'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'gallinas_appbundle_event';
    }
}
