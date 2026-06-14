<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Una pregunta dentro de una {@see Survey}. El tipo ({@see Question::$type})
 * decide cómo se pinta el campo, cómo se guarda la respuesta en
 * {@see SurveyAnswer} y cómo se agrega en los resultados:
 *
 *   - TYPE_SINGLE   → radio; respuesta = una {@see QuestionOption} (option_id).
 *   - TYPE_MULTIPLE → checkboxes; varias respuestas, una fila por opción.
 *   - TYPE_SCALE    → escala 1-5; respuesta = entero (value_int).
 *   - TYPE_TEXT     → texto libre; respuesta = texto (value_text). NO agregable:
 *                     en resultados sólo se lista.
 *
 * @ORM\Table(name="survey_question")
 * @ORM\Entity
 */
class Question
{
    /** Opción única (radio). Usa {@see QuestionOption}. */
    public const TYPE_SINGLE = 'single';
    /** Opción múltiple (checkboxes). Usa {@see QuestionOption}. */
    public const TYPE_MULTIPLE = 'multiple';
    /** Escala 1-5 (Likert / estrellas). */
    public const TYPE_SCALE = 'scale';
    /** Texto libre. */
    public const TYPE_TEXT = 'text';

    /** Rango fijo de la escala. No configurable a propósito (YAGNI). */
    public const SCALE_MIN = 1;
    public const SCALE_MAX = 5;

    /**
     * Tipos que se responden eligiendo opciones predefinidas y que, por tanto,
     * necesitan {@see QuestionOption}.
     */
    public const TYPES_WITH_OPTIONS = [self::TYPE_SINGLE, self::TYPE_MULTIPLE];

    /**
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Survey", inversedBy="questions")
     * @ORM\JoinColumn(name="survey_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private ?Survey $survey = null;

    /**
     * @ORM\Column(name="text", type="string", length=500)
     */
    private string $text = '';

    /**
     * @ORM\Column(name="type", type="string", length=20)
     */
    private string $type = self::TYPE_SINGLE;

    /**
     * @ORM\Column(name="position", type="integer")
     */
    private int $position = 0;

    /**
     * @ORM\Column(name="required", type="boolean")
     */
    private bool $required = true;

    /**
     * @var Collection<int, QuestionOption>
     * @ORM\OneToMany(targetEntity="App\Entity\QuestionOption", mappedBy="question", cascade={"persist", "remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"position" = "ASC"})
     */
    private Collection $options;

    public function __construct()
    {
        $this->options = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSurvey(): ?Survey
    {
        return $this->survey;
    }

    public function setSurvey(?Survey $survey): self
    {
        $this->survey = $survey;

        return $this;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * ¿Este tipo de pregunta se responde con opciones predefinidas?
     */
    public function usesOptions(): bool
    {
        return in_array($this->type, self::TYPES_WITH_OPTIONS, true);
    }

    /**
     * ¿Admite los datos agregarse en gráficas/porcentajes? Todo menos el texto
     * libre.
     */
    public function isAggregatable(): bool
    {
        return self::TYPE_TEXT !== $this->type;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): self
    {
        $this->required = $required;

        return $this;
    }

    /**
     * @return Collection<int, QuestionOption>
     */
    public function getOptions(): Collection
    {
        return $this->options;
    }

    public function addOption(QuestionOption $option): self
    {
        if (!$this->options->contains($option)) {
            $this->options->add($option);
            $option->setQuestion($this);
        }

        return $this;
    }

    public function removeOption(QuestionOption $option): self
    {
        if ($this->options->removeElement($option)) {
            if ($option->getQuestion() === $this) {
                $option->setQuestion(null);
            }
        }

        return $this;
    }
}
