<?php

namespace App\Form;

use App\Entity\PartnerEggShare;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PartnerEggShareType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name')
            ->add('created')
            ->add('updated')
            ->add('start_date')
            ->add('end_date')
            ->add('month_price')
            ->add('partner')
            ->add('egg_share')
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => PartnerEggShare::class,
        ]);
    }
}
