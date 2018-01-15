<?php

namespace Gallinas\AppBundle\Form;

use Doctrine\ORM\EntityRepository;


use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;

use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

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

            /*->add('zone', 'entity', array('class' => 'Gallinas\AppBundle\Entity\Zone', 'required'=>false,'empty_value'=>"Selecciona zona",'label' => "Zonas de cultivo", 'query_builder' => function (EntityRepository $er)
            {
                return $er->createQueryBuilder('u')
                    ->orderBy('u.name', 'ASC');
            }))


            ->add('sectors', null, array("label" => "Sector"))*/
            ;


    }

    /**
     * @param OptionsResolverInterface $resolver
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Gallinas\AppBundle\Entity\CropWorking'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'gallinas_appbundle_cropworking';
    }
}
