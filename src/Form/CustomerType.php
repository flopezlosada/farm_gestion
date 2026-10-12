<?php

namespace App\Form;

use App\Entity\Customer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * La ficha de un cliente: lo que va en la cabecera de sus facturas y lo que pide
 * que se diga en todas ellas.
 */
class CustomerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextareaType::class, ['attr' => ['rows' => 2, 'maxlength' => 255]])
            ->add('taxId', TextType::class, ['required' => false])
            ->add('address', TextType::class, ['required' => false])
            ->add('postalCode', TextType::class, ['required' => false, 'attr' => ['inputmode' => 'numeric', 'maxlength' => 10]])
            ->add('town', TextType::class, ['required' => false])
            ->add('province', TextType::class, ['required' => false])
            ->add('email', EmailType::class, ['required' => false])
            ->add('invoiceNote', TextareaType::class, ['required' => false, 'attr' => ['rows' => 4, 'maxlength' => 1000]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Customer::class]);
    }
}
