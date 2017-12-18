<?php

namespace Gallinas\AppBundle\Form;

use Gallinas\AppBundle\Form\DataTransformer\UserToIntTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

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
            ->add('gift_date', 'text', array('label' => 'Fecha de regalo', 'attr' => array('class' => 'datepicker form-control')))
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
                ->add($builder->create('user', 'hidden')->addModelTransformer($user_transformer));
        }
    }

    /**
     * @param OptionsResolverInterface $resolver
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Gallinas\AppBundle\Entity\Gift',
            'em' => null
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'gallinas_appbundle_gift';
    }
}
