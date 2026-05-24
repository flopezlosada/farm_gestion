<?php

namespace App\Form;

use Doctrine\ORM\EntityRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;


class TaskType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('title')
            ->add('content')
            ->add('labour')
            ->add('crop_workings', null, array('label' => "Cultivos asociados", 'expanded' => true, 'multiple' => true))
            ->add('users', null, array('label' => 'Responsables', 'expanded' => true, 'multiple' => true,
                'attr' => array('class' => 'checkbox-inline'), 'query_builder' => function (EntityRepository $repository)
                {
                    $qb = $repository->createQueryBuilder('u')
                        ->where('u.roles LIKE :roles')
                        ->setParameter('roles', '%"ROLE_GESTION_GRANJA"%');
                    return $qb;
                }));
    }

    /**
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\Task'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_task';
    }
}
