<?php

namespace App\Form;


use Doctrine\ORM\EntityRepository;
use App\Form\DataTransformer\UserToIntTransformer;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

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
            ->add('lay_date',TextType::class, array('label'=>'Fecha de puesta', 'attr'=>array('class'=>'datepicker form-control')))
            ->add('amount', null, array('label'=>'Cantidad'))
            ->add('batch',EntityType::class,array('label'=>'Indica el lote','query_builder' => function (EntityRepository $repository)
            {
                $qb = $repository->createQueryBuilder('b')
                    ->where('b.batch_status = :status')
                    ->andWhere("b.product= :product")
                    ->orderBy('b.id')
                    ->setParameter('status',1)
                    ->setParameter('product', 6);

                return $qb;
            },'choice_label'=>'getNameBatchLay', 'class'=>'App\Entity\Batch','placeholder'=>''))
        ;
        if (isset($options["em"])){
            $builder->add($builder->create('user', HiddenType::class)->addModelTransformer($user_transformer));
        }
    }
    
    /**
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\Lay',
            'em' => null
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_lay';
    }
}
