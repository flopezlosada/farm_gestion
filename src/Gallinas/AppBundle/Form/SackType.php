<?php

namespace Gallinas\AppBundle\Form;

use Doctrine\ORM\EntityRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;


class SackType extends AbstractType
{
    public $batch;

    public function __construct($batch){
        $this->batch=$batch;//le paso el lote al formulario
    }

    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $batch=$this->batch;
        $builder
            ->add('delivery_date','text', array('label'=>'Fecha de uso del saco', 'attr'=>array('class'=>'datepicker form-control')))
            ->add('purchase',null,array('label'=>"Selecciona el contenido del saco",'query_builder' => function (EntityRepository $repository) use ($batch)
            {
                $qb = $repository->createQueryBuilder('a')
                    ->innerJoin("a.product","p")
                    ->where('a.assigned = :assigned')
                    ->andWhere("p.common_food= :common or p.id in (:ids)")
                    ->orderBy('a.id')
                    ->setParameter('assigned', 0)
                    ->setParameter('common', 1)
                    ->setParameter('ids', array($batch->getSackProductId()));

                return $qb;
            },'property'=>'getNameBatchSack'))
            ->add('weight', null, array('label' => "Peso del saco"))
        ;
    }
    
    /**
     * @param OptionsResolver $resolver
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Gallinas\AppBundle\Entity\Sack'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'gallinas_appbundle_sack';
    }
}
