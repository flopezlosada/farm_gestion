<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Estancia de un {@see Helper} en el albergue: el intervalo [llegada, salida)
 * que ocupa una cama. Es la única pieza que consume aforo.
 *
 * Convención de intervalo medio-abierto, como un hotel: la cama está ocupada
 * desde el día de llegada (incluido) hasta el de salida (EXCLUIDO) — el día que
 * se va, la cama queda libre para otra persona. Toda la aritmética de ocupación
 * vive en {@see \App\Service\Hosting\OccupancyChecker}.
 *
 * Estados persistidos: SOLICITADA / CONFIRMADA / CANCELADA. "En curso" y
 * "finalizada" NO se guardan porque son derivables de las fechas y de hoy
 * (no almacenamos estado que se puede calcular). Sólo una estancia CONFIRMADA
 * ocupa aforo de verdad ({@see Stay::occupiesCapacity()}).
 *
 * @ORM\Table(name="stay")
 * @ORM\Entity(repositoryClass="App\Repository\StayRepository")
 */
class Stay
{
    /** Solicitada pero aún sin confirmar: no compromete cama. */
    public const STATUS_REQUESTED = 'requested';
    /** Confirmada: ocupa aforo. */
    public const STATUS_CONFIRMED = 'confirmed';
    /** Cancelada: no ocupa aforo. */
    public const STATUS_CANCELLED = 'cancelled';

    /** Estados válidos del ciclo de vida de la estancia. */
    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_CONFIRMED,
        self::STATUS_CANCELLED,
    ];

    /**
     * @var int|null
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var Helper|null
     * @ORM\ManyToOne(targetEntity="Helper", inversedBy="stays")
     * @ORM\JoinColumn(name="helper_id", referencedColumnName="id", nullable=false)
     */
    #[Assert\NotNull]
    private $helper;

    /**
     * @var \DateTimeImmutable|null
     * @ORM\Column(name="arrival_date", type="date_immutable")
     */
    #[Assert\NotNull]
    private $arrivalDate;

    /**
     * Fecha de salida ESTIMADA y editable. Obligatoria a propósito: mucha gente
     * llega sin salida cerrada, pero el aforo y el calendario necesitan siempre
     * un horizonte. Lo tentativo se marca con {@see Stay::$departureConfirmed}.
     *
     * @var \DateTimeImmutable|null
     * @ORM\Column(name="departure_date", type="date_immutable")
     */
    #[Assert\NotNull]
    #[Assert\GreaterThan(propertyPath: "arrivalDate", message: "La salida debe ser posterior a la llegada.")]
    private $departureDate;

    /**
     * ¿La fecha de salida está confirmada? Por defecto false (estimada): el
     * calendario la pinta distinta (el "¿?" del Excel actual) hasta confirmarla.
     *
     * @var bool
     * @ORM\Column(name="departure_confirmed", type="boolean", options={"default": false})
     */
    private $departureConfirmed = false;

    /**
     * @var string
     * @ORM\Column(name="status", type="string", length=20)
     */
    #[Assert\Choice(choices: Stay::STATUSES)]
    private $status = self::STATUS_REQUESTED;

    /**
     * Notas / contenidos de la estancia (en qué trabaja, formación, etc.).
     *
     * @var string|null
     * @ORM\Column(name="notes", type="text", nullable=true)
     */
    private $notes;

    /**
     * @var \DateTime
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created", type="datetime")
     */
    private $created;

    /**
     * @var \DateTime
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(name="updated", type="datetime")
     */
    private $updated;

    /**
     * Datos de prácticas, si esta estancia es de tipo prácticas; null en otro
     * caso. Cascade remove: borrar la estancia borra sus datos de prácticas.
     *
     * @var InternshipDetail|null
     * @ORM\OneToOne(targetEntity="InternshipDetail", mappedBy="stay", cascade={"remove"})
     */
    private $internshipDetail;

    /**
     * ¿Esta estancia ocupa una cama? Sólo las confirmadas: una solicitud sin
     * confirmar o una cancelación no comprometen aforo.
     *
     * @return bool
     */
    public function occupiesCapacity(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return Helper|null
     */
    public function getHelper(): ?Helper
    {
        return $this->helper;
    }

    /**
     * @param Helper|null $helper
     * @return self
     */
    public function setHelper(?Helper $helper): self
    {
        $this->helper = $helper;

        return $this;
    }

    /**
     * @return \DateTimeImmutable|null
     */
    public function getArrivalDate(): ?\DateTimeImmutable
    {
        return $this->arrivalDate;
    }

    /**
     * @param \DateTimeImmutable $arrivalDate
     * @return self
     */
    public function setArrivalDate(\DateTimeImmutable $arrivalDate): self
    {
        $this->arrivalDate = $arrivalDate;

        return $this;
    }

    /**
     * @return \DateTimeImmutable|null
     */
    public function getDepartureDate(): ?\DateTimeImmutable
    {
        return $this->departureDate;
    }

    /**
     * @param \DateTimeImmutable $departureDate
     * @return self
     */
    public function setDepartureDate(\DateTimeImmutable $departureDate): self
    {
        $this->departureDate = $departureDate;

        return $this;
    }

    /**
     * @return bool
     */
    public function isDepartureConfirmed(): bool
    {
        return $this->departureConfirmed;
    }

    /**
     * @param bool $departureConfirmed
     * @return self
     */
    public function setDepartureConfirmed(bool $departureConfirmed): self
    {
        $this->departureConfirmed = $departureConfirmed;

        return $this;
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @param string $status
     * @return self
     */
    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /**
     * @param string|null $notes
     * @return self
     */
    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    /**
     * @return \DateTime|null
     */
    public function getCreated(): ?\DateTime
    {
        return $this->created;
    }

    /**
     * @return \DateTime|null
     */
    public function getUpdated(): ?\DateTime
    {
        return $this->updated;
    }

    /**
     * @return InternshipDetail|null
     */
    public function getInternshipDetail(): ?InternshipDetail
    {
        return $this->internshipDetail;
    }

    /**
     * @param InternshipDetail|null $internshipDetail
     * @return self
     */
    public function setInternshipDetail(?InternshipDetail $internshipDetail): self
    {
        $this->internshipDetail = $internshipDetail;

        return $this;
    }
}
