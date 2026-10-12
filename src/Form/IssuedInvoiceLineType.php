<?php

namespace App\Form;

use App\Entity\IssuedInvoiceLine;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Una línea de una factura emitida: qué se cobra, cuánto sin IVA y a qué tipo.
 */
class IssuedInvoiceLineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('concept', TextareaType::class, ['attr' => ['rows' => 2, 'maxlength' => 1000]])
            ->add('amount', NumberType::class, [
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01'],
            ])
            ->add('vatRate', ChoiceType::class, [
                'choices' => array_combine(
                    array_map(static fn (string $rate): string => $rate === '0' ? 'Exenta (0 %)' : $rate . ' %', IssuedInvoiceLine::RATES),
                    IssuedInvoiceLine::RATES,
                ),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => IssuedInvoiceLine::class]);
    }
}
