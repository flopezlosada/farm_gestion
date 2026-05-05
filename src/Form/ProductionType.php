<?php

namespace App\Form;

use Doctrine\ORM\EntityRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProductionType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('production_date' , TextType::class, array('label' => 'Fecha de producción', 'attr' => array('class' => 'datepicker form-control')))
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
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\Production'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_production';
    }
}
