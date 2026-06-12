<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Una respuesta a una {@see Question} concreta.
 *
 * CLAVE DEL ANONIMATO (nivel A): esta entidad NO tiene referencia al socix que
 * la dio. El "quién ha respondido" se controla aparte, en
 * {@see SurveyParticipation}. No hay foreign key que una una respuesta con su
 * autor — el único cruce posible sería adivinar por timestamp, y por eso ni
 * siquiera guardamos aquí la fecha.
 *
 * Según el tipo de la pregunta se rellena un campo u otro:
 *   - single   → $option (la elegida).
 *   - multiple → una fila por cada opción marcada, cada una con su $option.
 *   - scale    → $valueInt (1-5).
 *   - text     → $valueText.
 *
 * @ORM\Table(name="survey_answer")
 * @ORM\Entity(repositoryClass="App\Repository\SurveyAnswerRepository")
 */
class SurveyAnswer
{
    /**
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Question")
     * @ORM\JoinColumn(name="question_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private ?Question $question = null;

    /**
     * Opción elegida (single/multiple). NULL para scale/text.
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\QuestionOption")
     * @ORM\JoinColumn(name="option_id", referencedColumnName="id", nullable=true, onDelete="CASCADE")
     */
    private ?QuestionOption $option = null;

    /**
     * Valor de escala (scale). NULL para el resto.
     *
     * @ORM\Column(name="value_int", type="integer", nullable=true)
     */
    private ?int $valueInt = null;

    /**
     * Texto libre (text). NULL para el resto.
     *
     * @ORM\Column(name="value_text", type="text", nullable=true)
     */
    private ?string $valueText = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuestion(): ?Question
    {
        return $this->question;
    }

    public function setQuestion(?Question $question): self
    {
        $this->question = $question;

        return $this;
    }

    public function getOption(): ?QuestionOption
    {
        return $this->option;
    }

    public function setOption(?QuestionOption $option): self
    {
        $this->option = $option;

        return $this;
    }

    public function getValueInt(): ?int
    {
        return $this->valueInt;
    }

    public function setValueInt(?int $valueInt): self
    {
        $this->valueInt = $valueInt;

        return $this;
    }

    public function getValueText(): ?string
    {
        return $this->valueText;
    }

    public function setValueText(?string $valueText): self
    {
        $this->valueText = $valueText;

        return $this;
    }
}
