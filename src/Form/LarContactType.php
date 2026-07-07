<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Formulario público de petición de información al LAR. El envío acaba en
 * larcsa@csavegadejarama.org. Recoge datos accionables para que la comisión
 * pueda responder de una: tipo de solicitud, tamaño del grupo y fechas
 * aproximadas, además del mensaje libre.
 *
 * Clona el estilo bootstrap del {@see ContactType} porque se pinta sobre la
 * base pública (base_front), no sobre el shell de gestión.
 */
class LarContactType extends AbstractType
{
    /** Tipos de solicitud, alineados con la oferta del LAR. */
    public const REQUESTS = [
        'visita_pedagogica' => 'Visita pedagógica / de sensibilización',
        'estancia_inmersion' => 'Estancia de inmersión pedagógica agroecológica',
        'otra' => 'Otra consulta',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => false,
                'attr' => ['placeholder' => 'Nombre o entidad...', 'class' => 'form-control p-3'],
                'constraints' => [
                    new NotBlank(message: 'Por favor, indica tu nombre o el de tu entidad'),
                    new Length(max: 150),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => false,
                'attr' => ['placeholder' => 'Correo...', 'class' => 'form-control p-3'],
                'constraints' => [
                    new NotBlank(message: 'Por favor, indica un email'),
                    new Email(message: 'Tu email parece incorrecto'),
                ],
            ])
            ->add('phone', TextType::class, [
                'label' => false,
                'required' => false,
                'attr' => ['placeholder' => 'Teléfono (opcional)...', 'class' => 'form-control p-3'],
                'constraints' => [new Length(max: 30)],
            ])
            ->add('requestType', ChoiceType::class, [
                'label' => false,
                'placeholder' => '¿Qué te interesa?',
                'choices' => array_flip(self::REQUESTS),
                'attr' => ['class' => 'form-control p-3'],
                'constraints' => [new NotBlank(message: 'Indica qué tipo de solicitud es')],
            ])
            ->add('groupSize', IntegerType::class, [
                'label' => false,
                'required' => false,
                'attr' => ['placeholder' => 'Nº de personas (opcional)...', 'class' => 'form-control p-3', 'min' => 1],
            ])
            ->add('preferredDates', TextType::class, [
                'label' => false,
                'required' => false,
                'attr' => ['placeholder' => 'Fechas aproximadas (opcional)...', 'class' => 'form-control p-3'],
                'constraints' => [new Length(max: 150)],
            ])
            ->add('body', TextareaType::class, [
                'label' => false,
                'attr' => ['placeholder' => 'Cuéntanos...', 'class' => 'form-control p-3', 'rows' => 4],
                'constraints' => [
                    new NotBlank(message: 'Por favor, escribe tu consulta'),
                    new Length(max: 5000),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Enviar solicitud',
                'attr' => ['class' => 'btn btn-primary btn-block border-0 py-2'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'lar_contact_form',
        ]);
    }
}
