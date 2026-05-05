<?php

namespace App\Form;

use App\Entity\Booking;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BookingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('beginAt',TextType::class, array('label'=>'Fecha del evento', 'attr'=>array('class'=>'datepicker form-control')))
            ->add('endAt',TextType::class, array('label'=>'Fecha de finalización del evento', 'attr'=>array('class'=>'datepicker form-control')))
            ->add('title', null, array('label' => 'Título'))
            ->add('file',null,array(
                "required"=>null===$builder->getData()->getId()?true:false,
                "label"=>"Imagen"
            ))
            ->add('content',TextareaType::class, array(
                'attr' => array('class' => 'tinymce','data-theme' => 'advanced'),
                'label' => "Contenido"    // simple, advanced, bbcode
            ));
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Booking::class,
        ]);
    }
}
