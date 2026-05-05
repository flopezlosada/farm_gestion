<?php

namespace App\Form;

use Doctrine\ORM\EntityRepository;


use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;

use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CropWorkingEditType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {


        $builder
            ->add('surface', null, array('label' => "Superficie de cultivo (m2)"))
            ->add('estimated_production', null, array('label' => "Producción estimada (kg)"))
            ->add('planting_pattern', null, array('label' => "Marco de plantación (cm x cm)"))
            ->add('content', null, array('label' => "Observaciones"))

            ->add('name', null, array('label' => "Descripción"))

            /*->add('zone', 'entity', array('class' => 'App\Entity\Zone', 'required'=>false,'empty_value'=>"Selecciona zona",'label' => "Zonas de cultivo", 'query_builder' => function (EntityRepository $er)
            {
                return $er->createQueryBuilder('u')
                    ->orderBy('u.name', 'ASC');
            }))


            ->add('sectors', null, array("label" => "Sector"))*/
            ;


    }

    /**
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\CropWorking'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_cropworking';
    }
}
