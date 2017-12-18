<?php

namespace Gallinas\AppBundle\Form;

use Doctrine\ORM\EntityRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class CulturalWorkType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('date', 'text', array('label'=>'Fecha estimada', 'attr' => array('class' => 'datepicker form-control')))
            ->add('content', null, array('label'=>'Descripción'))
            ->add('cultural_work_type', null, array('label'=>'Tipo de trabajo'))
            ->add('crop_workings', null, array('label' => 'Cultivo(s)', 'expanded'=>'true', 'query_builder' => function (EntityRepository $er)
            {
                return $er->createQueryBuilder('u')
                    ->where("u.finish = 0")
                    ->orderBy('u.name', 'ASC');
            },))
            ->add('sectors',null,array('label'=>'Sectores afectados', 'expanded'=>'true'))
        ;
    }
    
    /**
     * @param OptionsResolverInterface $resolver
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Gallinas\AppBundle\Entity\CulturalWork'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'gallinas_appbundle_culturalwork';
    }
}
