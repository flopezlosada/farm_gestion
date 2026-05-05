<?php

namespace App\Form;

use App\Entity\Partner;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\DateTime;

class PartnerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name', null, array('label'=>'Nombre'))
            ->add('surname', null, array('label'=>'Apellidos'))
            ->add('DNI', null, array('label'=>'DNI'))
            ->add('celular', null, array('label'=>'Teléfono'))
            ->add('address', null, array('label'=>'Dirección'))
            ->add('inscription_date',DateType::class, array('label'=>'Fecha de inscripción', 'widget' => 'single_text', 'html5'=>false,'attr'=>array('class'=>'datepicker form-control')))

            ->add('registration_form_file',null,array(
                "required"=>false,
                "label"=>"Ficha de socia/o"
            ))

            ->add('eat_meat', CheckboxType::class, array('label'=>'¿Consume productos cárnicos?', 'required'=>false))
            ->add('email', EmailType::class, array('label'=>'Correo electrónico'))

            ->add('state',EntityType::class, array('class' => 'App\Entity\State', 'required' => true, 'placeholder' => "Selecciona provincia"))
            ->add('city', EntityType::class, array("label" => "Municipio", 'class' => 'App\Entity\City', 'required' => true))
            ->add('share_payment', null, array('label'=>'Forma de pago', 'required'=>true))
            ->add('weekly_basket_group',null,array('label'=>'Indica si está asociada/o a algún grupo de recogida', 'required'=>true))
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Partner::class,
        ]);
    }
}
