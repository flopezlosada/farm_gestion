<?php

namespace App\DataFixtures;

use App\Entity\Question;
use App\Entity\QuestionOption;
use App\Entity\Survey;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Encuestas de muestra para ver el motor funcionando: una en cada estado del
 * ciclo de vida (borrador / abierta / cerrada) y, entre todas, los 4 tipos de
 * pregunta (single, multiple, scale, text).
 *
 * NO genera respuestas ni participaciones: aquí solo se siembra la estructura
 * para navegar el listado, el alta/edición y el ciclo abrir/cerrar. Las
 * respuestas llegan cuando se monte el panel del socix.
 *
 * Está en el grupo "survey" para poder cargarla en aislamiento sobre la BBDD
 * de trabajo sin purgar el resto:
 *
 *     ddev exec "php bin/console doctrine:fixtures:load --append --group=survey"
 */
class SurveyFixtures extends Fixture implements FixtureGroupInterface
{
    public static function getGroups(): array
    {
        return ['survey'];
    }

    public function load(ObjectManager $manager): void
    {
        // --- Borrador: editable, todavía no admite respuestas. ---
        $draft = (new Survey())
            ->setTitle('Cesta de verano (agosto)')
            ->setDescription('Queremos saber si merece la pena mantener el reparto en agosto.')
            ->setStatus(Survey::STATUS_DRAFT);
        $this->addQuestion($draft, '¿Recogerías cesta en agosto?', Question::TYPE_SINGLE, 0, true, ['Sí', 'No', 'Depende de las fechas']);
        $this->addQuestion($draft, '¿Algún comentario sobre el reparto de verano?', Question::TYPE_TEXT, 1, false);
        $manager->persist($draft);

        // --- Abierta: todos los tipos de pregunta, ya admite respuestas. ---
        $open = (new Survey())
            ->setTitle('Satisfacción de la temporada 2026')
            ->setDescription('Cuéntanos qué tal ha ido el año para seguir mejorando.')
            ->setStatus(Survey::STATUS_OPEN)
            ->setClosesAt(new \DateTime('+2 weeks'));
        $this->addQuestion($open, 'Del 1 al 5, ¿cómo valoras la calidad de las verduras?', Question::TYPE_SCALE, 0, true);
        $this->addQuestion($open, '¿Qué día prefieres para el reparto?', Question::TYPE_SINGLE, 1, true, ['Lunes', 'Miércoles', 'Viernes']);
        $this->addQuestion($open, '¿Qué productos te gustaría que añadiéramos?', Question::TYPE_MULTIPLE, 2, false, ['Pan', 'Aceite', 'Queso', 'Miel']);
        $this->addQuestion($open, 'Sugerencias para mejorar', Question::TYPE_TEXT, 3, false);
        $manager->persist($open);

        // --- Cerrada: ya no admite respuestas; solo quedan resultados. ---
        $closed = (new Survey())
            ->setTitle('Asamblea de primavera')
            ->setDescription('Organización de la asamblea anual.')
            ->setStatus(Survey::STATUS_CLOSED);
        $this->addQuestion($closed, '¿Asistirás a la asamblea?', Question::TYPE_SINGLE, 0, true, ['Sí', 'No']);
        $this->addQuestion($closed, '¿Cómo valoras la organización del año pasado?', Question::TYPE_SCALE, 1, true);
        $manager->persist($closed);

        $manager->flush();
    }

    /**
     * Añade una pregunta a la encuesta y, si el tipo usa opciones, las crea con
     * su posición. Centraliza el armado para no repetir el cableo en cada alta.
     *
     * @param Survey   $survey   Encuesta a la que se añade la pregunta.
     * @param string   $text     Enunciado de la pregunta.
     * @param string   $type     Uno de los Question::TYPE_*.
     * @param int      $position Orden dentro de la encuesta (0-based).
     * @param bool     $required ¿Obligatoria al responder?
     * @param string[] $options  Etiquetas de las opciones (solo single/multiple).
     */
    private function addQuestion(Survey $survey, string $text, string $type, int $position, bool $required, array $options = []): void
    {
        $question = (new Question())
            ->setText($text)
            ->setType($type)
            ->setPosition($position)
            ->setRequired($required);

        foreach (array_values($options) as $i => $label) {
            $question->addOption(
                (new QuestionOption())->setLabel($label)->setPosition($i)
            );
        }

        $survey->addQuestion($question);
    }
}
