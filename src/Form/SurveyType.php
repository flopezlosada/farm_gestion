<?php

namespace App\Form;

use App\Entity\Survey;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Form de creación/edición de una encuesta, en una sola pantalla: cabecera
 * (título, descripción, cierre) + colección dinámica de preguntas
 * ({@see QuestionType}). El alta/baja de preguntas en caliente se resuelve con
 * el prototipo de CollectionType + un poco de JS en la plantilla.
 */
class SurveyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label'       => 'Título',
                // Vacío llega como '' y no como null: el setter es `string`.
                'empty_data'  => '',
                'constraints' => [new NotBlank(message: 'Ponle un título a la encuesta.')],
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'Descripción',
                'required' => false,
                'attr'     => ['rows' => 3],
            ])
            ->add('closesAt', DateType::class, [
                'label'    => 'Último día para responder',
                'required' => false,
                'widget'   => 'single_text',
                'html5'    => true,
            ])
            ->add('questions', CollectionType::class, [
                'entry_type'    => QuestionType::class,
                'allow_add'     => true,
                'allow_delete'  => true,
                'by_reference'  => false,
                'label'         => false,
                'prototype'     => true,
                'prototype_name' => '__question__',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Survey::class,
        ]);
    }
}
