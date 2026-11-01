<?php

namespace App\Form;

use App\Entity\Worker;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form para crear/editar un {@see Worker} (ficha laboral). Datos mínimos: nombre,
 * alta, tipo de jornada, días de vacaciones al año y si sigue activo. El acceso de login se
 * gestiona aparte (vinculación a User), no desde aquí.
 */
class WorkerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre completo',
            ])
            ->add('hireDate', DateType::class, [
                'label' => 'Fecha de alta',
                'widget' => 'single_text',
                'help' => 'Desde cuándo trabaja, según el contrato.',
            ])
            ->add('recordingStartDate', DateType::class, [
                'label' => 'Registro en la app desde',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'Sólo si ya trabajaba cuando se empezó a fichar aquí. Los días anteriores no cuentan como «sin fichar». Vacío = desde el alta.',
            ])
            ->add('workdayType', ChoiceType::class, [
                'label' => 'Tipo de jornada',
                'choices' => array_flip(Worker::WORKDAY_TYPES),
                'placeholder' => 'Elige el tipo de jornada',
                'help' => 'El del contrato. A quien trabaja a tiempo parcial se le manda cada mes el resumen de sus horas, como manda la ley.',
            ])
            ->add('annualVacationDays', IntegerType::class, [
                'label' => 'Días de vacaciones al año',
                'attr' => ['min' => 0],
                'help' => 'En jornadas laborables (el convenio habitual son 22).',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Activo',
                'required' => false,
                'help' => 'Si se desmarca, conserva su histórico pero no puede fichar.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Worker::class,
        ]);
    }
}
