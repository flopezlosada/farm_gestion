<?php

namespace App\Form;

use App\Entity\PaySchedule;
use App\Entity\WorkerSalary;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Un tramo de sueldo de un trabajador. El trabajador no se elige aquí: viene de la
 * ficha desde la que se abre.
 */
class WorkerSalaryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('validFrom', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('validTo', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('annualGross', NumberType::class, [
                'scale' => 2,
                'html5' => true,
                'input' => 'string',
                'attr' => ['step' => '0.01', 'min' => '0'],
            ])
            ->add('payments', EnumType::class, [
                'class' => PaySchedule::class,
                'choice_label' => static fn (PaySchedule $schedule) => $schedule->label(),
                'expanded' => true,
            ])
            ->add('employerSsRate', NumberType::class, [
                'scale' => 2,
                'html5' => true,
                'input' => 'string',
                'attr' => ['step' => '0.01', 'min' => '0', 'max' => '100'],
            ])
            ->add('submit', SubmitType::class, ['label' => 'Guardar']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => WorkerSalary::class]);
    }
}
