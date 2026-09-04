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
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
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
 * LAS FRANJAS HORARIAS SON UNA COLECCIÓN, tantas como haga falta: sacar al
 * perro es mañana y tarde, y una cosecha grande puede ser tres. Se añaden y
 * quitan con un botón (csa-collection); sin JavaScript se ven las guardadas y
 * una vacía, que cubre el caso de una sola franja. Y puede no haber ninguna:
 * hay trabajo sin horario ("antes del día 20"), y entonces el turno es de todo
 * el día y las pantallas lo enseñan sin hora.
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

    /**
     * Qué semanas toca, en la repetición por días fijos. Era un número —«cada
     * cuántas semanas», de 1 a 8— y no lo entendía nadie: un 2 no dice
     * «quincenal» a quien lo lee. Cuatro opciones con nombre cubren lo que se
     * hace en la asociación; más allá no es una tarea que se repite, es otra
     * cosa.
     *
     * @var array<string,int>
     */
    private const EVERY_CHOICES = [
        'Todas las semanas' => 1,
        'Una de cada dos (quincenal)' => 2,
        'Una de cada tres' => 3,
        'Una de cada cuatro' => 4,
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
            ->add('repeatEvery', ChoiceType::class, [
                'label' => 'Qué semanas',
                'choices' => self::EVERY_CHOICES,
                'help' => 'Se cuenta desde la semana del «Desde el»: quincenal es esa semana sí, la siguiente no, y así.',
            ])
            // Obligatorio también para el navegador: el servidor ya lo exigía
            // (validateSchedule), pero sin el `required` la pantalla no lo
            // decía hasta después de enviar.
            ->add('repeatFrom', DateType::class, [
                'label' => 'Desde el',
                'widget' => 'single_text',
                'help' => 'En una tarea de una sola vez, es el día en que se hace.',
            ])
            // NO MAPEADA: en la tarea, «sin fin» es `repeatUntil` a null. Es una
            // casilla y no un «hasta» vacío porque un campo vacío puede ser un
            // olvido y una casilla marcada no: el generador abre turnos para
            // siempre, y eso no puede pasar por descuido. Se rellena al cargar
            // (fillOpenEnded) y se aplica al enviar (applyOpenEnded).
            ->add('openEnded', CheckboxType::class, [
                'label' => 'No tiene fin definido, por ahora',
                'mapped' => false,
                'required' => false,
                'help' => 'Se siguen abriendo turnos, unos meses por delante, hasta que alguien la pare o la anule.',
            ])
            ->add('repeatUntil', DateType::class, [
                'label' => 'Hasta el',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'Los turnos se van abriendo por meses, no todos de golpe: se puede poner una fecha lejana sin miedo.',
            ])
            // Las franjas del día. Cada entrada es un par [inicio, fin] en la
            // tarea, que es lo que lee el generador; la traducción a dos campos
            // de hora la hace VolunteerTimeSlotType. Sin ninguna, turno de todo
            // el día.
            ->add('repeatTimes', CollectionType::class, [
                'label' => false,
                'entry_type' => VolunteerTimeSlotType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                // Sin esto los errores de las franjas suben al formulario raíz,
                // que la plantilla no pinta: una colección es un campo compuesto
                // y Symfony los hace subir por defecto. Se quedan aquí, donde
                // la plantilla los enseña bajo las filas.
                'error_bubbling' => false,
                // Una franja sin ninguna hora se descarta al guardar, no se
                // guarda como turno vacío. Con fin y sin inicio se conserva,
                // para poder decir que está a medias (validateTimeSlots).
                'delete_empty' => static fn (?array $slot): bool => null === $slot,
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
            ->add('routine', CheckboxType::class, [
                'label' => 'Es una tarea de rutina',
                'required' => false,
                'help' => 'Una plaza, poco rato, todos los días: sacar al perro. En el calendario sus plazas libres se ven en gris, sin el aviso de «faltan», para que el aviso siga significando algo.',
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
        $builder->addEventListener(FormEvents::PRE_SET_DATA, $this->dropTimeSlotsIfTheNodeRules(...));
        // POST_SET_DATA y no PRE, y no es indiferente: entre los dos eventos
        // Symfony reparte los datos a los campos y a los NO MAPEADOS les pone su
        // valor por defecto (DataMapper::mapDataToForms), pisando lo que se les
        // hubiera puesto en PRE. Pasó con la casilla de «sin fin»: en PRE salía
        // siempre sin marcar.
        $builder->addEventListener(FormEvents::POST_SET_DATA, $this->seedOneSlot(...));
        $builder->addEventListener(FormEvents::POST_SET_DATA, $this->fillOpenEnded(...));
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->applyOpenEnded(...));
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->validateTimeSlots(...));
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->normalizeCadence(...));
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
     * Quita las franjas horarias cuando el horario lo manda el punto de
     * recogida, o sea en la convocatoria de montaje de las cestas.
     *
     * Ahí la hora la gobierna el punto y el sincronizador la reescribe en cada
     * pasada ({@see \App\Service\Volunteering\DeliveryPrepOffers}), así que
     * dejarlos editables sería peor que no tenerlos: quien cambiara la hora aquí
     * creería que la ha cambiado, y la vería volver sin explicación.
     *
     * SE QUITAN, no se deshabilitan. Un campo gris invita a preguntarse por qué,
     * y la plantilla puede decirlo en una línea con enlace a editar el punto;
     * además un campo deshabilitado tampoco viaja en el envío, así que habría que
     * blindar los listeners igual.
     *
     * Mover el turno de UNA semana concreta sigue siendo posible: eso se hace en
     * el turno, y el generador respeta los que ha tocado una persona.
     *
     * @param FormEvent $event el evento con la tarea
     */
    private function dropTimeSlotsIfTheNodeRules(FormEvent $event): void
    {
        $offer = $event->getData();
        if (!$offer instanceof VolunteerOffer || !$offer->isDeliveryPrep()) {
            return;
        }

        $event->getForm()->remove('repeatTimes');
    }

    /**
     * Deja una franja vacía a la vista en una tarea que no tiene ninguna, para
     * que quien da de alta vea dónde va la hora sin tener que pulsar «añadir»
     * —y para que sin JavaScript, donde el botón no funciona, haya al menos una.
     *
     * En POST_SET_DATA por lo mismo que los demás rellenos: antes, el reparto
     * de datos la pisaría.
     *
     * @param FormEvent $event el evento con la tarea
     */
    private function seedOneSlot(FormEvent $event): void
    {
        $form = $event->getForm();
        if ($form->has('repeatTimes') && [] === $form->get('repeatTimes')->getData()) {
            $form->get('repeatTimes')->setData([[null, null]]);
        }
    }

    /**
     * Marca «sin fin» al abrir una tarea que se repite y no tiene fecha final.
     *
     * @param FormEvent $event el evento con la tarea
     */
    private function fillOpenEnded(FormEvent $event): void
    {
        $offer = $event->getData();
        if ($offer instanceof VolunteerOffer && $offer->isRepeating() && null === $offer->getRepeatUntil()) {
            $event->getForm()->get('openEnded')->setData(true);
        }
    }

    /**
     * Con «sin fin» marcado, la fecha final se descarta aunque viniera puesta.
     *
     * Puede venir: el campo se esconde en pantalla al marcar la casilla, pero
     * un campo escondido viaja igual, y sin JavaScript se ven los dos. Manda
     * la casilla porque es el gesto explícito.
     *
     * @param FormEvent $event el envío del formulario
     */
    private function applyOpenEnded(FormEvent $event): void
    {
        $offer = $event->getData();
        if ($offer instanceof VolunteerOffer && true === $event->getForm()->get('openEnded')->getData()) {
            $offer->setRepeatUntil(null);
        }
    }

    /**
     * Comprueba que las franjas horarias tienen sentido, y las deja ordenadas.
     *
     * Lo que se para aquí son errores de dedo que hasta ahora se guardaban sin
     * más: una franja que acaba antes de empezar («de 11:11 a 10:12», y salió
     * una así), una hora de fin sin hora de inicio —que se ignoraba en
     * silencio—, y dos franjas que se pisan. Se ordenan por hora de inicio
     * antes de mirar si se pisan, porque el orden en que se teclearon no
     * significa nada: el generador cruza cada fecha con cada franja.
     *
     * Una franja que cruza la medianoche («de 22:00 a 02:00») tampoco pasa,
     * aunque el generador de turnos sabría interpretarla: en esta casa no hay
     * trabajo de voluntariado a esas horas, y aceptar el caso raro es aceptar
     * todos los dedazos que se le parecen.
     *
     * Los errores se cuelgan de la colección, no de la franja: una franja es
     * una fila sin sitio para un párrafo debajo, y el número dice cuál es. El
     * número es el de la fila TAL COMO SE TECLEÓ, porque la pantalla repinta
     * las filas en ese orden; numerarlas ya ordenadas señalaría a otra fila.
     *
     * @param FormEvent $event el envío del formulario
     */
    private function validateTimeSlots(FormEvent $event): void
    {
        $offer = $event->getData();
        $form = $event->getForm();
        if (!$offer instanceof VolunteerOffer || !$form->has('repeatTimes')) {
            return;
        }

        // Cada franja con el número de fila con que se tecleó, antes de ordenar.
        $numbered = [];
        foreach (array_values($offer->getRepeatTimes()) as $index => $slot) {
            $numbered[] = [$index + 1, $slot];
        }
        usort($numbered, static fn (array $a, array $b): int => ($a[1][0] ?? '') <=> ($b[1][0] ?? ''));
        $offer->setRepeatTimes(array_column($numbered, 1));

        $previous = null;
        foreach ($numbered as [$number, [$start, $end]]) {
            if (null === $start) {
                $this->error($form, 'repeatTimes', sprintf('La franja %d tiene hora de fin pero no de inicio.', $number));
                continue;
            }

            if (null !== $end && $end <= $start) {
                $this->error($form, 'repeatTimes', sprintf('La franja %d acaba antes de empezar: la hora de fin tiene que ser posterior a la de inicio.', $number));
            }

            if (null !== $previous && $start <= $previous[1]) {
                $this->error($form, 'repeatTimes', sprintf('Las franjas %d y %d se pisan.', min($previous[0], $number), max($previous[0], $number)));
            }

            $previous = [$number, $end ?? $start];
        }
    }

    /**
     * Deja la cadencia en «todas las semanas» cuando la repetición no es por
     * días fijos, que es la única que la lee.
     *
     * El campo se esconde en pantalla en los demás casos, pero un campo
     * escondido viaja igual: sin esto, cambiar una tarea de «días fijos,
     * quincenal» a «una vez al mes» dejaría un 2 guardado que no significa
     * nada y que reaparecería al volver a días fijos.
     *
     * @param FormEvent $event el envío del formulario
     */
    private function normalizeCadence(FormEvent $event): void
    {
        $offer = $event->getData();
        if ($offer instanceof VolunteerOffer && VolunteerOffer::REPEAT_WEEKLY !== $offer->getRepeatType()) {
            $offer->setRepeatEvery(1);
        }
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

        // Los días son obligatorios también en la mensual. El generador sabría
        // apañarse sin ellos —tomaría el día de la semana del «desde el»—, pero
        // eso es una regla que nadie ve: «el segundo martes» tiene que salir de
        // haber marcado el martes, no de la fecha que se puso al crearla.
        if (\in_array($type, [VolunteerOffer::REPEAT_WEEKLY, VolunteerOffer::REPEAT_MONTHLY], true) && [] === $offer->getRepeatWeekdays()) {
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

        // Sin fecha final y sin la casilla de «sin fin»: no se sabe qué se
        // quiso. Con la casilla, null es la respuesta y no hay más que mirar.
        if (null === $until) {
            if (true !== $form->get('openEnded')->getData()) {
                $this->error($form, 'repeatUntil', 'Si se repite, dime hasta cuándo, o marca que no tiene fin definido.');
            }

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

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VolunteerOffer::class,
        ]);
    }
}
