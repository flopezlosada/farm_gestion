<?php

namespace App\Form;

use App\Entity\Node;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerOffer;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Alta y edición de una tarea de voluntariado.
 *
 * Los textos de ayuda de `openToAnyone` y de `creditedMinutes` no son adorno:
 * son las dos casillas que más se van a rellenar mal si nadie explica qué
 * significan, y las dos tienen consecuencias que no se ven al guardar — una
 * decide a cuánta gente se molesta, la otra cuántas horas se le apuntan a
 * alguien.
 */
class VolunteerOfferType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Qué hay que hacer',
                'attr' => ['placeholder' => 'p. ej. Descargar el reparto en La Cabrera'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Explicación',
                'required' => false,
                'help' => 'Con detalle suficiente para que alguien que no ha estado nunca sepa si puede con ello y qué tiene que llevar.',
                'attr' => ['rows' => 4],
            ])
            ->add('categories', EntityType::class, [
                'label' => 'Tipo de trabajo',
                'class' => VolunteerCategory::class,
                'query_builder' => static fn ($repository) => $repository
                    ->createQueryBuilder('c')
                    ->where('c.active = true')
                    ->orderBy('c.name', 'ASC'),
                'choice_label' => 'name',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help' => 'Se avisará primero a quien haya marcado alguno de estos tipos en su ficha.',
            ])
            ->add('startsAt', DateTimeType::class, [
                'label' => 'Cuándo empieza',
                'widget' => 'single_text',
            ])
            ->add('endsAt', DateTimeType::class, [
                'label' => 'Cuándo acaba',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'Se puede dejar vacío en tareas sin horario (p. ej. "antes del día 20").',
            ])
            ->add('remote', CheckboxType::class, [
                'label' => 'Se hace desde casa',
                'required' => false,
                'help' => 'Si lo marcas, se ignoran el lugar y el punto de recogida.',
            ])
            ->add('node', EntityType::class, [
                'label' => 'Punto de recogida',
                'class' => Node::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— No es en un punto de recogida —',
                'help' => 'Si la tarea ocurre en un punto de recogida, ponlo: a quien recoge ahí le sale la primera, porque ya va a estar allí ese día.',
            ])
            ->add('place', TextType::class, [
                'label' => 'Lugar',
                'required' => false,
                'help' => 'Sólo si no es un punto de recogida: "la nave", "parcela de arriba"…',
            ])
            ->add('slots', IntegerType::class, [
                'label' => 'Cuánta gente hace falta',
                'required' => false,
                'help' => 'Vacío = sin tope ("cuanta más gente venga, mejor").',
            ])
            ->add('companionsAllowed', CheckboxType::class, [
                'label' => 'Se puede venir acompañadx',
                'required' => false,
            ])
            ->add('creditedMinutes', IntegerType::class, [
                'label' => 'Minutos que computa',
                'required' => false,
                'help' => 'Lo que la asociación decide que vale este trabajo, que no tiene por qué ser lo que dura. 30 = media hora.',
            ])
            ->add('openToAnyone', CheckboxType::class, [
                'label' => 'Esto lo puede hacer cualquiera',
                'required' => false,
                'help' => 'Márcalo sólo si no hace falta saber nada previo ni tener fuerza: recoger cestas, sí; desbrozar, no. Marcado, si sigue faltando gente el aviso se amplía a socixs que no han dicho de qué quieren que se les avise.',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Estado',
                'choices' => [
                    'Borrador (no se ve ni avisa)' => VolunteerOffer::STATUS_DRAFT,
                    'Publicada' => VolunteerOffer::STATUS_PUBLISHED,
                    'Anulada' => VolunteerOffer::STATUS_CANCELLED,
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VolunteerOffer::class,
        ]);
    }
}
