<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Registro de que un socix YA participó en una encuesta. Es la mitad "con
 * identidad" del modelo: sabe quién respondió, pero NO qué respondió (eso vive
 * suelto en {@see SurveyAnswer}, sin referencia al socix).
 *
 * El índice UNIQUE(survey, partner) es lo que impide el duplicado a nivel de
 * base de datos: un segundo intento de guardar revienta con violación de
 * unicidad, sin depender de comprobaciones en código que puedan colar en una
 * carrera. Es la garantía dura del "evita que la gente responda dos veces".
 *
 * @ORM\Table(
 *     name="survey_participation",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="uniq_survey_partner", columns={"survey_id", "partner_id"})}
 * )
 * @ORM\Entity(repositoryClass="App\Repository\SurveyParticipationRepository")
 */
class SurveyParticipation
{
    /**
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Survey")
     * @ORM\JoinColumn(name="survey_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private ?Survey $survey = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Partner")
     * @ORM\JoinColumn(name="partner_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private ?Partner $partner = null;

    /**
     * @ORM\Column(name="submitted_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $submittedAt;

    public function __construct()
    {
        $this->submittedAt = new \DateTimeImmutable();
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

    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    public function setPartner(?Partner $partner): self
    {
        $this->partner = $partner;

        return $this;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }
}
