<?php

namespace Gallinas\AppBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class RecipientType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name')
            ->add('regular',null, array('label'=>'Marca esta casilla si a estx agraciadx le regalamos huevos de forma regular'))
            ->add('often_buying_eggs',null, array('label'=>'Si a esta/e agraciadx le vamos a  regalar huevos de forma regular, indica la frecuencia en días del regalo'))

        ;
    }
    
    /**
     * @param OptionsResolverInterface $resolver
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Gallinas\AppBundle\Entity\Recipient'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'gallinas_appbundle_recipient';
    }
}
