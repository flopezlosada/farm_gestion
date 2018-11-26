<?php

namespace Gallinas\AppBundle\Form;

use Doctrine\ORM\EntityRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class ProductionType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('production_date', 'text', array('label' => 'Fecha de producción', 'attr' => array('class' => 'datepicker form-control')))
            ->add('note',null,array('label'=>'Anotaciones'))
            ->add('amount', null, array('label' => 'Cantidad (en kg)'))
            ->add('crop_working', null, array('label' => 'Cultivo', 'query_builder' => function (EntityRepository $er)
            {
                return $er->createQueryBuilder('u')
                    ->where("u.finish = 0")
                    ->orderBy('u.name', 'ASC');
            },));
    }

    /**
     * @param OptionsResolverInterface $resolver
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Gallinas\AppBundle\Entity\Production'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'gallinas_appbundle_production';
    }
}
