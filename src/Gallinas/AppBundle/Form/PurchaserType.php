<?php

namespace Gallinas\AppBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

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
     * @param OptionsResolverInterface $resolver
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Gallinas\AppBundle\Entity\Purchaser'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'gallinas_appbundle_purchaser';
    }
}
