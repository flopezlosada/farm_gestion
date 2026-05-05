<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BatchType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('purchase_date' , TextType::class, array('label' => 'Fecha de compra', 'attr' => array('class' => 'datepicker form-control')))
            ->add('receipt_date' , TextType::class, array('label' => 'Fecha de recepción del pedido', 'attr' => array('class' => 'datepicker2 form-control')))
            ->add('days_of_life', null, array('label' => "Días de vida"))
            ->add('price', null, array('label' => "Precio de compra del lote"))
            ->add('amount', null, array('label' => "Cantidad de animales"))
            ->add('weight', null, array('label' => "Peso aproximado de un animal en el día de recepción"))
            ->add('note', null, array('label' => "Anotaciones"))
            ->add('product', null, array('label' => "Tipo de Animal"))
        ;
    }

    /**
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\Batch'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_batch';
    }
}