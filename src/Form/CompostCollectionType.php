<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CompostCollectionType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('collect_date' , TextType::class, array('label'=>'Fecha ', 'attr' => array('class' => 'datepicker form-control')))
            ->add('amount',null, array('label'=>'Kilos recogidos'))
            ->add('improper_percentage',null, array('label'=>'Porcentaje de impropios. Indica un número de 0 a 100'))
            ->add('compost_collection_point',null, array('label'=>'Punto de recogida'))
        ;
    }
    
    /**
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\CompostCollection'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_compostcollection';
    }
}
