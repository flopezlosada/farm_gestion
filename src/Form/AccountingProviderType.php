<?php

namespace App\Form;

use App\Entity\Provider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * La ficha de un proveedor vista desde contabilidad: lo que hace falta para
 * reconocerle en una factura, pagarle y declararle en el 347.
 *
 * No reutiliza el formulario del comercio de la granja ({@see ProviderType}) porque
 * ése pide municipio y provincia como relaciones con las tablas geográficas, y aquí
 * van en texto, tal como los imprime la factura.
 */
class AccountingProviderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class)
            ->add('taxId', TextType::class, ['required' => false])
            ->add('iban', TextType::class, ['required' => false])
            ->add('address', TextType::class, ['required' => false])
            ->add('postal_code', TextType::class, ['required' => false, 'attr' => ['inputmode' => 'numeric', 'maxlength' => 5]])
            ->add('town', TextType::class, ['required' => false])
            ->add('province', TextType::class, ['required' => false])
            ->add('contact', TextType::class, ['required' => false])
            ->add('phone', TextType::class, ['required' => false])
            ->add('email', EmailType::class, ['required' => false])
            ->add('web', UrlType::class, ['required' => false, 'default_protocol' => 'https'])
            ->add('submit', SubmitType::class, ['label' => 'Guardar']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Provider::class]);
    }
}
