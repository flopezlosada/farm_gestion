<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class HarvestType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('harvest_date', TextType::class, array('label' => 'Fecha de cosecha', 'attr' => array('class' => 'datepicker form-control')))
            ->add('amount', null, array('label' => 'Cantidad'))
            ->add('crop_working', null, array('label' => 'Cultivo'))
        ;
    }
    
    /**
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\Harvest'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_harvest';
    }
}
