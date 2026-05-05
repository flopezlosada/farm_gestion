<?php

namespace App\Form;

use App\Form\DataTransformer\UserToIntTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

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
            ->add('sale_date',TextType::class, array('label'=>'Fecha de venta', 'attr'=>array('class'=>'datepicker form-control')))
            ->add('amount', null, array('label' => 'Cantidad'))
            ->add('product', null, array('label' => "Producto vendido"))
            ->add('purchaser', null, array('label' => "Clienta/e"))
            ->add('unity', null, array('label' => "Unidades"))
            ->add('single_price', null, array('label' => "Precio por unidad"))
            ->add('note', null, array('label' => "Notas"))
            ->add('paid', null, array('label' => "Marca esta casilla si la venta está pagada"))
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
            'data_class' => 'App\Entity\Sale',
            'em' => null
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_sale';
    }
}
