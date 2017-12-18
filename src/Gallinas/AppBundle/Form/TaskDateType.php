<?php

namespace Gallinas\AppBundle\Form;

use Doctrine\ORM\EntityRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class TaskDateType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('title',null, array('label'=>'Título'))
            ->add('content',null, array('label'=>'Contenido'))
            ->add('expected_date','text', array('label'=>'Fecha de realización', 'attr'=>array('class'=>'datepicker form-control', 'required'=>true)))
            ->add('labour',null, array('label'=>'Labor'))
            ->add('crop_workings',null, array('label'=>'Cultivos asociados','expanded'=>true,'multiple'=>true, 'attr'=>array('class'=>'checkbox-inline')))
            ->add('users', null, array('label' => 'Responsables', 'expanded' => true, 'multiple' => true,
                'attr' => array('class' => 'checkbox-inline'), 'query_builder' => function (EntityRepository $repository)
                {
                    $qb = $repository->createQueryBuilder('u')

                        ->where('u.roles LIKE :roles')
                        ->setParameter('roles', '%"ROLE_COOP"%');
                    return $qb;
                }));
    }
    
    /**
     * @param OptionsResolverInterface $resolver
     */
    public function setOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Gallinas\AppBundle\Entity\Task'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'gallinas_appbundle_task';
    }
}


/**
 * 'choices'=>array('01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio',
'07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre')
 * 'choices'=>array('Enero','Febrero','Marzo','Abril','Mayo','Junio',
'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre')
 */