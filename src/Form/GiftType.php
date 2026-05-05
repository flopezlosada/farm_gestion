<?php

namespace App\Form;

use App\Form\DataTransformer\UserToIntTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GiftType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        if (isset($options["em"]))
        {

            $user_transformer = new UserToIntTransformer($options["em"]);
        }

        $builder
            ->add('gift_date' , TextType::class, array('label' => 'Fecha de regalo', 'attr' => array('class' => 'datepicker form-control')))
            ->add('gift_type', null, array('label' => "Tipo de regalo"))
            ->add('amount', null, array('label' => 'Cantidad'))
            ->add('note', null, array('label' => "Notas"))
            ->add('unity', null, array('label' => "Unidades"))
            ->add('single_price', null, array('label' => "Precio por unidad"))
            ->add('recipient', null, array('label' => "Persona que recibe el regalo"))
            ->add('product', null, array('label' => "Producto regalado"));

        if (isset($options["em"]))
        {
            $builder
                ->add($builder->create('user', HiddenType::class)->addModelTransformer($user_transformer));
        }
    }

    /**
     *  {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\Gift',
            'em' => null
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'app_gift';
    }
}
