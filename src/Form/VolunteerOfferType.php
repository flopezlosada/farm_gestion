<?php

namespace App\Form;

use App\Entity\Node;
use App\Entity\Partner;
use App\Entity\VolunteerCategory;
use App\Entity\VolunteerOffer;
use App\Entity\VolunteerPlace;
use App\Service\Volunteering\CreditedTime;
use App\Service\Volunteering\TaskCoordinator;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Alta y edición de una tarea de voluntariado.
 *
 * NO PIDE FECHA NI ESTADO, y las dos ausencias son deliberadas.
 *
 * La FECHA ya no es de la tarea: aquí se dice cada cuánto se hace el trabajo
 * —qué días de la semana, en qué tramos, desde cuándo y hasta cuándo— y de esa
 * receta salen los turnos ({@see \App\Service\Volunteering\ShiftGenerator}). Es
 * lo que permite expresar "abrir el invernadero sábados y domingos por la
 * mañana" sin crear dos tareas, y "sacar al perro mañana y tarde" sin crear
 * setecientas treinta.
 *
 * El ESTADO era un `<select>` con "Publicada" al alcance del ratón, en medio de
 * un formulario de diecinueve campos: publicar es lo que dispara los avisos a la
 * asociación, y no puede ser algo que pase por descuido al guardar un cambio de
 * hora. Ahora se publica desde la ficha, con un botón que dice a cuánta gente va
 * a avisar.
 *
 * LOS TRAMOS SON DOS Y FIJOS, y no una colección dinámica. El caso real es "por
 * la mañana" o "mañana y tarde"; una colección con botones de añadir y quitar
 * exige JavaScript, y sin él el formulario se queda sin poder añadir el segundo
 * tramo — que es justo el que hace falta. Con dos pares de horas se cubre lo que
 * hay y el formulario funciona con el navegador pelado.
 *
 * Los textos de ayuda de `openToAnyone` y de `creditedMinutes` no son adorno:
 * son las dos casillas que más se van a rellenar mal si nadie explica qué
 * significan, y las dos tienen consecuencias que no se ven al guardar — una
 * decide a cuánta gente se molesta, la otra cuántas horas se le apuntan a
 * alguien.
 */
class VolunteerOfferType extends AbstractType
{
    /**
     * Hasta cuándo se puede estirar una serie: un año y pico. No es una regla
     * del dominio, es una red contra el dedazo en el año de la fecha final, que
     * pediría miles de turnos.
     */
    private const MAX_SPAN = '+13 months';

    /** Días de la semana, en ISO-8601 y empezando en lunes como el calendario. */
    private const WEEKDAYS = [
        'L' => 1, 'M' => 2, 'X' => 3, 'J' => 4, 'V' => 5, 'S' => 6, 'D' => 7,
    ];

    public function __construct(private readonly TaskCoordinator $coordinators)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Título',
                'attr' => ['placeholder' => 'p. ej. Descargar el reparto en La Cabrera'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Descripción',
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
            ->add('repeatType', ChoiceType::class, [
                'label' => 'Cada cuánto',
                'choices' => [
                    'Una sola vez' => VolunteerOffer::REPEAT_ONCE,
                    'Días fijos de la semana' => VolunteerOffer::REPEAT_WEEKLY,
                    'Una vez al mes' => VolunteerOffer::REPEAT_MONTHLY,
                    'Los días que haya reparto' => VolunteerOffer::REPEAT_DELIVERY,
                ],
                'help' => 'Con «los días que haya reparto» las fechas salen del calendario del punto de recogida: ya vienen sin las semanas que no reparte y con los traslados aplicados.',
            ])
            ->add('repeatWeekdays', ChoiceType::class, [
                'label' => 'Qué días',
                'choices' => self::WEEKDAYS,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help' => 'Varios a la vez: abrir el invernadero es sábados y domingos, y es una sola tarea.',
            ])
            ->add('repeatEvery', IntegerType::class, [
                'label' => 'Cada cuántas semanas',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 8],
                'help' => 'Vacío o 1 = todas las semanas. 2 = una de cada dos.',
            ])
            ->add('repeatFrom', DateType::class, [
                'label' => 'Desde el',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'En una tarea de una sola vez, es el día en que se hace.',
            ])
            ->add('repeatUntil', DateType::class, [
                'label' => 'Hasta el',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'Los turnos se van abriendo por meses, no todos de golpe: se puede poner una fecha lejana sin miedo.',
            ])
            // Los cuatro campos de hora NO están mapeados: se componen en
            // `repeatTimes` al enviar y se descomponen al cargar. Ver los
            // listeners de abajo.
            ->add('firstStart', TimeType::class, [
                'label' => 'De',
                'widget' => 'single_text',
                'mapped' => false,
                'required' => false,
                'input' => 'string',
            ])
            ->add('firstEnd', TimeType::class, [
                'label' => 'a',
                'widget' => 'single_text',
                'mapped' => false,
                'required' => false,
                'input' => 'string',
                'help' => 'Se puede dejar vacío en trabajo sin horario ("antes del día 20").',
            ])
            ->add('secondStart', TimeType::class, [
                'label' => 'Y también de',
                'widget' => 'single_text',
                'mapped' => false,
                'required' => false,
                'input' => 'string',
            ])
            ->add('secondEnd', TimeType::class, [
                'label' => 'a',
                'widget' => 'single_text',
                'mapped' => false,
                'required' => false,
                'input' => 'string',
                'help' => 'Para el trabajo que se hace dos veces al día, como sacar al perro.',
            ])
            ->add('place', EntityType::class, [
                'label' => 'Sitio',
                'class' => VolunteerPlace::class,
                'query_builder' => static fn ($repository) => $repository
                    ->createQueryBuilder('p')
                    ->where('p.active = true')
                    ->orderBy('p.name', 'ASC'),
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— Sin concretar —',
            ])
            ->add('placeNote', TextType::class, [
                'label' => 'Precisión',
                'required' => false,
                'help' => 'Sólo si hace falta afinar: "parcela de arriba", "por la puerta de atrás".',
            ])
            ->add('node', EntityType::class, [
                'label' => 'Punto de recogida',
                'class' => Node::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— No es en un punto de recogida —',
                'help' => 'Si la tarea ocurre en un punto de recogida, ponlo: a quien recoge ahí le sale la primera, porque ya va a estar allí ese día.',
            ])
            // DESPUÉS de `place` y `node` a propósito. El mapeo llama a los
            // setters en el orden en que se declaran los campos, y
            // `setRemote(true)` limpia sitio y nodo: declarado antes, el
            // `setNode()` posterior los volvía a poner y se podía guardar una
            // tarea "se hace desde casa" CON punto de recogida, justo lo que el
            // docblock de setRemote() dice evitar. El orden visual lo manda la
            // plantilla, no este builder.
            ->add('remote', CheckboxType::class, [
                'label' => 'Se hace desde casa',
                'required' => false,
                'help' => 'Si lo marcas, se ignoran el sitio y el punto de recogida.',
            ])
            ->add('slots', IntegerType::class, [
                'label' => 'Plazas por turno',
                'required' => false,
                'help' => 'Vacío = sin tope ("cuanta más gente venga, mejor"). Un turno suelto puede pedir otra cantidad.',
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
        ;

        // Horas arriba, minutos abajo. Un transformer y no dos campos ni un
        // `mapped => false` con copia a mano: así la entidad no se entera de en
        // qué unidad se escribió y no hay un segundo sitio donde se pueda
        // olvidar la conversión.
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
        $builder->addEventListener(FormEvents::PRE_SET_DATA, $this->fillTimeSlots(...));
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->collectTimeSlots(...));
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->validateSchedule(...));
    }

    /**
     * Añade el campo de coordinación, y sólo si hace falta preguntarlo.
     *
     * TRES CASOS, y en dos de ellos el campo no aparece:
     *
     *  - Tarea nueva: todavía no tiene áreas, así que no hay candidatas que
     *    ofrecer. Se resuelve al guardar ({@see TaskCoordinator}), y si queda
     *    ambigua se elige entrando a editarla.
     *  - Un área con una sola persona coordinándola —el caso de hoy en las
     *    cuatro—: no hay nada que decidir, y preguntarlo es pedir un dato que
     *    el sistema ya sabe.
     *  - Varias: ahí sí, y el desplegable ofrece ESAS y no los 246 socixs.
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
     * Rellena los cuatro campos de hora a partir de los tramos guardados, al
     * abrir el formulario de una tarea que ya existe.
     *
     * @param FormEvent $event el evento con la tarea
     */
    private function fillTimeSlots(FormEvent $event): void
    {
        $offer = $event->getData();
        if (!$offer instanceof VolunteerOffer) {
            return;
        }

        $times = $offer->getRepeatTimes();
        $form = $event->getForm();

        $fields = [['firstStart', 'firstEnd'], ['secondStart', 'secondEnd']];

        foreach ($fields as $index => [$startField, $endField]) {
            $slot = $times[$index] ?? null;

            // Mismo motivo que en {@see self::collectTimeSlots()}: los campos
            // pueden no existir si el horario lo manda otro, y `$form->get()`
            // de un campo ausente lanza.
            if (null === $slot || !$form->has($startField)) {
                continue;
            }

            $form->get($startField)->setData($this->withSeconds($slot[0] ?? null));

            if ($form->has($endField)) {
                $form->get($endField)->setData($this->withSeconds($slot[1] ?? null));
            }
        }
    }

    /**
     * Compone los tramos horarios de los cuatro campos y los guarda en la tarea.
     *
     * Un tramo sin hora de inicio no existe: sin ella no hay momento al que
     * apuntarse, y guardar "hasta las 12:00" sin principio dejaría un turno a
     * medianoche que nadie pidió.
     *
     * @param FormEvent $event el envío del formulario
     */
    private function collectTimeSlots(FormEvent $event): void
    {
        $offer = $event->getData();
        $form = $event->getForm();

        if (!$offer instanceof VolunteerOffer) {
            return;
        }

        // SI LOS CAMPOS NO ESTÁN, NO SE TOCA NADA. Hacen falta las dos guardas y
        // no son estética:
        //
        //  - `$form->get()` de un campo ausente LANZA, así que un formulario que
        //    esconda las horas —porque el horario lo manda otro, como el punto
        //    de recogida en el montaje de cestas— reventaría al guardar.
        //  - Y saltar el bucle escribiendo `[]` sería peor que reventar: una
        //    lista vacía BORRA los tramos guardados, y una tarea sin tramos se
        //    queda sin turnos. Ausencia de campos significa "esto no se edita
        //    aquí", no "esto se queda vacío".
        if (!$form->has('firstStart')) {
            return;
        }

        $slots = [];
        foreach ([['firstStart', 'firstEnd'], ['secondStart', 'secondEnd']] as [$startField, $endField]) {
            if (!$form->has($startField)) {
                continue;
            }

            $start = $this->asHourMinute($form->get($startField)->getData());
            if (null === $start) {
                continue;
            }

            $end = $form->has($endField) ? $this->asHourMinute($form->get($endField)->getData()) : null;
            $slots[] = [$start, $end];
        }

        $offer->setRepeatTimes($slots);
    }

    /**
     * Comprueba que la receta de repetición tiene sentido, ya con el formulario
     * enviado.
     *
     * Va aquí y no en el controller para que `isValid()` diga la verdad: si la
     * comprobación viviera después, habría que deshacer una tarea ya creada, o
     * peor, dejarla creada sin turnos y sin que nadie lo dijera.
     *
     * @param FormEvent $event el envío del formulario
     */
    private function validateSchedule(FormEvent $event): void
    {
        $offer = $event->getData();
        $form = $event->getForm();

        if (!$offer instanceof VolunteerOffer) {
            return;
        }

        $from = $offer->getRepeatFrom();
        $until = $offer->getRepeatUntil();
        $type = $offer->getRepeatType();

        if (null === $from) {
            $this->error($form, 'repeatFrom', 'Dime desde cuándo se hace.');

            return;
        }

        if ([] === $offer->getRepeatTimes()) {
            $this->error($form, 'firstStart', 'Pon al menos la hora a la que empieza.');
        }

        if (VolunteerOffer::REPEAT_WEEKLY === $type && [] === $offer->getRepeatWeekdays()) {
            $this->error($form, 'repeatWeekdays', 'Marca al menos un día de la semana.');
        }

        if (VolunteerOffer::REPEAT_DELIVERY === $type && null === $offer->getNode()) {
            $this->error(
                $form,
                'repeatType',
                'Para que las fechas salgan del reparto, la tarea tiene que ocurrir en un punto de recogida.'
            );
        }

        // Una tarea de una sola vez no necesita fecha final: es su propio día.
        if (VolunteerOffer::REPEAT_ONCE === $type) {
            return;
        }

        if (null === $until) {
            $this->error($form, 'repeatUntil', 'Si se repite, dime hasta cuándo.');

            return;
        }

        if ($until < $from) {
            $this->error($form, 'repeatUntil', 'La fecha final es anterior al principio.');

            return;
        }

        $ceiling = \DateTimeImmutable::createFromInterface($from)->modify(self::MAX_SPAN);
        if ($until > $ceiling) {
            $this->error($form, 'repeatUntil', sprintf(
                'Como mucho hasta el %s. Si de verdad va más allá, amplíala cuando llegue.',
                $ceiling->format('d/m/Y')
            ));
        }
    }

    /**
     * Cuelga un error del campo si existe, y del formulario si no.
     *
     * El `coordinator` y los tramos aparecen condicionalmente, y un
     * `$form->get()` de un campo que no está lanza: un error de validación no
     * puede convertirse en un 500.
     *
     * @param FormInterface $form    el formulario
     * @param string        $field   el campo al que colgarlo
     * @param string        $message el mensaje
     */
    private function error(FormInterface $form, string $field, string $message): void
    {
        $target = $form->has($field) ? $form->get($field) : $form;
        $target->addError(new FormError($message));
    }

    /**
     * "HH:MM" a partir de lo que devuelve un TimeType con `input: string`
     * ("HH:MM:SS"), o null si no hay hora.
     *
     * @param mixed $value lo que trae el campo
     *
     * @return string|null la hora en "HH:MM", o null
     */
    private function asHourMinute(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }

        $value = trim((string) $value);
        if ('' === $value) {
            return null;
        }

        return substr($value, 0, 5);
    }

    /**
     * "HH:MM" a "HH:MM:00", que es lo que espera un TimeType con
     * `input: string`.
     *
     * @param string|null $value la hora guardada
     *
     * @return string|null la hora con segundos, o null
     */
    private function withSeconds(?string $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : substr($value.':00', 0, 8);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VolunteerOffer::class,
        ]);
    }
}
