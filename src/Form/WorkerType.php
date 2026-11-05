<?php

namespace App\Form;

use App\Entity\Partner;
use App\Entity\Worker;
use App\Entity\WorkerLeaveBasket;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Form para crear/editar un {@see Worker} (ficha laboral). Datos mínimos: nombre,
 * alta, tipo de jornada, días de vacaciones al año y baja; y de contacto, todos
 * opcionales: DNI/NIE, teléfono, correo y dirección. También su cesta: si ya es
 * socia, si recibe cesta y qué pasa con ella tras la baja. El acceso de login se gestiona
 * aparte (vinculación a User), no desde aquí.
 */
class WorkerType extends AbstractType
{
    /**
     * @param AuthorizationCheckerInterface $auth Para ofrecer «Recibe cesta» sólo a quien puede dar la cesta.
     */
    public function __construct(private readonly AuthorizationCheckerInterface $auth)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre completo',
            ])
            ->add('nif', TextType::class, [
                'label' => 'DNI/NIE',
                'required' => false,
                'help' => 'Sale en el registro de jornada para identificarle ante la Inspección.',
            ])
            ->add('phone', TelType::class, [
                'label' => 'Teléfono',
                'required' => false,
            ])
            ->add('email', EmailType::class, [
                'label' => 'Correo personal',
                'required' => false,
                'help' => 'Para contactar, también tras la baja. El de su acceso para fichar va aparte.',
            ])
            ->add('address', TextType::class, [
                'label' => 'Dirección',
                'required' => false,
                'help' => 'Calle, número, código postal y localidad.',
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
        ;

        $this->addBasketFields($builder, $options['data'] ?? null);
    }

    /**
     * La cesta. Lo primero es si ya es socia: entonces su cesta es la de su ficha,
     * que se elige. Si no lo es, se marca si recibe cesta y, al guardar, se le da
     * de alta la ficha de no socia ({@see \App\Controller\WorkerController}).
     * Con esa ficha ya creada no hay nada que preguntar: la plantilla la enseña.
     * Qué pasa con la cesta tras la baja sólo sale si ya tiene ficha: sin ella no
     * hay nada que decidir.
     *
     * @param FormBuilderInterface $builder El formulario.
     * @param Worker|null          $worker  El trabajador que se da de alta o se edita.
     */
    private function addBasketFields(FormBuilderInterface $builder, ?Worker $worker): void
    {
        $partner = $worker?->getPartner();
        if ($partner === null || $partner->isMember()) {
            $builder
                ->add('alreadyMember', ChoiceType::class, [
                    'mapped' => false,
                    'expanded' => true,
                    'choices' => ['No' => false, 'Sí' => true],
                    'data' => $partner !== null,
                ])
                ->add('partner', EntityType::class, [
                    'class' => Partner::class,
                    'query_builder' => static fn (EntityRepository $r) => $r->createQueryBuilder('p')
                        ->andWhere('p.member = true')
                        ->orderBy('p.name')->addOrderBy('p.surname'),
                    'choice_label' => static fn (Partner $p): string => $p->getLegalName(),
                    'required' => false,
                    'placeholder' => '— elige su ficha —',
                ])
                // Antes que la validación (prioridad 0): fija la ficha que se valida.
                ->addEventListener(FormEvents::POST_SUBMIT, $this->resolveMemberCard(...), 10);

            // Lleva a dar de alta la ficha o la cesta, que son de Socixs.
            if ($this->auth->isGranted('ROLE_GESTION_SOCIXS_EDIT')) {
                $builder
                    ->add('givesBasket', CheckboxType::class, ['mapped' => false, 'required' => false])
                    ->add('basketStart', DateType::class, [
                        'mapped' => false,
                        'required' => false,
                        'widget' => 'single_text',
                        'input' => 'datetime_immutable',
                    ])
                    // Después de fijar la ficha: si es socia con cesta, no hace falta fecha.
                    ->addEventListener(FormEvents::POST_SUBMIT, $this->requireBasketStart(...), 5);
            }
        }

        if ($partner === null) {
            return;
        }
        $builder
            ->add('basketAfterLeave', EnumType::class, [
                'label' => 'Su cesta tras la baja',
                'class' => WorkerLeaveBasket::class,
                'choice_label' => static fn (WorkerLeaveBasket $c): string => $c->label(),
                'required' => false,
                'placeholder' => '— sin decidir —',
            ])
            ->add('basketGiftUntil', DateType::class, [
                'label' => 'Se le regala hasta',
                'widget' => 'single_text',
                'required' => false,
            ]);
    }

    /**
     * «¿Ya es socia?» manda sobre el desplegable, que el navegador envía aunque esté
     * escondido: con «No» no se enlaza ninguna ficha de socia; con «Sí» hay que
     * elegirla.
     *
     * @param FormEvent $event El envío del formulario.
     */
    private function resolveMemberCard(FormEvent $event): void
    {
        $form = $event->getForm();
        /** @var Worker $worker */
        $worker = $event->getData();

        if ($form->get('alreadyMember')->getData() !== true) {
            $worker->setPartner(null);
        } elseif ($worker->getPartner() === null) {
            $form->get('partner')->addError(new FormError('Elige su ficha de socia.'));
        }
    }

    /**
     * Con «Recibe cesta» hay que decir desde cuándo: es la fecha con la que se
     * abre «Añadir cesta». Salvo si es socia y ya tiene cesta, que no se le da otra.
     *
     * @param FormEvent $event El envío del formulario.
     */
    private function requireBasketStart(FormEvent $event): void
    {
        $form = $event->getForm();
        $partner = $event->getData()->getPartner();
        if ($form->get('givesBasket')->getData() === true
            && $form->get('basketStart')->getData() === null
            && !($partner?->hasActiveBasket() ?? false)) {
            $form->get('basketStart')->addError(new FormError('Indica desde cuándo recibe cesta.'));
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Worker::class,
        ]);
    }
}
