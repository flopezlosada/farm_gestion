<?php

namespace App\Form;

use App\Entity\Absence;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form para crear/solicitar una {@see Absence} (vacaciones, baja, permiso). Lo
 * usan el supervisor (que la registra ya aprobada) y el trabajador (que la
 * solicita). El estado y quién aprueba NO se editan aquí: los fija el controller
 * según quién actúa.
 *
 * Cuando lo usa el trabajador, se puede ocultar el selector de tipo dejando solo
 * vacaciones, con la opción `only_vacation`.
 */
class AbsenceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$options['only_vacation']) {
            $builder->add('type', ChoiceType::class, [
                'label' => 'Tipo',
                'choices' => [
                    'Vacaciones' => Absence::TYPE_VACATION,
                    'Baja médica' => Absence::TYPE_SICK_LEAVE,
                    'Permiso' => Absence::TYPE_PERMIT,
                    'Otra ausencia' => Absence::TYPE_OTHER,
                ],
            ]);
        }

        $builder
            ->add('startDate', DateType::class, [
                'label' => 'Desde',
                'widget' => 'single_text',
            ])
            ->add('endDate', DateType::class, [
                'label' => 'Hasta',
                'widget' => 'single_text',
                'help' => 'Último día de la ausencia (incluido).',
            ])
            ->add('note', TextareaType::class, [
                'label' => 'Comentario',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Absence::class,
            'only_vacation' => false,
        ]);

        $resolver->setAllowedTypes('only_vacation', 'bool');
    }
}
