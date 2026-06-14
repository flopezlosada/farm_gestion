<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => false,
                'attr' => ['placeholder' => 'Nombre...', 'class' => 'form-control p-3'],
                'constraints' => [
                    new NotBlank(message: 'Por favor, indica tu nombre'),
                    new Length(max: 100),
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
            ->add('subject', TextType::class, [
                'label' => false,
                'attr' => ['placeholder' => 'Asunto...', 'class' => 'form-control p-3'],
                'constraints' => [
                    new NotBlank(message: 'Por favor, indica un asunto'),
                    new Length(max: 150),
                ],
            ])
            ->add('body', TextareaType::class, [
                'label' => false,
                'attr' => ['placeholder' => 'Mensaje...', 'class' => 'form-control p-3', 'rows' => 4],
                'constraints' => [
                    new NotBlank(message: 'Por favor, indica aquí tu mensaje'),
                    new Length(max: 5000),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Enviar mensaje',
                'attr' => ['class' => 'btn btn-primary btn-block border-0 py-2'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'contact_form',
        ]);
    }
}
