<?php

namespace App\Form;

use App\Entity\Absence;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
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
 * vacaciones, con la opción `only_vacation`; entonces tampoco lleva la casilla
 * del complemento de la baja médica.
 */
class AbsenceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$options['only_vacation']) {
            $builder->add('type', ChoiceType::class, [
                'label' => 'Tipo',
                'choices' => array_flip(Absence::TYPE_LABELS),
            ]);
            // Sólo cuenta en una baja médica: la plantilla lo enseña sólo con ese tipo.
            $builder->add('topsUpToFullPay', CheckboxType::class, [
                'label' => 'La asociación complementa hasta el 100 % del sueldo',
                'required' => false,
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
                // Una baja médica empieza sin fecha de alta: se deja vacío y se
                // cierra al llegar el parte de alta.
                'required' => false,
                'help' => 'Último día de la ausencia (incluido). En una baja médica sin fecha de alta, déjalo vacío.',
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
