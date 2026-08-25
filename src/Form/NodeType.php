<?php

namespace App\Form;

use App\Entity\Node;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form para crear/editar un Node (sitio físico de reparto).
 * Sub-fase 8.8a (2026-05-26).
 */
class NodeType extends AbstractType
{
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
                'choices' => array_flip(Node::WEEKDAY_NAMES),
            ])
            ->add('cadence', ChoiceType::class, [
                'label'   => 'Cadencia',
                'choices' => self::CADENCE_CHOICES,
            ])
            // `required` se queda en false a propósito: el navegador lo exigiría
            // también en los puntos semanales, donde el campo no aplica. Quién
            // la necesita y con qué forma lo decide Node::validateCadenceConsistency().
            ->add('anchorDate', DateType::class, [
                'label'    => 'Fecha ancla (obligatoria si la cadencia es quincenal)',
                'widget'   => 'single_text',
                'required' => false,
                'help'     => 'Una fecha en la que este punto SÍ reparte, en su mismo día de la semana. A partir de ahí se alternan las semanas con reparto y sin él. En puntos semanales, déjala vacía.',
            ])
            ->add('schedule', TextType::class, [
                'label'    => 'Horario público',
                'required' => false,
                'help'     => 'Se muestra tal cual en la web pública (Hazte socix), p. ej. «Miércoles de 18:00 a 20:00». Vacío = no se publica.',
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
