<?php

namespace App\Form;

use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerOffer;
use App\Service\Volunteering\CreditedTime;
use App\Service\Volunteering\OfferRepeatDates;
use App\Service\Volunteering\TaskCoordinator;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
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
    public function __construct(private readonly TaskCoordinator $coordinators)
    {
    }

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
                'label' => 'Área',
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
            // En HORAS aunque la entidad guarde minutos. Nadie piensa "esto vale
            // 240 minutos": piensa "cuatro horas". El transformer de abajo hace
            // la traducción; ver CreditedTime para por qué se guardan minutos.
            ->add('creditedMinutes', NumberType::class, [
                'label' => 'Horas que computa',
                'required' => false,
                'html5' => true,
                'scale' => 2,
                // step libre: por dentro son minutos, así que 4,2 h son 252 y no
                // hay motivo para que el navegador lo rechace.
                'attr' => ['step' => 'any', 'min' => 0, 'max' => 24],
                'help' => 'Lo que la asociación decide que vale este trabajo, que no tiene por qué ser lo que dura. Se puede poner media hora (0,5) o un cuarto (0,25).',
            ])
            // Gente que viene sin ser socix y sin acompañar a nadie. Va en la
            // tarea y no en una inscripción porque no tiene de quién colgar:
            // `companions` cuelga siempre de un socix.
            ->add('guests', IntegerType::class, [
                'label' => 'Gente de fuera',
                'required' => false,
                'attr' => ['min' => 0, 'max' => 99],
                'help' => 'Personas que vienen a echar una mano sin ser socixs y sin acompañar a nadie. Ocupan plaza —si vienen tres, la tarea ya sólo pide el resto— pero no se les computan horas.',
            ])
            ->add('guestsNote', TextType::class, [
                'label' => 'Quiénes son',
                'required' => false,
                'help' => 'Para acordarse dentro de tres meses: «3 estudiantes del IES», «la gente de la cooperativa».',
            ])
            ->add('openToAnyone', CheckboxType::class, [
                'label' => 'Esto lo puede hacer cualquiera',
                'required' => false,
                'help' => 'Márcalo sólo si no hace falta saber nada previo ni tener fuerza: recoger cestas, sí; desbrozar, no. Marcado, si sigue faltando gente el aviso se amplía a socixs que no han dicho de qué quieren que se les avise.',
            ])
            ->add('featured', CheckboxType::class, [
                'label' => 'Destacar en el panel de socixs',
                'required' => false,
                'help' => 'Sube esta tarea a lo alto del panel de cada socix, por delante del orden normal. Es para la semana en la que una cosa importa más que las demás: si se destaca todo, no destaca nada.',
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

        // Horas arriba, minutos abajo. Un transformer y no dos campos ni un
        // `mapped => false` con copia a mano: así la entidad no se entera de en
        // qué unidad se escribió y no hay un segundo sitio donde se pueda
        // olvidar la conversión. En buildForm y no en addRepeatFields, que sólo
        // corre en el alta: ahí quedaba la edición pidiendo minutos.
        $builder->get('creditedMinutes')->addModelTransformer(new CallbackTransformer(
            static fn (?int $minutes): ?float => CreditedTime::hoursFromMinutes($minutes),
            static fn ($hours): ?int => CreditedTime::minutesFromHours($hours),
        ));

        // El campo de coordinación sólo existe cuando hay algo que elegir, y
        // sólo ofrece a quien de verdad coordina el área de la tarea. Antes
        // ofrecía los 246 socixs, que es pedir que se elija a dedo justo lo que
        // el sistema ya sabe.
        //
        // En PRE_SET_DATA y no en el builder porque las áreas de la tarea no se
        // conocen hasta que hay datos: al construir el formulario no hay tarea
        // todavía.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, $this->addCoordinatorChoice(...));

        if ($options['with_repeat']) {
            $this->addRepeatFields($builder);
        }
    }

    /**
     * Añade el campo de coordinación, y sólo si hace falta preguntarlo.
     *
     * TRES CASOS, y en dos de ellos el campo no aparece:
     *
     *  - Tarea nueva: todavía no tiene áreas, así que no hay candidatas que
     *    ofrecer. Se resuelve al guardar ({@see \App\Service\Volunteering\TaskCoordinator}),
     *    y si queda ambigua se elige entrando a editarla.
     *  - Un área con una sola persona coordinándola —el caso de hoy en las
     *    cuatro—: no hay nada que decidir, y preguntarlo es pedir un dato que
     *    el sistema ya sabe.
     *  - Varias: ahí sí, y el desplegable ofrece ESAS y no los 246 socixs.
     *
     * Y se pregunta AQUÍ y no al cerrar la tarea: es una propiedad del trabajo,
     * como el sitio o la hora. Preguntarlo después —mientras se pasa lista— era
     * pedir una decisión de configuración en medio de otra faena, y como no se
     * preguntaba en ningún sitio obligatorio, lo normal era que no constara
     * nadie.
     *
     * @param FormEvent $event el evento con la tarea que se va a editar
     */
    private function addCoordinatorChoice(FormEvent $event): void
    {
        $offer = $event->getData();

        if (!$offer instanceof VolunteerOffer) {
            return;
        }

        if (!$this->coordinators->needsChoosing($offer)) {
            return;
        }

        // Las mismas candidatas que considera el servicio para asignar solo.
        // Compartido a propósito: si el desplegable ofreciera un juego de gente
        // distinto, nadie lo notaría hasta ver una tarea atribuida a quien no la
        // llevó.
        $candidates = $this->coordinators->candidatesFor($offer);

        $event->getForm()->add('coordinator', EntityType::class, [
            'label' => 'Quién la coordina',
            'class' => Partner::class,
            'choices' => array_values($candidates),
            'choice_label' => static fn (Partner $p): string => trim($p->getName().' '.$p->getSurname()),
            'required' => false,
            'placeholder' => '— Sin decidir —',
            'help' => 'Esta área la coordinan varias personas: di cuál lleva esta tarea. Se guarda EN la tarea a propósito — si mañana cambia quien coordina el área, las de antes tienen que seguir diciendo quién las llevó.',
        ]);
    }

    /**
     * Cada cuánto se repite la tarea, al darla de alta.
     *
     * SIN MAPEAR Y SÓLO EN EL ALTA. No son datos de la tarea: se leen una vez
     * para crear las copias y no se guardan en ninguna parte. Si cada copia
     * llevara escrita la receta de la serie, anular una por un festivo dejaría a
     * las demás afirmando algo que ya no es cierto. Y en la edición no pintan
     * nada: repetir una tarea que ya existe se hace desde su ficha, donde además
     * se ve lo que ya tiene.
     *
     * La cadencia del calendario de reparto se ofrece siempre, aunque sólo valga
     * con punto de recogida: el nodo se elige en ESTE mismo formulario, así que
     * al construirlo todavía no se sabe. La incoherencia se caza al enviar
     * ({@see self::validateRepeat()}), que es cuando ya hay algo que mirar.
     *
     * @param FormBuilderInterface $builder el constructor del formulario
     */
    private function addRepeatFields(FormBuilderInterface $builder): void
    {
        $builder
            ->add('repeatCadence', ChoiceType::class, [
                'label' => 'Se repite',
                'mapped' => false,
                'required' => false,
                'placeholder' => 'No se repite',
                'choices' => [
                    'Los días que haya reparto' => OfferRepeatDates::CADENCE_DELIVERY,
                    'Cada semana' => OfferRepeatDates::CADENCE_WEEKLY,
                    'Cada dos semanas' => OfferRepeatDates::CADENCE_BIWEEKLY,
                    'Una vez al mes' => OfferRepeatDates::CADENCE_MONTHLY,
                ],
                'help' => 'Con «los días que haya reparto» las fechas salen del calendario del punto de recogida: ya vienen sin las semanas que no reparte y con los traslados aplicados.',
            ])
            ->add('repeatUntil', DateType::class, [
                'label' => 'Hasta el',
                'mapped' => false,
                'required' => false,
                'widget' => 'single_text',
                'help' => 'Las copias se crean en borrador y sueltas: anular una no toca a las demás.',
            ])
            ->addEventListener(FormEvents::POST_SUBMIT, $this->validateRepeat(...))
        ;
    }

    /**
     * Comprueba que lo de repetir tiene sentido, ya con el formulario enviado.
     *
     * Va aquí y no en el controller para que `isValid()` diga la verdad: si la
     * comprobación viviera después, habría que deshacer una tarea ya creada, o
     * peor, dejarla creada sin sus copias y sin que nadie lo dijera.
     *
     * @param FormEvent $event el envío del formulario
     */
    private function validateRepeat(FormEvent $event): void
    {
        $form = $event->getForm();
        $cadence = $form->get('repeatCadence')->getData();

        if (null === $cadence) {
            return;
        }

        if (null === $form->get('repeatUntil')->getData()) {
            $form->get('repeatUntil')->addError(new FormError('Si la tarea se repite, dime hasta cuándo.'));
        }

        if (OfferRepeatDates::CADENCE_DELIVERY === $cadence && null === $form->get('node')->getData()) {
            $form->get('repeatCadence')->addError(new FormError(
                'Para repetirla los días de reparto, la tarea tiene que ocurrir en un punto de recogida.'
            ));
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VolunteerOffer::class,
            // Los campos de repetición sólo en el alta: ver addRepeatFields().
            'with_repeat' => false,
        ]);

        $resolver->setAllowedTypes('with_repeat', 'bool');
    }
}
