<?php

namespace App\Form;

use App\Entity\BasketShare;
use App\Entity\PartnerBasketShare;
use App\Entity\WeeklyBasketGroup;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class PartnerBasketShareType extends AbstractType
{
    /**
     * Posiciones que puede ocupar una cesta mensual en el mes. El índice
     * negativo es "la última", que no es lo mismo que "la 4ª" en los meses de
     * 5 semanas (ver PartnerBasketShare::$day_month_order).
     *
     * @var array<string,int>
     */
    private const MONTH_ORDER_CHOICES = [
        '1ª entrega del mes' => 1,
        '2ª entrega del mes' => 2,
        '3ª entrega del mes' => 3,
        'Última entrega del mes' => PartnerBasketShare::DAY_MONTH_ORDER_LAST,
    ];

    /**
     * `value` del `<option>` para los desplegables que ofrecen una opción vacía:
     * el propio valor de negocio, y cadena vacía para el hueco.
     *
     * Sin esto Symfony NO usa los valores como `value`: en cuanto una opción
     * vale `null` (y las tres de este formulario la tienen — «No corresponde»,
     * «Sin turno») deja de poder convertirlos a string y cae en su fallback,
     * que son ÍNDICES POSICIONALES. El `<select>` acababa con value 0, 1, 2, 3,
     * 4 mientras el JS de _form_conditionals razona con 1, 2, 3, -1 y con la
     * cadena vacía. Consecuencias reales (2026-08-27): al pasar una cesta a
     * mensual el desplegable perdía «3ª» y «Última» (índices 3 y 4, descartados
     * por el recorte `n <= 2`); el JS creía que SIEMPRE había turno, porque
     * «Sin turno» valía "0" y no ""; y el `required` del navegador no impedía
     * enviar «No corresponde» por lo mismo. La regla no puede vivir en el JS:
     * es el HTML el que tiene que hablar el idioma del dominio.
     */
    private const CHOICE_VALUE = [self::class, 'choiceValue'];

    /**
     * @param int|string|null $choice Valor de negocio de la opción.
     * @return string Su `value` en el HTML.
     */
    public static function choiceValue(int|string|null $choice): string
    {
        return $choice === null ? '' : (string) $choice;
    }

    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // El alta de socio ya no pide grupo de recogida: si el socio llega
        // aquí sin grupo, se elige junto con la cesta (el nodo del grupo
        // determina qué modalidades caben y el turno A/B). No mapeado: lo
        // persiste el controller en el Partner, no en el PartnerBasketShare.
        if ($options['ask_pickup_group']) {
            $builder->add('pickupGroup', EntityType::class, [
                'class' => WeeklyBasketGroup::class,
                'label' => 'Grupo de recogida',
                'mapped' => false,
                'required' => true,
                'placeholder' => 'Elige punto y grupo de recogida',
                'data' => $options['pickup_group'],
                'constraints' => [new NotBlank(message: 'Indica el grupo de recogida')],
            ]);

            // El grupo se elige aquí pero vive en el Partner, y hasta ahora sólo
            // se le asignaba DESPUÉS de validar: la validación de la cesta veía
            // un socio sin punto y no podía contrastar nada contra él (justo el
            // alta en un punto recién creado). POST_SUBMIT corre antes que el
            // ValidationListener, así que aquí el nodo ya está en el grafo. El
            // controller sigue siendo quien persiste.
            $builder->addEventListener(
                FormEvents::POST_SUBMIT,
                static function (FormEvent $event): void {
                    $share = $event->getData();
                    $group = $event->getForm()->get('pickupGroup')->getData();
                    if ($share instanceof PartnerBasketShare && $group instanceof WeeklyBasketGroup) {
                        $share->getPartner()?->setWeeklyBasketGroup($group);
                    }
                }
            );
        }

        $builder

            ->add('start_date', TextType::class, array('label' => 'Fecha de inicio', 'help'=>"Si la cesta es mensual o sólo tiene huevos una vez al mes, debes indicar la fecha del primer día del mes en que empieza a recibir", 'attr' => array('class' => 'datepicker form-control')))
            ->add('partner')
            ->add('basket_share', EntityType::class, [
                'class' => BasketShare::class,
                'label' => 'Tipo de cesta',
                'required' => true,
                // El punto de recogida restringe las modalidades que caben en
                // él: en uno quincenal (Cascorro, Midori) no cabe una cesta de
                // reparto semanal, y en uno mensual (El Berrueco) sólo caben
                // las mensuales, porque abre una única vez al mes. La lista la
                // calcula CohortChoiceBuilder a partir de la cadencia del nodo.
                'query_builder' => static function (EntityRepository $repo) use ($options) {
                    $qb = $repo->createQueryBuilder('bs')->orderBy('bs.id', 'ASC');
                    if ($options['allowed_share_ids'] !== null) {
                        $qb->where('bs.id IN (:allowed)')
                            ->setParameter('allowed', $options['allowed_share_ids']);
                    }
                    return $qb;
                },
            ])
            ->add('egg_amount',null,array('label'=>'Cantidad de huevos', 'placeholder'=>'No quiere huevos'))
            ->add('egg_period',null,array('label'=>'Frecuencia de recogida de huevos'))
            // Sólo para huevos mensuales: en cuál de las ENTREGAS DE CESTA del
            // socio en el mes viajan los huevos (no el viernes del calendario).
            // Caso Miriam: quincenal que recoge 1er y 3er viernes; "2ª entrega"
            // = los huevos van en su 3er viernes (su segunda cesta). El resolver
            // cuenta sobre las cestas del socio, así que los huevos nunca caen en
            // un día sin cesta. Ver EggDeliveryResolver::shareBaselineDeliveriesInMonth.
            ->add('eggDayMonthOrder', ChoiceType::class, [
                'choices'  => [
                    'No corresponde' => null,
                    'En su 1ª cesta del mes' => 1,
                    'En su 2ª cesta del mes' => 2,
                    'En su 3ª cesta del mes' => 3,
                    'En su última cesta del mes' => -1,
                ],
                'label' => 'En qué cesta del mes recibe los huevos',
                'help' => 'Los huevos viajan dentro de una de las cestas del socio (nunca en un día sin cesta). Una quincenal que recoge el 1er y 3er viernes y elige «2ª cesta» recibe los huevos en su segunda cesta. «Última cesta» sigue al último reparto del mes aunque el mes tenga 5 semanas.',
                'choice_value' => self::CHOICE_VALUE,
            ])
            // Qué entrega del mes recoge una cesta MENSUAL. Sobre qué se cuenta
            // depende del turno: sin turno, los viernes del mes; con turno, las
            // entregas de ese turno (ver MonthlyOperativeOrderResolver). De ahí
            // que las etiquetas hablen de "entrega" y no de "viernes".
            ->add('dayMonthOrder', ChoiceType::class, $this->monthOrderOptions(
                $options['forced_month_order'],
                $options['offered_month_orders'],
            ))
            ->add('deliveryGroup', ChoiceType::class, [
                'label' => 'Turno de viernes',
                'help' => 'Sólo en puntos de reparto semanales. En quincenales decide qué viernes recoge; en mensuales, con qué turno coincide (su orden se cuenta sobre las entregas de ese turno). Cada opción muestra los viernes reales.',
                'choices' => $options['cohort_choices'],
                // Sin opción vacía: el turno es obligatorio para las
                // quincenales, y el JS del formulario añade "Sin turno" cuando
                // la modalidad es mensual (ahí es opcional). Para el resto de
                // modalidades se anula en server.
                'placeholder' => false,
                'required' => false,
                'choice_value' => self::CHOICE_VALUE,
            ])
            ->add('isFreeBasket', CheckboxType::class, array('label'=>'Marca esta casilla si es una cesta gratuita', 'required'=>false))
            ->add('amount',ChoiceType::class,array(
                'choices'  => [
                    '1' => 1,
                    '2' => 2,
                    '3' => 3,
                    '4' => 4,
                ],'label'=>'Cantidad de cestas','help'=>'Lo normal es que sea siempre 1, 
                es sólo para casos especiales, como alguna gratuita, en que se asocian varias cestas de verdura (siempre todas iguales en periodicidad) a la misma ficha de socia/o. La cantidad total de huevos será la que pongas en el campo de huevos, no le influye este campo.', 'required'=>true))
        ;

        // Punto mensual: la semana no se elige, la impone el punto. Se fija en
        // el propio objeto para que el campo (deshabilitado) no dependa de lo
        // que llegue del navegador, y para que un socio dado de alta antes de
        // que administración cambiara la semana quede corregido al editarlo.
        if ($options['forced_month_order'] !== null) {
            $builder->addEventListener(
                FormEvents::PRE_SET_DATA,
                static function (FormEvent $event) use ($options): void {
                    $share = $event->getData();
                    if (!$share instanceof PartnerBasketShare) {
                        return;
                    }
                    $share->setDayMonthOrder($options['forced_month_order']);
                    // El turno A/B no pinta nada donde sólo hay una entrega al mes.
                    $share->setDeliveryGroup(null);
                }
            );
        }
    }

    /**
     * Opciones del campo "qué entrega del mes recoge la cesta". Cuando el punto
     * de recogida es mensual la posición no se elige: se muestra la única
     * posible y el campo queda deshabilitado, porque allí todos los socios
     * recogen la semana que abre el punto.
     *
     * En el resto de puntos sí se elige, pero sólo entre las entregas que ese
     * punto abre TODOS los meses ({@see \App\Entity\Node::offeredMonthOrders}):
     * ofrecer la "3ª entrega" en un punto quincenal, que sólo abre tres veces
     * en los meses largos, es invitar a configurar un socio que se queda sin
     * cesta la mayoría de los meses y sin ningún aviso.
     *
     * @param int|null   $forced  Semana impuesta por el punto, o null si se elige.
     * @param int[]|null $offered Posiciones que el punto sirve siempre, o null
     *                            si el socio aún no tiene punto (se ofrecen todas).
     * @return array<string,mixed> Opciones para el ChoiceType.
     */
    private function monthOrderOptions(?int $forced, ?array $offered): array
    {
        if ($forced === null) {
            $choices = $offered === null
                ? self::MONTH_ORDER_CHOICES
                : array_filter(
                    self::MONTH_ORDER_CHOICES,
                    static fn (int $order): bool => in_array($order, $offered, true),
                );

            return [
                'choices' => ['No corresponde' => null] + $choices,
                'label' => 'Qué entrega del mes recoge la cesta',
                'help' => 'Obligatorio en las cestas mensuales: sin este dato la cesta no aparece en ningún reparto. Sin turno asignado se cuentan los viernes del mes (1ª = primer viernes); con turno, las entregas de ese turno, así el socio coincide siempre con su grupo. «Última entrega» sigue al último reparto del mes.',
                'choice_value' => self::CHOICE_VALUE,
            ];
        }

        return [
            'choices' => array_filter(
                self::MONTH_ORDER_CHOICES,
                static fn (int $order): bool => $order === $forced,
            ),
            'label' => 'Qué entrega del mes recoge la cesta',
            'choice_value' => self::CHOICE_VALUE,
            'disabled' => true,
            'help' => 'Este punto de recogida abre una sola semana al mes, así que la entrega no se elige: es la que abre el punto. Para cambiarla hay que cambiar la semana del punto de recogida.',
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PartnerBasketShare::class,
            // Choices del turno A/B. El controlador las sobrescribe con las
            // fechas reales del nodo del socio; este default cubre new/edit.
            'cohort_choices' => [
                'Sin asignar' => null,
                'Grupo A' => PartnerBasketShare::DELIVERY_GROUP_A,
                'Grupo B' => PartnerBasketShare::DELIVERY_GROUP_B,
            ],
            // Modalidades que admite el punto de recogida, o null si no lo
            // restringe. La calcula CohortChoiceBuilder según su cadencia.
            'allowed_share_ids' => null,
            // Entregas del mes que el punto abre todos los meses, o null si el
            // socio aún no tiene punto. Acota el desplegable de posición mensual.
            'offered_month_orders' => null,
            // Semana del mes que impone un punto de cadencia mensual: allí no
            // se elige, la fija el punto y todos sus socios recogen ese día.
            'forced_month_order' => null,
            // Pide el grupo de recogida en el propio form (socio sin grupo).
            'ask_pickup_group' => false,
            // Preselección del grupo (la elección hecha antes de recargar).
            'pickup_group' => null,
        ]);
        $resolver->setAllowedTypes('cohort_choices', 'array');
        $resolver->setAllowedTypes('allowed_share_ids', ['null', 'int[]']);
        $resolver->setAllowedTypes('offered_month_orders', ['null', 'int[]']);
        $resolver->setAllowedTypes('forced_month_order', ['null', 'int']);
        $resolver->setAllowedTypes('ask_pickup_group', 'bool');
        $resolver->setAllowedTypes('pickup_group', ['null', WeeklyBasketGroup::class]);
    }
}
