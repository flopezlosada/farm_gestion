<?php

namespace App\Form;

use App\Entity\Obligation;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Alta y edición de una {@see Obligation}.
 *
 * LAS FECHAS NO ESTÁN AQUÍ, salvo en el alta: vivir en un
 * {@see \App\Entity\ObligationTerm} es lo que hace que renovar sea añadir un
 * periodo —y que el aviso del año siguiente se reprograme solo— en lugar de
 * editar una fecha que alguien tiene que acordarse de mover. Si se pudieran
 * editar desde aquí, se editarían, y el historial dejaría de existir.
 *
 * En el ALTA sí se pide la primera fecha (`with_first_term`), y es obligatoria:
 * una obligación sin fecha no vigila nada, y "ya la pondré" es como no darla de
 * alta. Son campos no mapeados porque pertenecen a la otra entidad; el
 * controlador construye el periodo con ellos.
 */
class ObligationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Qué es',
                'help' => 'Como lo llamáis vosotras, no su nombre legal: «Convenio de la parcela de La Cerrada» se encuentra; «Addenda 2ª al convenio de cesión» no.',
            ])
            ->add('kind', ChoiceType::class, [
                'label' => 'Tipo',
                'choices' => array_flip(Obligation::KIND_LABELS),
                'help' => 'Sirve para agrupar el listado.',
            ])
            ->add('counterparty', TextType::class, [
                'label' => 'Con quién',
                'required' => false,
                'help' => 'El ayuntamiento, la aseguradora, la propiedad de la finca…',
            ])
            ->add('reference', TextType::class, [
                'label' => 'Número de póliza o expediente',
                'required' => false,
                'help' => 'El dato que siempre hay que ir a buscar al documento cuando se llama por teléfono.',
            ])
            ->add('responsible', EntityType::class, [
                'label' => 'Quién se ocupa',
                'class' => User::class,
                'required' => false,
                'placeholder' => 'Sin asignar',
                // Mismo criterio que en las áreas de voluntariado: el nombre de
                // la persona, no el username, que en las cuentas de socixs es su
                // correo y no dice quién es.
                'choice_label' => static fn (User $user): string => mb_strtolower($user->getDisplayName()),
                'help' => 'Si se pone, el aviso de que caduca le llega también a esta persona, además de a quien tenga el permiso de Vencimientos.',
            ])
            ->add('documentUrl', UrlType::class, [
                'label' => 'Dónde está el documento',
                'required' => false,
                'default_protocol' => 'https',
                'help' => 'Enlace a la carpeta del Dropbox o a la sede electrónica. No se sube el fichero: el archivo ya existe y dos copias significan que una está vieja.',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Qué hay que saber para renovarlo',
                'required' => false,
                'attr' => ['rows' => 4],
                'help' => 'Con quién se habla, qué papeles hacen falta, cuánto tarda. Es lo que le ahorra media mañana a quien lo haga el año que viene.',
            ])
        ;

        if ($options['with_first_term']) {
            $builder
                ->add('firstStartsOn', DateType::class, [
                    'label' => 'Vigente desde',
                    'widget' => 'single_text',
                    'mapped' => false,
                    'required' => false,
                    'input' => 'datetime_immutable',
                    'help' => 'Opcional: de los convenios viejos muchas veces no se sabe.',
                ])
                ->add('firstEndsOn', DateType::class, [
                    'label' => 'Caduca el',
                    'widget' => 'single_text',
                    'mapped' => false,
                    'input' => 'datetime_immutable',
                    'constraints' => [new NotNull(message: 'Sin fecha de caducidad no hay nada que vigilar.')],
                    'help' => 'La fecha que se va a vigilar. Luego, cada renovación se anota desde la ficha.',
                ])
            ;
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Obligation::class,
            'with_first_term' => false,
        ]);
        $resolver->setAllowedTypes('with_first_term', 'bool');
    }
}
