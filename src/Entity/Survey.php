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
     * Archivada: se oculta del listado por defecto, pero NO se borra — conserva
     * sus preguntas y respuestas. Es ortogonal al ciclo de vida (una encuesta
     * cerrada con votos se archiva para no verla, sin perder el dato).
     *
     * @ORM\Column(name="archived", type="boolean")
     */
    private bool $archived = false;

    /**
     * Último día en que se admiten respuestas, incluido. NULL = sin plazo:
     * abierta hasta que alguien la cierre.
     *
     * NO CAMBIA EL ESTADO. Pasada la fecha la encuesta sigue `open` en la base y
     * es {@see acceptsResponses()} quien deja de admitir respuestas. Así no hace
     * falta ninguna tarea programada que la cierre a su hora, y ampliar el plazo
     * es cambiar una fecha, no reabrir nada. El estado `closed` queda para el
     * cierre manual, antes de tiempo.
     *
     * Se guarda como fecha a las 00:00 (el formulario sólo pide el día), así que
     * la comparación se hace contra el final de ese día.
     *
     * @ORM\Column(name="closes_at", type="datetime", nullable=true)
     */
    private ?\DateTimeInterface $closesAt = null;

    /**
     * Cuándo se avisó a la asociación de que está abierta. NULL = no se ha avisado.
     *
     * Es la memoria VISIBLE del aviso, la que lee el equipo en el listado. Que no
     * salga dos veces lo garantiza el registro de efectos de
     * {@see \App\Service\Survey\SurveyAnnouncer}, no esta columna.
     *
     * @ORM\Column(name="announced_at", type="datetime", nullable=true)
     */
    private ?\DateTimeInterface $announcedAt = null;

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

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): self
    {
        $this->archived = $archived;

        return $this;
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
     * Por qué no se puede abrir todavía, o null si está lista.
     *
     * Abrir avisa a toda la asociación y ya no se puede editar: una encuesta
     * que nadie puede enviar (una pregunta de opciones sin opciones) o que ya ha
     * vencido tiene que pararse aquí, no descubrirse con el aviso enviado.
     *
     * @param \DateTimeInterface $now el momento de referencia
     *
     * @return string|null el motivo, en palabras para el equipo
     */
    public function whyCannotOpen(\DateTimeInterface $now): ?string
    {
        if (!$this->isEditable()) {
            return 'Sólo se puede abrir una encuesta en borrador.';
        }

        if ($this->questions->isEmpty()) {
            return 'No se puede abrir una encuesta sin preguntas.';
        }

        foreach ($this->questions as $question) {
            if (!$question->isAnswerable()) {
                return sprintf('La pregunta «%s» necesita al menos %d opciones.', $question->getText(), Question::MIN_OPTIONS);
            }
        }

        if ($this->isPastDeadline($now)) {
            return 'La fecha de cierre ya ha pasado: cámbiala antes de abrirla.';
        }

        return null;
    }

    /**
     * ¿Admite respuestas en este momento? Abierta, sin archivar y sin haber
     * pasado su plazo.
     *
     * Es LA pregunta que se hacen el panel, el enlace del correo y la tarjeta de
     * la portada. {@see isOpen()} a secas no basta: una encuesta abierta con el
     * plazo vencido seguiría pidiendo respuestas que ya no deberían contar. Y
     * archivar es quitarla de en medio: si el equipo la archiva abierta, a la
     * socia tampoco debe seguir apareciéndole.
     *
     * La consulta equivalente es {@see \App\Repository\SurveyRepository::findAcceptingResponses()};
     * si cambia una, cambia la otra.
     *
     * @param \DateTimeInterface $now el momento de referencia
     *
     * @return bool true si una socia puede responderla ahora
     */
    public function acceptsResponses(\DateTimeInterface $now): bool
    {
        return $this->isOpen() && !$this->archived && !$this->isPastDeadline($now);
    }

    /**
     * ¿Ha pasado ya su último día? Sin plazo, nunca.
     *
     * @param \DateTimeInterface $now el momento de referencia
     *
     * @return bool true si `$now` es posterior al final del día de cierre
     */
    public function isPastDeadline(\DateTimeInterface $now): bool
    {
        if (null === $this->closesAt) {
            return false;
        }

        $lastMoment = \DateTimeImmutable::createFromInterface($this->closesAt)->setTime(23, 59, 59);

        return $now > $lastMoment;
    }

    public function getAnnouncedAt(): ?\DateTimeInterface
    {
        return $this->announcedAt;
    }

    public function setAnnouncedAt(?\DateTimeInterface $announcedAt): self
    {
        $this->announcedAt = $announcedAt;

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
