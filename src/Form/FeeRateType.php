<?php

namespace App\Form;

use App\Entity\FeeConcept;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un precio nuevo para un concepto desde una fecha. No edita ninguno: el precio de
 * antes queda como lo que valía hasta ese día.
 */
class FeeRateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('concept', EnumType::class, [
                'class' => FeeConcept::class,
                'choice_label' => static fn (FeeConcept $c): string => $c->label(),
                'group_by' => static fn (FeeConcept $c): string => $c->isEgg() ? 'Huevos' : 'Verdura',
                'constraints' => [new Assert\NotNull(message: 'Elige qué cambia de precio.')],
            ])
            ->add('price', NumberType::class, [
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0.01', 'inputmode' => 'decimal'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Escribe el precio.'),
                    new Assert\Positive(message: 'El precio tiene que ser mayor que cero.'),
                ],
            ])
            ->add('validFrom', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotNull(message: 'Escribe desde cuándo vale.')],
            ]);
    }
}
