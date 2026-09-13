<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Un periodo de validez de una {@see Obligation}: desde cuándo y —lo que
 * importa— HASTA cuándo.
 *
 * Renovar no es editar la fecha: es añadir una de estas. El registro queda con
 * la historia completa (cada cuánto se renueva de verdad, si alguna vez se dejó
 * pasar) y el aviso del siguiente vencimiento se recalcula solo, sin que nadie
 * tenga que acordarse de mover nada.
 *
 * `endsOn` es obligatorio a propósito, aunque `startsOn` no lo sea: de un
 * convenio viejo muchas veces no se sabe cuándo empezó, pero si no se sabe
 * cuándo acaba no hay nada que vigilar y la fila no aporta.
 *
 * @ORM\Table(
 *     name="obligation_term",
 *     indexes={
 *         @ORM\Index(name="IDX_obligation_term_ends", columns={"ends_on"}),
 *         @ORM\Index(name="IDX_obligation_term_obligation", columns={"obligation_id", "ends_on"})
 *     }
 * )
 * @ORM\Entity
 */
class ObligationTerm
{
    /**
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="Obligation", inversedBy="terms")
     * @ORM\JoinColumn(name="obligation_id", nullable=false, onDelete="CASCADE")
     */
    private ?Obligation $obligation = null;

    /**
     * @ORM\Column(name="starts_on", type="date_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $startsOn = null;

    /**
     * @ORM\Column(name="ends_on", type="date_immutable")
     */
    private \DateTimeImmutable $endsOn;

    /**
     * Enlace al documento sellado de ESTE periodo (el convenio firmado, la
     * póliza de este año). El de la obligación apunta a la carpeta; éste, al
     * papel concreto que prueba que en estas fechas estaba en regla.
     *
     * @ORM\Column(name="document_url", type="string", length=500, nullable=true)
     */
    private ?string $documentUrl = null;

    /**
     * @ORM\Column(name="notes", type="string", length=255, nullable=true)
     */
    private ?string $notes = null;

    /**
     * @ORM\Column(name="created_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->endsOn = new \DateTimeImmutable('today');
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Un periodo no puede acabar antes de empezar.
     *
     * Se valida en la entidad y no en el formulario porque los periodos también
     * se crean desde el alta de la obligación, y una regla escrita en un solo
     * formulario deja el otro camino abierto.
     *
     * @param ExecutionContextInterface $context Contexto de validación.
     */
    #[Assert\Callback]
    public function validateDateOrder(ExecutionContextInterface $context): void
    {
        if ($this->startsOn !== null && $this->startsOn > $this->endsOn) {
            $context->buildViolation('La fecha de caducidad es anterior a la de inicio: revisa cuál es cuál.')
                ->atPath('endsOn')
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getObligation(): ?Obligation
    {
        return $this->obligation;
    }

    /**
     * @param Obligation|null $obligation Obligación a la que pertenece el periodo.
     */
    public function setObligation(?Obligation $obligation): self
    {
        $this->obligation = $obligation;

        return $this;
    }

    public function getStartsOn(): ?\DateTimeImmutable
    {
        return $this->startsOn;
    }

    /**
     * @param \DateTimeImmutable|null $startsOn Inicio de la validez, si se conoce.
     */
    public function setStartsOn(?\DateTimeImmutable $startsOn): self
    {
        $this->startsOn = $startsOn;

        return $this;
    }

    public function getEndsOn(): \DateTimeImmutable
    {
        return $this->endsOn;
    }

    /**
     * @param \DateTimeImmutable $endsOn Último día de validez.
     */
    public function setEndsOn(\DateTimeImmutable $endsOn): self
    {
        $this->endsOn = $endsOn;

        return $this;
    }

    public function getDocumentUrl(): ?string
    {
        return $this->documentUrl;
    }

    /**
     * @param string|null $documentUrl Enlace al documento sellado de este periodo.
     */
    public function setDocumentUrl(?string $documentUrl): self
    {
        $this->documentUrl = $documentUrl;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /**
     * @param string|null $notes Apunte breve sobre este periodo.
     */
    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
