<?php

namespace App\Tests\Entity;

use App\Entity\Question;
use App\Entity\QuestionOption;
use App\Entity\Survey;
use PHPUnit\Framework\TestCase;

/**
 * Las reglas de cuándo una encuesta admite respuestas y cuándo se puede abrir.
 *
 * Son las que deciden a la vez el panel, el enlace del correo y la portada, así
 * que un fallo aquí es una socia respondiendo fuera de plazo o una encuesta
 * abierta —y avisada a todo el mundo— que nadie puede enviar.
 */
class SurveyTest extends TestCase
{
    public function testSinPlazoAdmiteRespuestasMientrasEsteAbierta(): void
    {
        $survey = (new Survey())->setStatus(Survey::STATUS_OPEN);

        self::assertTrue($survey->acceptsResponses(new \DateTimeImmutable('2030-01-01')));
    }

    /**
     * El último día cuenta entero: `closes_at` se guarda a las 00:00 y la
     * socia que responde a las diez de la noche de ese día sigue dentro.
     */
    public function testElUltimoDiaSeAdmiteHastaLaNoche(): void
    {
        $survey = (new Survey())
            ->setStatus(Survey::STATUS_OPEN)
            ->setClosesAt(new \DateTime('2026-10-13 00:00'));

        self::assertTrue($survey->acceptsResponses(new \DateTimeImmutable('2026-10-13 22:00')));
        self::assertFalse($survey->acceptsResponses(new \DateTimeImmutable('2026-10-14 00:00:01')));
    }

    public function testNiBorradorNiCerradaAdmitenRespuestas(): void
    {
        $now = new \DateTimeImmutable('2026-10-01');

        self::assertFalse((new Survey())->setStatus(Survey::STATUS_DRAFT)->acceptsResponses($now));
        self::assertFalse((new Survey())->setStatus(Survey::STATUS_CLOSED)->acceptsResponses($now));
    }

    public function testArchivadaAbiertaYaNoAdmiteRespuestas(): void
    {
        $survey = (new Survey())->setStatus(Survey::STATUS_OPEN)->setArchived(true);

        self::assertFalse($survey->acceptsResponses(new \DateTimeImmutable('2026-10-01')));
    }

    public function testUnBorradorCompletoSePuedeAbrir(): void
    {
        $survey = $this->draftWith($this->choice(2));

        self::assertNull($survey->whyCannotOpen(new \DateTimeImmutable('2026-10-01')));
    }

    public function testNoSeAbreSinPreguntas(): void
    {
        self::assertNotNull((new Survey())->whyCannotOpen(new \DateTimeImmutable('2026-10-01')));
    }

    /**
     * Con una sola opción no hay nada que elegir; con cero, si es obligatoria,
     * nadie puede enviar la encuesta.
     */
    public function testNoSeAbreConUnaPreguntaDeOpcionesSinSuficientesOpciones(): void
    {
        $now = new \DateTimeImmutable('2026-10-01');

        self::assertStringContainsString('opciones', (string) $this->draftWith($this->choice(0))->whyCannotOpen($now));
        self::assertStringContainsString('opciones', (string) $this->draftWith($this->choice(1))->whyCannotOpen($now));
    }

    public function testLasDeEscalaYTextoNoNecesitanOpciones(): void
    {
        $scale = (new Question())->setText('¿Qué tal?')->setType(Question::TYPE_SCALE);
        $text = (new Question())->setText('¿Algo más?')->setType(Question::TYPE_TEXT);

        self::assertNull($this->draftWith($scale, $text)->whyCannotOpen(new \DateTimeImmutable('2026-10-01')));
    }

    public function testNoSeAbreConElPlazoYaVencido(): void
    {
        $survey = $this->draftWith($this->choice(2))->setClosesAt(new \DateTime('2026-09-30'));

        self::assertNotNull($survey->whyCannotOpen(new \DateTimeImmutable('2026-10-01')));
    }

    public function testUnaAbiertaNoSeVuelveAAbrir(): void
    {
        $survey = $this->draftWith($this->choice(2))->setStatus(Survey::STATUS_OPEN);

        self::assertNotNull($survey->whyCannotOpen(new \DateTimeImmutable('2026-10-01')));
    }

    /**
     * Un borrador con las preguntas dadas.
     */
    private function draftWith(Question ...$questions): Survey
    {
        $survey = (new Survey())->setTitle('Prueba')->setStatus(Survey::STATUS_DRAFT);
        foreach ($questions as $question) {
            $survey->addQuestion($question);
        }

        return $survey;
    }

    /**
     * Una pregunta de opción única con `$count` opciones.
     */
    private function choice(int $count): Question
    {
        $question = (new Question())->setText('¿Qué día?')->setType(Question::TYPE_SINGLE);
        for ($i = 0; $i < $count; ++$i) {
            $question->addOption((new QuestionOption())->setLabel('Opción ' . $i));
        }

        return $question;
    }
}
