<?php

namespace Gallinas\AppBundle\Form;

use Doctrine\ORM\EntityRepository;
use Gallinas\AppBundle\Form\DataTransformer\UserToIntTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class LayType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        if (isset($options["em"])){

            $user_transformer=new UserToIntTransformer($options["em"]);
        }
        $builder
            ->add('lay_date','text', array('label'=>'Fecha de puesta', 'attr'=>array('class'=>'datepicker form-control')))
            ->add('amount', null, array('label'=>'Cantidad'))
            ->add('batch',null,array('label'=>'Indica el lote','query_builder' => function (EntityRepository $repository)
            {
                $qb = $repository->createQueryBuilder('b')
                    ->where('b.batch_status = :status')
                    ->andWhere("b.product= :product")
                    ->orderBy('b.id')
                    ->setParameter('status',1)
                    ->setParameter('product', 6);

                return $qb;
            },'property'=>'getNameBatchLay', 'placeholder'=>''))
        ;
        if (isset($options["em"])){
            $builder->add($builder->create('user', 'hidden')->addModelTransformer($user_transformer));
        }
    }
    
    /**
     * @param OptionsResolverInterface $resolver
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Gallinas\AppBundle\Entity\Lay',
            'em' => null
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'gallinas_appbundle_lay';
    }
}
