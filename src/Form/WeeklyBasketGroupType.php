<?php

namespace App\Form;

use App\Entity\Node;
use App\Entity\WeeklyBasketGroup;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class WeeklyBasketGroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name', null, array('label'=>'Nombre'))
            ->add('color', ColorType::class, array('label'=>'Color'))
            ->add('node', EntityType::class, [
                'label' => 'Nodo de reparto',
                'class' => Node::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'Sin nodo asignado',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => WeeklyBasketGroup::class,
        ]);
    }
}
