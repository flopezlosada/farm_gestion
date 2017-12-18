<?php

namespace Gallinas\AppBundle\Form;

use Gallinas\AppBundle\Form\DataTransformer\UserToIntTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class SaleType extends AbstractType
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
            ->add('sale_date','text', array('label'=>'Fecha de venta', 'attr'=>array('class'=>'datepicker form-control')))
            ->add('amount', null, array('label' => 'Cantidad'))
            ->add('product', null, array('label' => "Producto vendido"))
            ->add('purchaser', null, array('label' => "Clienta/e"))
            ->add('unity', null, array('label' => "Unidades"))
            ->add('single_price', null, array('label' => "Precio por unidad"))
            ->add('note', null, array('label' => "Notas"))
            ->add('paid', null, array('label' => "Marca esta casilla si la venta está pagada"))
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
            'data_class' => 'Gallinas\AppBundle\Entity\Sale',
            'em' => null
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'gallinas_appbundle_sale';
    }
}
