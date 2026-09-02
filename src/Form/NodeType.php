<?php

namespace App\Form;

use App\Entity\Node;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
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

    /**
     * Cuándo se monta, respecto al día de la entrega. Se ofrece como lista y no
     * como un número con signo porque "-1" no lo entiende nadie y un campo libre
     * invita a escribir "3", que sería montar las cestas tres días antes de
     * repartirlas.
     *
     * Llega hasta dos días antes: un punto grande puede empezar el jueves lo que
     * entrega el sábado. Más atrás no es montar cestas, es otra cosa.
     *
     * @var array<string,int>
     */
    private const PREP_DAY_CHOICES = [
        'El mismo día de la entrega' => 0,
        'La víspera'                 => -1,
        'Dos días antes'             => -2,
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

        // El montaje con voluntariado sólo se pregunta si el módulo está
        // encendido. Preguntarlo con el módulo apagado ofrecería configurar algo
        // que después no convoca a nadie, y quien lo marcara se quedaría
        // esperando gente que el sistema nunca pidió.
        if (!$options['with_delivery_prep']) {
            return;
        }

        $builder
            ->add('deliveryPrep', CheckboxType::class, [
                'label'    => 'Este punto monta las cestas con voluntariado',
                'required' => false,
                'help'     => 'Cada semana que abra este punto se convoca sola a la gente que hace falta para montar las cestas. Sin marcar, aquí no se convoca a nadie.',
            ])
            // Mismo criterio que anchorDate: la hora sólo hace falta con la
            // casilla marcada, y `required` en true la exigiría también a los
            // puntos que no montan con voluntariado. Quién la necesita lo decide
            // Node::validateDeliveryPrep().
            ->add('deliveryPrepTime', TimeType::class, [
                'label'    => 'Hora del montaje',
                'widget'   => 'single_text',
                'required' => false,
                'help'     => 'A qué hora se empieza a montar. El día lo pone solo el calendario de este punto.',
            ])
            ->add('deliveryPrepDayOffset', ChoiceType::class, [
                'label'   => 'Qué día se monta',
                'choices' => self::PREP_DAY_CHOICES,
            ])
            ->add('deliveryPrepSlots', IntegerType::class, [
                'label'    => 'Cuánta gente hace falta',
                'required' => false,
                'help'     => 'Vacío = sin tope: se apunta quien quiera.',
            ])
            ->add('deliveryPrepMinutes', IntegerType::class, [
                'label'    => 'Cuánto dura (minutos)',
                'required' => false,
                'help'     => 'Da la hora de fin y lo que se le computa a quien viene. Vacío = la convocatoria dice cuándo empieza y nada más.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Node::class,
            // Si se pregunta por el montaje de cestas con voluntariado. Lo
            // decide quien monta el formulario, mirando el flag del módulo: un
            // formulario no consulta permisos por su cuenta en este proyecto.
            'with_delivery_prep' => false,
        ]);

        $resolver->setAllowedTypes('with_delivery_prep', 'bool');
    }
}
