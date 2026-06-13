<?php

namespace App\Form;

use App\Entity\Question;
use App\Entity\QuestionOption;
use App\Entity\Survey;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Formulario con el que un socix RESPONDE una encuesta. No mapea a una entidad:
 * devuelve un array {@see fieldName} => valor, que el controlador traduce a
 * {@see \App\Entity\SurveyAnswer}. Se construye al vuelo a partir de las
 * preguntas de la {@see Survey} que se pasa por la opción `survey`.
 *
 * Cada tipo de pregunta se pinta con el widget adecuado (expanded = radios /
 * checkboxes, nunca un desplegable ni texto libre salvo el tipo texto):
 *   - single   → radios (una opción).
 *   - multiple → checkboxes (varias).
 *   - scale    → radios 1..5.
 *   - text     → textarea.
 */
class SurveyResponseType extends AbstractType
{
    /** Prefijo del nombre de cada campo: "q" + id de la pregunta (q42). */
    public const FIELD_PREFIX = 'q';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Survey $survey */
        $survey = $options['survey'];

        foreach ($survey->getQuestions() as $question) {
            $name = self::fieldName($question);

            match ($question->getType()) {
                Question::TYPE_SINGLE   => $this->addChoice($builder, $name, $question, multiple: false),
                Question::TYPE_MULTIPLE => $this->addChoice($builder, $name, $question, multiple: true),
                Question::TYPE_SCALE    => $this->addScale($builder, $name, $question),
                Question::TYPE_TEXT     => $this->addText($builder, $name, $question),
                default                 => null,
            };
        }
    }

    /**
     * Nombre del campo del formulario para una pregunta. Centralizado para que
     * el form y el controlador coincidan al construir y al leer la respuesta.
     */
    public static function fieldName(Question $question): string
    {
        return self::FIELD_PREFIX.$question->getId();
    }

    /**
     * Radios (single) o checkboxes (multiple) con las opciones de la pregunta.
     * El value de cada opción es su id, que es lo que el controlador persiste.
     */
    private function addChoice(FormBuilderInterface $builder, string $name, Question $question, bool $multiple): void
    {
        $choices = [];
        foreach ($question->getOptions() as $option) {
            $choices[$option->getLabel()] = $option->getId();
        }

        $constraints = [];
        if ($question->isRequired()) {
            $constraints[] = $multiple
                ? new Count(min: 1, minMessage: 'Marca al menos una opción.')
                : new NotBlank(message: 'Elige una opción.');
        }

        $builder->add($name, ChoiceType::class, [
            'label'       => $question->getText(),
            'choices'     => $choices,
            'expanded'    => true,
            'multiple'    => $multiple,
            'required'    => $question->isRequired(),
            'constraints' => $constraints,
        ]);
    }

    /**
     * Escala 1..5 como radios. Rango fijo definido en {@see Question}.
     */
    private function addScale(FormBuilderInterface $builder, string $name, Question $question): void
    {
        $choices = [];
        for ($i = Question::SCALE_MIN; $i <= Question::SCALE_MAX; ++$i) {
            $choices[(string) $i] = $i;
        }

        $builder->add($name, ChoiceType::class, [
            'label'       => $question->getText(),
            'choices'     => $choices,
            'expanded'    => true,
            'multiple'    => false,
            'required'    => $question->isRequired(),
            'constraints' => $question->isRequired() ? [new NotBlank(message: 'Elige una valoración.')] : [],
        ]);
    }

    /**
     * Texto libre.
     */
    private function addText(FormBuilderInterface $builder, string $name, Question $question): void
    {
        $builder->add($name, TextareaType::class, [
            'label'       => $question->getText(),
            'required'    => $question->isRequired(),
            'attr'        => ['rows' => 3],
            'constraints' => $question->isRequired() ? [new NotBlank(message: 'Esta pregunta es obligatoria.')] : [],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // No mapea a entidad: devuelve un array que el controlador traduce.
            'data_class' => null,
        ]);
        $resolver->setRequired('survey');
        $resolver->setAllowedTypes('survey', Survey::class);
    }
}
