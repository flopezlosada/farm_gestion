<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PurchaserType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name',null, array('label'=>'Nombre'))
        ->add('regular',null, array('label'=>'Marca esta casilla si estx clientx compra huevos de forma regular'))
        ->add('often_buying_eggs',null, array('label'=>'Si esta/e clientx va a comprar huevos de forma regular, indica la frecuencia en días de la compra'))
        ;
    }
    
    /**
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\Purchaser'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_purchaser';
    }
}
