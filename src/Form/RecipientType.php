<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

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
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\Recipient'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_recipient';
    }
}
