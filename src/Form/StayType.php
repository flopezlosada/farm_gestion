<?php

namespace App\Form;

use App\Entity\Stay;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form para crear/editar una {@see Stay} (estancia en el albergue). El helper se
 * fija en el controller (la estancia siempre se crea desde la ficha de alguien),
 * no en el form. El guard de aforo NO vive aquí: lo aplica el controller con
 * {@see \App\Service\Hosting\HostingAvailabilityChecker} antes de persistir.
 */
class StayType extends AbstractType
{
    /** @var array<string,string> Etiqueta humana => valor de estado persistido. */
    private const STATUS_CHOICES = [
        'Solicitada' => Stay::STATUS_REQUESTED,
        'Confirmada' => Stay::STATUS_CONFIRMED,
        'Cancelada' => Stay::STATUS_CANCELLED,
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('arrivalDate', DateType::class, [
                'label' => 'Llegada',
                'widget' => 'single_text',
            ])
            ->add('departureDate', DateType::class, [
                'label' => 'Salida (estimada)',
                'widget' => 'single_text',
                'help' => 'Aunque sea tentativa, hace falta un horizonte. Marca abajo si ya está confirmada.',
            ])
            ->add('departureConfirmed', CheckboxType::class, [
                'label' => 'La salida está confirmada',
                'required' => false,
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Estado',
                'choices' => self::STATUS_CHOICES,
                'help' => 'Sólo las confirmadas ocupan plaza y cuentan para el aforo.',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notas',
                'required' => false,
                'help' => 'En qué trabaja, detalles de la estancia, etc.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Stay::class,
        ]);
    }
}
