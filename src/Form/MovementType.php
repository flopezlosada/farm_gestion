<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MovementType extends AbstractType
{

    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('date',TextType::class, array('label'=>'Fecha', 'attr'=>array('class'=>'datepicker form-control')))
            ->add('sector',null, array('label'=>'Sector al que se dirige'));
    }

    /**
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\Movement'
        ));

        $resolver->setRequired([
            'batch',
        ]);
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_movement';
    }
}
