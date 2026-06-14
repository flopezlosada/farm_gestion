<?php

namespace App\Tests\Controller;

use App\Entity\Question;
use App\Entity\QuestionOption;
use App\Entity\Setting;
use App\Entity\Survey;
use App\Entity\SurveyAnswer;
use App\Entity\SurveyParticipation;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Utilidades compartidas por los tests funcionales de encuestas: encender el
 * feature-flag, construir encuestas de estructura conocida vía EM y limpiar
 * todo al final (las encuestas creadas y los overrides de configuración) para
 * no contaminar el resto de la suite, que cuenta con los defaults del catálogo.
 *
 * La limpieza se apoya en el ON DELETE CASCADE de las FKs: borrar la fila de
 * `survey` arrastra preguntas, opciones, respuestas y participaciones.
 */
trait SurveyTestTrait
{
    /** @var int[] Ids de las encuestas creadas en el test, para borrarlas al final. */
    private array $createdSurveyIds = [];

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Enciende el módulo de encuestas (los controllers están tras
     * #[IsGranted('FEATURE_SURVEYS')], que por defecto está apagado).
     */
    private function enableSurveys(): void
    {
        static::getContainer()->get(AppSettings::class)->setBool(AppSettings::FEATURE_SURVEYS, true);
    }

    /**
     * Crea una encuesta con una pregunta de cada tipo (scale, single con 3
     * opciones, multiple con 4, texto) en el estado dado, y la deja persistida.
     * Devuelve la encuesta ya con ids.
     */
    private function makeFullSurvey(string $status = Survey::STATUS_OPEN, string $title = 'Encuesta de prueba'): Survey
    {
        $survey = (new Survey())->setTitle($title)->setStatus($status);

        $scale = (new Question())->setText('¿Cómo lo valoras?')->setType(Question::TYPE_SCALE)->setPosition(0)->setRequired(true);
        $survey->addQuestion($scale);

        $single = (new Question())->setText('¿Qué día prefieres?')->setType(Question::TYPE_SINGLE)->setPosition(1)->setRequired(true);
        foreach (['Lunes', 'Miércoles', 'Viernes'] as $i => $label) {
            $single->addOption((new QuestionOption())->setLabel($label)->setPosition($i));
        }
        $survey->addQuestion($single);

        $multiple = (new Question())->setText('¿Qué añadirías?')->setType(Question::TYPE_MULTIPLE)->setPosition(2)->setRequired(false);
        foreach (['Pan', 'Aceite', 'Queso', 'Miel'] as $i => $label) {
            $multiple->addOption((new QuestionOption())->setLabel($label)->setPosition($i));
        }
        $survey->addQuestion($multiple);

        $text = (new Question())->setText('Sugerencias')->setType(Question::TYPE_TEXT)->setPosition(3)->setRequired(false);
        $survey->addQuestion($text);

        $em = $this->em();
        $em->persist($survey);
        $em->flush();

        $this->createdSurveyIds[] = $survey->getId();

        return $survey;
    }

    /**
     * Crea una encuesta mínima (sin preguntas) en el estado dado.
     */
    private function makeEmptySurvey(string $status = Survey::STATUS_DRAFT, string $title = 'Encuesta vacía'): Survey
    {
        $survey = (new Survey())->setTitle($title)->setStatus($status);
        $em = $this->em();
        $em->persist($survey);
        $em->flush();
        $this->createdSurveyIds[] = $survey->getId();

        return $survey;
    }

    /**
     * Registra una participación de un partner en la encuesta (la mitad "con
     * identidad" del modelo). No persiste respuestas: sirve para que cuente
     * como votada (denominador y bloqueo de borrado).
     */
    private function addParticipation(Survey $survey, \App\Entity\Partner $partner): void
    {
        $em = $this->em();
        $em->persist((new SurveyParticipation())->setSurvey($survey)->setPartner($partner));
        $em->flush();
    }

    /**
     * Crea $count respuestas anónimas eligiendo una opción concreta (single/
     * multiple). Sin vínculo al partner: solo cuenta para el agregado.
     */
    private function addOptionAnswers(Question $question, QuestionOption $option, int $count): void
    {
        $em = $this->em();
        for ($i = 0; $i < $count; ++$i) {
            $em->persist((new SurveyAnswer())->setQuestion($question)->setOption($option));
        }
        $em->flush();
    }

    /**
     * Borra las encuestas creadas (CASCADE arrastra el resto) y los overrides
     * de configuración. Llamar desde el tearDown del test.
     */
    private function cleanupSurveys(): void
    {
        $em = $this->em();
        $conn = $em->getConnection();

        if ($this->createdSurveyIds !== []) {
            $conn->executeStatement(
                'DELETE FROM survey WHERE id IN (?)',
                [$this->createdSurveyIds],
                [\Doctrine\DBAL\ArrayParameterType::INTEGER],
            );
            $this->createdSurveyIds = [];
        }

        foreach ($em->getRepository(Setting::class)->findAll() as $setting) {
            $em->remove($setting);
        }
        $em->flush();
    }
}
