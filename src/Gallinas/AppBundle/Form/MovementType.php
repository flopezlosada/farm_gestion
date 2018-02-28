<?php

namespace Gallinas\AppBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class MovementType extends AbstractType
{
    public $batch;

    public function __construct($batch)
    {
        $this->batch = $batch;//le paso el lote al formulario
    }

    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('date','text', array('label'=>'Fecha', 'attr'=>array('class'=>'datepicker form-control')))
            ->add('sector',null, array('label'=>'Sector al que se dirige'));
    }

    /**
     * @param OptionsResolverInterface $resolver
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Gallinas\AppBundle\Entity\Movement'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'gallinas_appbundle_movement';
    }
}
