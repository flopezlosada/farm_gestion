<?php

namespace Gallinas\AppBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class FowlType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('put_down_date', 'text', array('label' => 'Fecha de sacrificio/muerte', 'attr' => array('class' => 'datepicker form-control')))
            ->add('put_down_weight',null, array('label' => "Peso vivo al sacrificio (Kg)"))
            ->add('carcass_weight',null, array('label' => "Peso en canal (Kg)"))
            ->add('sale_price',null, array('label' => "Precio de venta en €/Kg"))
            ->add('note',null, array('label' => "Notas"))
            ->add('fowl_status',null, array('label' => "Estado del animal"))
            ->add('fowl_destination',null, array('label' => "Destino del animal"))
            ->add('fowl_purchaser',null, array('label' => "Cliente"))
            ->add('fowl_recipient',null, array('label' => "Agraciada/o"))
            ->add('fowl_user',null, array('label' => "Repartido a "))
        ;
    }
    
    /**
     * @param OptionsResolverInterface $resolver
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Gallinas\AppBundle\Entity\Fowl'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'gallinas_appbundle_fowl';
    }
}
