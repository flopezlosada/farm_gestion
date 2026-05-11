<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ZoneType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        // 'crop_workings' retirado: la entidad Zone no expone esa propiedad
        // (la relación con cultivos va a través de los sectores).
        $builder
            ->add('name')
        ;
    }
    
    /**
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\Zone'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_zone';
    }
}
