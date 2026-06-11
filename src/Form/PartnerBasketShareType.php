<?php

namespace App\Form;

use App\Entity\BasketShare;
use App\Entity\PartnerBasketShare;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PartnerBasketShareType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder

            ->add('start_date', TextType::class, array('label' => 'Fecha de inicio', 'help'=>"Si la cesta es mensual o sólo tiene huevos una vez al mes, debes indicar la fecha del primer día del mes en que empieza a recibir", 'attr' => array('class' => 'datepicker form-control')))
            ->add('partner')
            ->add('basket_share', EntityType::class, [
                'class' => BasketShare::class,
                'label' => 'Tipo de cesta',
                'required' => true,
                // En puntos de reparto quincenal (Cascorro, Midori) no caben las
                // cestas de reparto semanal: el nodo sólo reparte cada 2 semanas.
                'query_builder' => static function (EntityRepository $repo) use ($options) {
                    $qb = $repo->createQueryBuilder('bs')->orderBy('bs.id', 'ASC');
                    if ($options['exclude_weekly_shares']) {
                        $qb->where('bs.id NOT IN (:weekly)')
                            ->setParameter('weekly', BasketShare::IDS_WEEKLY);
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
                    'En su 4ª cesta del mes' => 4,
                ],
                'label' => 'En qué cesta del mes recibe los huevos',
                'help' => 'Los huevos viajan dentro de una de las cestas del socio (nunca en un día sin cesta). Una quincenal que recoge el 1er y 3er viernes y elige «2ª cesta» recibe los huevos en su segunda cesta.',
            ])
            ->add('dayMonthOrder', ChoiceType::class,[
                'choices'  => [
                    'No corresponde' => null,
                    'Primer viernes' => 1,
                    'Segundo viernes' => 2,
                    'Tercer viernes' => 3,
                    'Cuarto viernes' => 4,
                ], 'label'=>"Qué viernes del mes recoge la cesta"])
            ->add('deliveryGroup', ChoiceType::class, [
                'label' => 'Turno de viernes (quincenal)',
                'help' => 'Sólo para quincenales en puntos de reparto semanales. Cada opción muestra los viernes reales en que recoge.',
                'choices' => $options['cohort_choices'],
                // Sin opción vacía: el turno es obligatorio cuando aplica
                // (quincenal en nodo semanal). Para el resto se anula en server.
                'placeholder' => false,
                'required' => false,
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
            // Excluye las modalidades de reparto semanal del select de tipo.
            'exclude_weekly_shares' => false,
        ]);
        $resolver->setAllowedTypes('cohort_choices', 'array');
        $resolver->setAllowedTypes('exclude_weekly_shares', 'bool');
    }
}
