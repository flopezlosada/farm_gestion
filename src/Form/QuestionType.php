<?php

namespace App\Form;

use App\Entity\Question;
use App\Entity\QuestionOption;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form de una pregunta dentro de la {@see SurveyType}.
 *
 * Las opciones (single/multiple) NO se modelan como sub-colección de inputs,
 * sino como un único textarea "una opción por línea" ({@see $optionsText},
 * campo no mapeado). Así el form de la encuesta tiene un solo nivel de
 * colección dinámica (las preguntas) en lugar de dos anidados, que es donde se
 * concentra el dolor de JS y prototipos. La conversión texto ↔ QuestionOption
 * se hace aquí, encapsulada en dos listeners:
 *
 *   - PRE_SET_DATA  → al editar, vuelca las opciones existentes al textarea.
 *   - POST_SUBMIT   → reconstruye las QuestionOption desde las líneas del
 *                     textarea (sólo si el tipo usa opciones).
 *
 * Reconstruir (limpiar + recrear) es seguro porque la encuesta sólo se edita
 * en borrador, cuando todavía no hay respuestas que puedan apuntar a una opción.
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
                'label' => 'Pregunta',
            ])
            ->add('type', ChoiceType::class, [
                'label'   => 'Tipo',
                'choices' => self::TYPE_CHOICES,
            ])
            ->add('required', CheckboxType::class, [
                'label'    => 'Obligatoria',
                'required' => false,
            ]);

        // Al cargar (edición), volcamos las opciones existentes al textarea.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $question = $event->getData();
            $optionsText = '';
            if ($question instanceof Question) {
                $labels = array_map(
                    static fn (QuestionOption $o): string => $o->getLabel(),
                    $question->getOptions()->toArray()
                );
                $optionsText = implode("\n", $labels);
            }

            $event->getForm()->add('optionsText', TextareaType::class, [
                'label'    => 'Opciones (una por línea)',
                'mapped'   => false,
                'required' => false,
                'data'     => $optionsText,
                'attr'     => ['rows' => 4, 'placeholder' => "Lunes\nMartes\nMiércoles"],
            ]);
        });

        // Al enviar, reconstruimos las QuestionOption desde el textarea.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $question = $event->getData();
            if (!$question instanceof Question) {
                return;
            }

            // orphanRemoval=true borra de BBDD las que quitemos aquí.
            foreach ($question->getOptions()->toArray() as $existing) {
                $question->removeOption($existing);
            }

            if (!$question->usesOptions()) {
                return;
            }

            $raw = (string) $event->getForm()->get('optionsText')->getData();
            $labels = array_values(array_filter(
                array_map('trim', explode("\n", $raw)),
                static fn (string $line): bool => '' !== $line
            ));

            foreach ($labels as $position => $label) {
                $option = (new QuestionOption())
                    ->setLabel($label)
                    ->setPosition($position);
                $question->addOption($option);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Question::class,
        ]);
    }
}
