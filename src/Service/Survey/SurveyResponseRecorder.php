<?php

namespace App\Service\Survey;

use App\Entity\Partner;
use App\Entity\Question;
use App\Entity\Survey;
use App\Entity\SurveyAnswer;
use App\Entity\SurveyParticipation;
use App\Form\SurveyResponseType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Guarda la respuesta de una socia a una encuesta.
 *
 * ES EL ÚNICO SITIO QUE ESCRIBE RESPUESTAS, y por eso el único que hay que
 * leer para fiarse del anonimato. Responde desde dos puertas —el panel, con
 * sesión, y el enlace firmado del correo, sin ella— y las dos pasan por aquí.
 *
 * Anonimato "de confianza" (nivel A): se escriben las {@see SurveyAnswer}, que
 * no llevan referencia a la socia ni fecha, y por separado una
 * {@see SurveyParticipation}, que sabe quién participó pero no qué contestó. No
 * hay forma de cruzar respuesta y autora.
 *
 * El antiduplicado es el UNIQUE(survey, partner) de survey_participation: un
 * segundo envío revienta en el flush y la transacción se lleva también las
 * respuestas, así que no queda nada a medias.
 */
class SurveyResponseRecorder
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * Guarda respuestas y participación en una sola transacción.
     *
     * @param Survey               $survey  la encuesta respondida
     * @param Partner              $partner quien responde
     * @param array<string, mixed> $data    datos del {@see SurveyResponseType}: nombre de campo => valor
     *
     * @return bool true si se guardó; false si esa socia ya había respondido
     */
    public function record(Survey $survey, Partner $partner, array $data): bool
    {
        try {
            $this->entityManager->wrapInTransaction(function () use ($survey, $partner, $data): void {
                foreach ($survey->getQuestions() as $question) {
                    $value = $data[SurveyResponseType::fieldName($question)] ?? null;

                    foreach ($this->answersFor($question, $value) as $answer) {
                        $this->entityManager->persist($answer);
                    }
                }

                $this->entityManager->persist(
                    (new SurveyParticipation())->setSurvey($survey)->setPartner($partner)
                );
            });
        } catch (UniqueConstraintViolationException) {
            // Doble envío o carrera: el UNIQUE(survey, partner) lo corta en seco.
            return false;
        }

        return true;
    }

    /**
     * Traduce el valor enviado para una pregunta a sus {@see SurveyAnswer}.
     * Una respuesta vacía (no obligatoria sin contestar) no genera filas.
     *
     * @param Question $question la pregunta
     * @param mixed    $value    id de opción (single), array de ids (multiple),
     *                           entero 1-5 (scale) o texto (text)
     *
     * @return SurveyAnswer[]
     */
    private function answersFor(Question $question, mixed $value): array
    {
        return match ($question->getType()) {
            Question::TYPE_SINGLE => null !== $value && '' !== $value
                ? [$this->optionAnswer($question, (int) $value)]
                : [],
            Question::TYPE_MULTIPLE => array_map(
                fn (int $optionId): SurveyAnswer => $this->optionAnswer($question, $optionId),
                array_map('intval', \is_array($value) ? $value : [])
            ),
            Question::TYPE_SCALE => null !== $value && '' !== $value
                ? [(new SurveyAnswer())->setQuestion($question)->setValueInt((int) $value)]
                : [],
            Question::TYPE_TEXT => \is_string($value) && '' !== trim($value)
                ? [(new SurveyAnswer())->setQuestion($question)->setValueText(trim($value))]
                : [],
            default => [],
        };
    }

    /**
     * Una respuesta que apunta a una opción concreta de la pregunta. La opción
     * sale de la colección ya cargada de la pregunta (de ahí salieron las
     * choices del formulario), sin volver a la base.
     *
     * @param Question $question la pregunta
     * @param int      $optionId id de la opción elegida
     *
     * @throws \RuntimeException si la opción no es de esta pregunta
     */
    private function optionAnswer(Question $question, int $optionId): SurveyAnswer
    {
        $option = $question->getOptions()->findFirst(
            static fn (int $key, $candidate): bool => $candidate->getId() === $optionId
        );

        if (null === $option) {
            // El value vino de las opciones del propio formulario: no debería pasar.
            throw new \RuntimeException('Opción de respuesta no válida.');
        }

        return (new SurveyAnswer())->setQuestion($question)->setOption($option);
    }
}
