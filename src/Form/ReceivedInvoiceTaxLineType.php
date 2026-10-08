<?php

namespace App\Form;

use App\Entity\ReceivedInvoiceTaxLine;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Una línea del desglose de IVA en la revisión de una factura: base, tipo y cuota.
 */
class ReceivedInvoiceTaxLineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $money = ['input' => 'string', 'scale' => 2, 'html5' => true, 'required' => true, 'attr' => ['step' => '0.01']];

        $builder
            ->add('base', NumberType::class, array_replace($money, ['attr' => ['step' => '0.01', 'data-inv-base' => '1']]))
            ->add('rate', NumberType::class, array_replace($money, ['attr' => ['step' => '0.01', 'min' => '0', 'max' => '100', 'data-inv-rate' => '1']]))
            ->add('taxAmount', NumberType::class, array_replace($money, ['attr' => ['step' => '0.01', 'data-inv-tax' => '1']]));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ReceivedInvoiceTaxLine::class]);
    }
}
