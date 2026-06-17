<?php

namespace App\Form;

use App\Entity\InternshipDetail;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form de los datos de prácticas de una estancia ({@see InternshipDetail}). La
 * estancia a la que pertenece se fija en el controller, no en el form.
 */
class InternshipDetailType extends AbstractType
{
    /** @var array<string,string> Etiqueta => estado del informe. */
    private const REPORT_CHOICES = [
        'Pendiente' => InternshipDetail::REPORT_PENDING,
        'Entregado' => InternshipDetail::REPORT_DELIVERED,
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tutorName', TextType::class, [
                'label' => 'Tutor/a',
                'required' => false,
            ])
            ->add('tutorEmail', TextType::class, [
                'label' => 'Email del tutor/a',
                'required' => false,
            ])
            ->add('agreementReference', TextType::class, [
                'label' => 'Referencia del convenio',
                'required' => false,
                'help' => 'Nº de expediente o referencia del convenio de prácticas.',
            ])
            ->add('reportStatus', ChoiceType::class, [
                'label' => 'Informe de prácticas',
                'choices' => self::REPORT_CHOICES,
                'help' => 'La memoria que pide la universidad. El documento se generará en una fase posterior.',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notas de prácticas',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InternshipDetail::class,
        ]);
    }
}
