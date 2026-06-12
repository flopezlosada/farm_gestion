<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Encuesta interna dirigida a lxs socixs. El equipo (ROLE_GESTION_ENCUESTAS)
 * la crea con varias {@see Question}, la abre, lxs socixs responden desde su
 * panel y luego se consultan los resultados de forma agregada.
 *
 * Anonimato "de confianza" (nivel A): las respuestas ({@see SurveyAnswer}) NO
 * llevan referencia al socix. El control de "quién ya respondió" vive aparte,
 * en {@see SurveyParticipation}, que no guarda el contenido de la respuesta.
 *
 * @ORM\Table(name="survey")
 * @ORM\Entity(repositoryClass="App\Repository\SurveyRepository")
 */
class Survey
{
    /** Borrador: editable, todavía no admite respuestas. */
    public const STATUS_DRAFT = 'draft';
    /** Abierta: lxs socixs pueden responder. Ya no se edita la estructura. */
    public const STATUS_OPEN = 'open';
    /** Cerrada: no admite más respuestas; solo se consultan resultados. */
    public const STATUS_CLOSED = 'closed';

    /**
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(name="title", type="string", length=200)
     */
    private string $title = '';

    /**
     * @ORM\Column(name="description", type="text", nullable=true)
     */
    private ?string $description = null;

    /**
     * @ORM\Column(name="status", type="string", length=20)
     */
    private string $status = self::STATUS_DRAFT;

    /**
     * @ORM\Column(name="created_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * Cierre opcional anunciado. NO cierra la encuesta por sí solo (el cambio
     * de estado es explícito); sirve para informar a lxs socixs de la fecha
     * límite. NULL = sin fecha anunciada.
     *
     * @ORM\Column(name="closes_at", type="datetime", nullable=true)
     */
    private ?\DateTimeInterface $closesAt = null;

    /**
     * @var Collection<int, Question>
     * @ORM\OneToMany(targetEntity="App\Entity\Question", mappedBy="survey", cascade={"persist", "remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"position" = "ASC"})
     */
    private Collection $questions;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->questions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * ¿Admite respuestas ahora mismo? Sólo en estado abierto.
     */
    public function isOpen(): bool
    {
        return self::STATUS_OPEN === $this->status;
    }

    /**
     * ¿Se puede editar su estructura (preguntas/opciones)? Sólo en borrador:
     * tocar las preguntas con respuestas ya recogidas las invalidaría.
     */
    public function isEditable(): bool
    {
        return self::STATUS_DRAFT === $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getClosesAt(): ?\DateTimeInterface
    {
        return $this->closesAt;
    }

    public function setClosesAt(?\DateTimeInterface $closesAt): self
    {
        $this->closesAt = $closesAt;

        return $this;
    }

    /**
     * @return Collection<int, Question>
     */
    public function getQuestions(): Collection
    {
        return $this->questions;
    }

    public function addQuestion(Question $question): self
    {
        if (!$this->questions->contains($question)) {
            $this->questions->add($question);
            $question->setSurvey($this);
        }

        return $this;
    }

    public function removeQuestion(Question $question): self
    {
        if ($this->questions->removeElement($question)) {
            if ($question->getSurvey() === $this) {
                $question->setSurvey(null);
            }
        }

        return $this;
    }
}
