<?php

namespace App\Form;

use App\Entity\QuestionOption;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Una opción de una pregunta single/multiple, dentro de la colección de
 * {@see QuestionType}. Solo expone la etiqueta; la posición la fija el
 * controlador según el orden de envío.
 */
class QuestionOptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('label', TextType::class, [
            'label'       => false,
            'attr'        => ['placeholder' => 'Texto de la opción'],
            'constraints' => [new NotBlank(message: 'La opción no puede estar vacía.')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => QuestionOption::class,
        ]);
    }
}
