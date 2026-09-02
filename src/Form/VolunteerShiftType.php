<?php

namespace App\Form;

use App\Entity\VolunteerShift;
use App\Service\Volunteering\CreditedTime;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Un turno suelto: cuándo es y, si difiere del resto, cuánta gente pide y cuánto
 * computa.
 *
 * SÓLO LO QUE ES DEL MOMENTO. El título, la explicación, el sitio y el tipo de
 * trabajo son de la tarea y se editan allí: repetirlos aquí crearía dos sitios
 * donde cambiar lo mismo, y el turno acabaría contradiciendo a su tarea.
 *
 * Las plazas y los minutos van vacíos por defecto y eso significa "los de la
 * tarea" ({@see VolunteerShift::getSlots()}). Rellenarlos es para el caso
 * concreto: el reparto de Navidad, que pide el doble de gente.
 */
class VolunteerShiftType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('startsAt', DateTimeType::class, [
                'label' => 'Cuándo empieza',
                'widget' => 'single_text',
            ])
            ->add('endsAt', DateTimeType::class, [
                'label' => 'Cuándo acaba',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'Se puede dejar vacío en trabajo sin horario.',
            ])
            ->add('ownSlots', IntegerType::class, [
                'label' => 'Plazas sólo de este turno',
                'required' => false,
                'help' => 'Vacío = las de la tarea.',
            ])
            ->add('ownCreditedMinutes', NumberType::class, [
                'label' => 'Horas sólo de este turno',
                'required' => false,
                'html5' => true,
                'scale' => 2,
                'attr' => ['step' => 'any', 'min' => 0, 'max' => 24],
                'help' => 'Vacío = las de la tarea.',
            ])
        ;

        // En horas arriba, minutos abajo, igual que en la tarea: nadie piensa
        // "esto vale 90 minutos".
        $builder->get('ownCreditedMinutes')->addModelTransformer(new CallbackTransformer(
            static fn (?int $minutes): ?float => CreditedTime::hoursFromMinutes($minutes),
            static fn ($hours): ?int => CreditedTime::minutesFromHours($hours),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => VolunteerShift::class]);
    }
}
