<?php

namespace Gallinas\AppBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class CompostCollectionType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('collect_date', 'text', array('label'=>'Fecha ', 'attr' => array('class' => 'datepicker form-control')))
            ->add('amount',null, array('label'=>'Kilos recogidos'))
            ->add('improper_percentage',null, array('label'=>'Porcentaje de impropios. Indica un número de 0 a 100'))
            ->add('compost_collection_point',null, array('label'=>'Punto de recogida'))
        ;
    }
    
    /**
     * @param OptionsResolverInterface $resolver
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Gallinas\AppBundle\Entity\CompostCollection'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'gallinas_appbundle_compostcollection';
    }
}
