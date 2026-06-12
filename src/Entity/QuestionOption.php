<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Una opción predefinida de una {@see Question} de tipo single/multiple
 * (p. ej. "Lunes", "Martes"…). El sí/no es simplemente una pregunta single con
 * dos opciones, por eso no hay tipo booleano aparte.
 *
 * @ORM\Table(name="survey_question_option")
 * @ORM\Entity
 */
class QuestionOption
{
    /**
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Question", inversedBy="options")
     * @ORM\JoinColumn(name="question_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private ?Question $question = null;

    /**
     * @ORM\Column(name="label", type="string", length=255)
     */
    private string $label = '';

    /**
     * @ORM\Column(name="position", type="integer")
     */
    private int $position = 0;

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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
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
}
