<?php

namespace App\Form;

use Doctrine\ORM\EntityRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CulturalWorkType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('date' , TextType::class, array('label'=>'Fecha estimada', 'attr' => array('class' => 'datepicker form-control')))
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
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\CulturalWork'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_culturalwork';
    }
}
