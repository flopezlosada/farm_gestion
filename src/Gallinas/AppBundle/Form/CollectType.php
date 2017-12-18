<?php

namespace Gallinas\AppBundle\Form;

use Gallinas\AppBundle\Form\DataTransformer\UserToIntTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class CollectType extends AbstractType
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
            ->add('collect_date', 'text', array('label' => 'Fecha de reparto', 'attr' => array('class' => 'datepicker form-control')))
            ->add('amount', null, array('label' => 'Cantidad'))
            ->add('product', null, array('label' => "Producto repartido"))
            ->add('unity', null, array('label' => "Unidades"));


        if (isset($options["em"]))
        {
            $builder

                ->add($builder->create('user', 'hidden')->addModelTransformer($user_transformer));
        } else
        {
            $builder->add('user', null, array('label' => 'Persona que recibe el reparto'));
        }
    }

    /**
     * @param OptionsResolverInterface $resolver
     */
    public
    function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Gallinas\AppBundle\Entity\Collect',
            'em' => null
        ));
    }

    /**
     * @return string
     */
    public
    function getName()
    {
        return 'gallinas_appbundle_collect';
    }
}
