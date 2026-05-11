<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProviderType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name')
            ->add('contact')
            ->add('address')
            ->add('postal_code')
            ->add('phone')
            ->add('celular')
            ->add('web')
            ->add('email')
            ->add('state')
            ->add('city')
            ->add('country')
        ;
    }
    
    /**
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\Provider'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_provider';
    }
}
