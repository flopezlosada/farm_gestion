<?php

namespace App\Form;

use App\Entity\PartnerBasketShare;
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
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder

            ->add('start_date', TextType::class, array('label' => 'Fecha de inicio', 'help'=>"Si la cesta es mensual o sólo tiene huevos una vez al mes, debes indicar la fecha del primer día del mes en que empieza a recibir", 'attr' => array('class' => 'datepicker form-control')))
            ->add('partner')
            ->add('basket_share', null, array('label'=>'Tipo de cesta', 'required'=>true))
            ->add('egg_amount',null,array('label'=>'Cantidad de huevos', 'placeholder'=>'No quiere huevos'))
            ->add('egg_period',null,array('label'=>'Frecuencia de recogida de huevos'))
            ->add('dayMonthOrder', ChoiceType::class,[
                'choices'  => [
                    'No corresponde' => null,
                    'Primer viernes' => 1,
                    'Segundo viernes' => 2,
                    'Tercer viernes' => 3,
                    'Cuarto viernes' => 4,
                ], 'label'=>"Selecciona el viernes que recoge"])
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

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => PartnerBasketShare::class,
        ]);
    }
}
