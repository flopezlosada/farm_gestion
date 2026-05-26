<?php

namespace App\Form;

use App\Entity\Node;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form para crear/editar un Node (sitio físico de reparto).
 * Sub-fase 8.8a (2026-05-26).
 */
class NodeType extends AbstractType
{
    /**
     * Día ISO 1-7 mapeado a nombre humano.
     *
     * @var array<string,int>
     */
    private const WEEKDAY_CHOICES = [
        'Lunes'     => 1,
        'Martes'    => 2,
        'Miércoles' => 3,
        'Jueves'    => 4,
        'Viernes'   => 5,
        'Sábado'    => 6,
        'Domingo'   => 7,
    ];

    /**
     * @var array<string,string>
     */
    private const CADENCE_CHOICES = [
        'Semanal'   => Node::CADENCE_WEEKLY,
        'Quincenal' => Node::CADENCE_BIWEEKLY,
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', null, [
                'label' => 'Nombre',
            ])
            ->add('deliveryWeekday', ChoiceType::class, [
                'label'   => 'Día de reparto',
                'choices' => self::WEEKDAY_CHOICES,
            ])
            ->add('cadence', ChoiceType::class, [
                'label'   => 'Cadencia',
                'choices' => self::CADENCE_CHOICES,
            ])
            ->add('anchorDate', DateType::class, [
                'label'    => 'Fecha ancla (sólo cadencia quincenal)',
                'widget'   => 'single_text',
                'required' => false,
                'help'     => 'Un viernes-ciclo que SÍ reparte. A partir de aquí alternan operativos vs vacíos.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Node::class,
        ]);
    }
}
