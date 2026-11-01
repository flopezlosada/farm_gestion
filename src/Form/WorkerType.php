<?php

namespace App\Form;

use App\Entity\Partner;
use App\Entity\Worker;
use App\Entity\WorkerLeaveBasket;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form para crear/editar un {@see Worker} (ficha laboral). Datos mínimos: nombre,
 * alta, tipo de jornada, días de vacaciones al año y baja. También su ficha de socix,
 * si recibe cesta, y qué pasa con ella tras la baja. El acceso de login se gestiona
 * aparte (vinculación a User), no desde aquí.
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
            ->add('leaveDate', DateType::class, [
                'label' => 'Fecha de baja',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'Último día que trabaja. Desde el día siguiente conserva su histórico pero no puede fichar. Vacío = sigue trabajando.',
            ])
            ->add('partner', EntityType::class, [
                'label' => 'Su ficha de socix',
                'class' => Partner::class,
                'query_builder' => static fn (EntityRepository $r) => $r->createQueryBuilder('p')->orderBy('p.name')->addOrderBy('p.surname'),
                'choice_label' => static fn (Partner $p): string => $p->getLegalName(),
                'required' => false,
                'placeholder' => '— no recibe cesta —',
                'help' => 'Si recibe cesta. Mientras trabaja no se le cobra.',
            ])
            ->add('basketAfterLeave', EnumType::class, [
                'label' => 'Su cesta tras la baja',
                'class' => WorkerLeaveBasket::class,
                'choice_label' => static fn (WorkerLeaveBasket $c): string => $c->label(),
                'required' => false,
                'placeholder' => '— sin decidir —',
                'help' => 'Mientras no se decida, su cuota sale como pendiente y no se cobra.',
            ])
            ->add('basketGiftUntil', DateType::class, [
                'label' => 'Se le regala hasta',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'Último día del regalo. Vacío = sin fin.',
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
