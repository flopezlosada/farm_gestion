<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

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
            ->add('start_date',TextType::class, array('label'=>'Fecha de inicio', 'attr'=>array('class'=>'datepicker form-control')))
            ->add('end_date',TextType::class, array('label'=>'Fecha de fin', 'required'=>false, 'attr'=>array('class'=>'datepicker2 form-control')))
            ->add('all_day_event')
        ;
    }
    
    /**
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\Event'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_event';
    }
}
