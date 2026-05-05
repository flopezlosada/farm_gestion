<?php
/**
 *
 * User: paco
 * Date: 12/03/15
 * Time: 10:18
 */

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class BatchEditType extends BatchType
{

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $batch=$options['batch'];
        $builder
            ->add('purchase_date' , TextType::class, array('label' => 'Fecha de compra', 'attr' => array('class' => 'datepicker form-control')))
            ->add('receipt_date' , TextType::class, array('label' => 'Fecha de recepción del pedido', 'attr' => array('class' => 'datepicker2 form-control')))
            ->add('days_of_life', null, array('label' => "Días de vida"))
            ->add('price', null, array('label' => "Precio de compra del lote"))
            ->add('weight', null, array('label' => "Peso aproximado de un animal en el día de recepción"))
            ->add('note', null, array('label' => "Anotaciones"))
        ;

        if ($batch->getBatchStatus()->getId()==2)
        {
            $builder->add('finalization_date' , TextType::class, array('label' => 'Fecha de finalización', 'attr' => array('class' => 'datepicker3 form-control')));
        }
    }


    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\Batch'
        ));


        $resolver->setRequired([
            'batch',
        ]);
    }

}