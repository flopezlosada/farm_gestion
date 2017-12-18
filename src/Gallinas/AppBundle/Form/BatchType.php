<?php

namespace Gallinas\AppBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class BatchType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('purchase_date', 'text', array('label' => 'Fecha de compra', 'attr' => array('class' => 'datepicker form-control')))
            ->add('receipt_date', 'text', array('label' => 'Fecha de recepción del pedido', 'attr' => array('class' => 'datepicker2 form-control')))
            ->add('days_of_life', null, array('label' => "Días de vida"))
            ->add('price', null, array('label' => "Precio de compra del lote"))
            ->add('amount', null, array('label' => "Cantidad de animales"))
            ->add('weight', null, array('label' => "Peso aproximado de un animal en el día de recepción"))
            ->add('note', null, array('label' => "Anotaciones"))
            ->add('product', null, array('label' => "Tipo de Animal"))
        ;
    }

    /**
     * @param OptionsResolverInterface $resolver
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Gallinas\AppBundle\Entity\Batch'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'gallinas_appbundle_batch';
    }
}