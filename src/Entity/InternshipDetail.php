<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Datos de PRÁCTICAS de una {@see Stay}: tutor, convenio y estado del informe.
 * Satélite 1-a-1 que SÓLO existe para estancias de prácticas; un wwoofer no
 * tiene este registro (cero columnas nulas en Stay = lo contrario de meterlo
 * todo en Stay con campos vacíos).
 *
 * Vive a nivel de ESTANCIA, no de persona: si alguien vuelve otro año en
 * prácticas, es otra estancia con otro tutor/convenio/informe.
 *
 * El documento del informe (PDF generado para la universidad) es trabajo de una
 * fase posterior; aquí se modela sólo el hueco (estado pendiente/entregado) para
 * que esté listo cuando toque.
 *
 * @ORM\Table(name="internship_detail")
 * @ORM\Entity(repositoryClass="App\Repository\InternshipDetailRepository")
 */
class InternshipDetail
{
    /** Informe aún no entregado a la universidad. */
    public const REPORT_PENDING = 'pending';
    /** Informe ya entregado. */
    public const REPORT_DELIVERED = 'delivered';

    /** Estados válidos del informe de prácticas. */
    public const REPORT_STATUSES = [
        self::REPORT_PENDING,
        self::REPORT_DELIVERED,
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
     * La estancia de prácticas a la que pertenecen estos datos. Lado dueño de la
     * relación 1-1 (la FK vive aquí, única).
     *
     * @var Stay|null
     * @ORM\OneToOne(targetEntity="Stay", inversedBy="internshipDetail")
     * @ORM\JoinColumn(name="stay_id", referencedColumnName="id", nullable=false, unique=true)
     */
    #[Assert\NotNull]
    private $stay;

    /**
     * @var string|null
     * @ORM\Column(name="tutor_name", type="string", length=255, nullable=true)
     */
    private $tutorName;

    /**
     * @var string|null
     * @ORM\Column(name="tutor_email", type="string", length=180, nullable=true)
     */
    #[Assert\Email]
    private $tutorEmail;

    /**
     * Referencia del convenio de prácticas (texto libre: nº de expediente, etc.).
     *
     * @var string|null
     * @ORM\Column(name="agreement_reference", type="string", length=255, nullable=true)
     */
    private $agreementReference;

    /**
     * @var string
     * @ORM\Column(name="report_status", type="string", length=20)
     */
    #[Assert\Choice(choices: InternshipDetail::REPORT_STATUSES)]
    private $reportStatus = self::REPORT_PENDING;

    /**
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
     * ¿El informe está entregado?
     *
     * @return bool
     */
    public function isReportDelivered(): bool
    {
        return $this->reportStatus === self::REPORT_DELIVERED;
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return Stay|null
     */
    public function getStay(): ?Stay
    {
        return $this->stay;
    }

    /**
     * @param Stay|null $stay
     * @return self
     */
    public function setStay(?Stay $stay): self
    {
        $this->stay = $stay;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getTutorName(): ?string
    {
        return $this->tutorName;
    }

    /**
     * @param string|null $tutorName
     * @return self
     */
    public function setTutorName(?string $tutorName): self
    {
        $this->tutorName = $tutorName;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getTutorEmail(): ?string
    {
        return $this->tutorEmail;
    }

    /**
     * @param string|null $tutorEmail
     * @return self
     */
    public function setTutorEmail(?string $tutorEmail): self
    {
        $this->tutorEmail = $tutorEmail;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getAgreementReference(): ?string
    {
        return $this->agreementReference;
    }

    /**
     * @param string|null $agreementReference
     * @return self
     */
    public function setAgreementReference(?string $agreementReference): self
    {
        $this->agreementReference = $agreementReference;

        return $this;
    }

    /**
     * @return string
     */
    public function getReportStatus(): string
    {
        return $this->reportStatus;
    }

    /**
     * @param string $reportStatus
     * @return self
     */
    public function setReportStatus(string $reportStatus): self
    {
        $this->reportStatus = $reportStatus;

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
}
