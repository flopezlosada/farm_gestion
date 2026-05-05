<?php

namespace App\Form;

use App\Form\DataTransformer\UserToIntTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

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
            ->add('collect_date' , TextType::class, array('label' => 'Fecha de reparto', 'attr' => array('class' => 'datepicker form-control')))
            ->add('amount', null, array('label' => 'Cantidad'))
            ->add('product', null, array('label' => "Producto repartido"))
            ->add('unity', null, array('label' => "Unidades"));


        if (isset($options["em"]))
        {
            $builder

                ->add($builder->create('user', HiddenType::class)->addModelTransformer($user_transformer));
        } else
        {
            $builder->add('user', null, array('label' => 'Persona que recibe el reparto'));
        }
    }

    /**
     *  {@inheritdoc}
     */
    public
    function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'App\Entity\Collect',
            'em' => null
        ));
    }

    /**
     * @return string
     */
    public
    function getName()
    {
        return 'app_collect';
    }
}
