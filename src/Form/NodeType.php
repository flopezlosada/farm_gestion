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

    /**
     * Semanas elegibles en un punto mensual. Sin "4ª" a propósito: sólo
     * coincide con "la última" en los meses de 4 semanas, y lo que
     * administración quiere decir siempre es la última.
     *
     * @var array<string,int>
     */
    private const MONTHLY_WEEK_CHOICES = [
        '1ª semana del mes'     => 1,
        '2ª semana del mes'     => 2,
        '3ª semana del mes'     => 3,
        'Última semana del mes' => Node::MONTHLY_WEEK_LAST,
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
                'choices' => array_flip(Node::CADENCE_LABELS),
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
            // Mismo criterio que anchorDate: quién la necesita lo decide
            // Node::validateCadenceConsistency(), no el navegador.
            ->add('monthlyWeek', ChoiceType::class, [
                'label'       => 'Semana del mes (obligatoria si la cadencia es mensual)',
                'choices'     => self::MONTHLY_WEEK_CHOICES,
                'placeholder' => 'No aplica',
                'required'    => false,
                'help'        => 'La semana en que abre el punto, contada sobre su día de reparto: «2ª semana» es el 2º miércoles del mes si reparte en miércoles. «Última» sigue al último del mes, tenga 4 o 5. En puntos semanales o quincenales, déjala vacía.',
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
