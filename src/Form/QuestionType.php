<?php

namespace App\Form;

use App\Entity\Question;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form de una pregunta dentro de la {@see SurveyType}.
 *
 * Las opciones (single/multiple) se modelan como una colección anidada de
 * {@see QuestionOptionType}: cada opción es un campo de texto propio, con
 * botones de añadir/quitar (la plantilla resuelve el prototipo `__option__`).
 * El form de la encuesta tiene por tanto DOS niveles de colección (preguntas →
 * opciones); el dolor de prototipos anidados se concentra en el JS de
 * `survey/_form.html.twig`, pero la UX para el equipo es la natural (un campo
 * por opción) en lugar de un textarea "una por línea".
 *
 * Reordenar/limpiar opciones es seguro porque la encuesta solo se edita en
 * borrador, cuando todavía no hay respuestas que apunten a una opción.
 */
class QuestionType extends AbstractType
{
    /**
     * @var array<string,string>
     */
    private const TYPE_CHOICES = [
        'Opción única'    => Question::TYPE_SINGLE,
        'Opción múltiple' => Question::TYPE_MULTIPLE,
        'Escala 1-5'      => Question::TYPE_SCALE,
        'Texto libre'     => Question::TYPE_TEXT,
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('text', TextType::class, [
                'label'       => 'Pregunta',
                // Vacío llega como '' y no como null: el setter es `string`.
                'empty_data'  => '',
                'constraints' => [new NotBlank(message: 'Escribe la pregunta.')],
            ])
            ->add('type', ChoiceType::class, [
                'label'   => 'Tipo',
                'choices' => self::TYPE_CHOICES,
            ])
            ->add('required', CheckboxType::class, [
                'label'    => 'Obligatoria',
                'required' => false,
            ])
            ->add('options', CollectionType::class, [
                'entry_type'     => QuestionOptionType::class,
                'allow_add'      => true,
                'allow_delete'   => true,
                'by_reference'   => false,
                'label'          => false,
                'prototype'      => true,
                'prototype_name' => '__option__',
                // Un form compuesto sube sus errores al padre por defecto, y el
                // «Añade al menos 2 opciones» acababa en la pregunta, donde no
                // se pinta: el formulario volvía sin decir por qué. Se queda
                // aquí, junto a las opciones, que es donde se arregla.
                'error_bubbling' => false,
            ]);

        // Si el tipo no usa opciones (escala / texto), descartar las que pudieran
        // haber quedado en el DOM al cambiar de tipo. orphanRemoval=true las borra.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $question = $event->getData();
            if ($question instanceof Question && !$question->usesOptions()) {
                foreach ($question->getOptions()->toArray() as $option) {
                    $question->removeOption($option);
                }
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'  => Question::class,
            // Una pregunta de opciones sin opciones sale sin nada que marcar y,
            // si es obligatoria, bloquea el envío de toda la encuesta. Se para
            // al guardar el borrador, no al abrirla con el aviso ya enviado.
            'constraints' => [new Callback(self::validateOptions(...))],
        ]);
    }

    /**
     * Exige {@see Question::MIN_OPTIONS} opciones a las preguntas que las usan.
     * Corre después del POST_SUBMIT de arriba, así que las opciones que se
     * quedaron en el DOM al cambiar a escala o texto ya no cuentan.
     *
     * @param mixed                     $question la pregunta enviada
     * @param ExecutionContextInterface $context  contexto de validación
     */
    public static function validateOptions(mixed $question, ExecutionContextInterface $context): void
    {
        if ($question instanceof Question && !$question->isAnswerable()) {
            $context->buildViolation(sprintf('Añade al menos %d opciones.', Question::MIN_OPTIONS))
                ->atPath('options')
                ->addViolation();
        }
    }
}
