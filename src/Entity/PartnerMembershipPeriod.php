<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Un período de pertenencia de un socio a la asociación. Un Partner puede
 * tener N PartnerMembershipPeriod a lo largo del tiempo: gente que se da
 * de alta, baja, vuelve a darse de alta. Sin esto los gráficos de
 * evolución histórica (cuántos socios activos había cada mes) no se
 * pueden reconstruir.
 *
 * Convivencia con `Partner.inscription_date` / `Partner.demote_date`:
 * se mantienen como denormalización de "primera alta" y "última baja";
 * el detalle por episodio vive aquí.
 *
 * @ORM\Table(name="partner_membership_period",
 *     indexes={
 *         @ORM\Index(name="idx_pmp_partner", columns={"partner_id"}),
 *         @ORM\Index(name="idx_pmp_dates", columns={"start_date","end_date"})
 *     })
 * @ORM\Entity(repositoryClass="App\Repository\PartnerMembershipPeriodRepository")
 */
class PartnerMembershipPeriod
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity="Partner", inversedBy="membership_periods")
     * @ORM\JoinColumn(name="partner_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private Partner $partner;

    /**
     * @ORM\Column(type="date")
     */
    #[Assert\NotNull]
    private \DateTimeInterface $start_date;

    /**
     * Fecha de fin del período. Null mientras esté activo.
     * @ORM\Column(type="date", nullable=true)
     */
    private ?\DateTimeInterface $end_date = null;

    /**
     * Motivo libre del cambio (ej. "se muda fuera", "vacaciones largas").
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $reason = null;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created", type="datetime")
     */
    private \DateTimeInterface $created;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPartner(): Partner
    {
        return $this->partner;
    }

    public function setPartner(Partner $partner): self
    {
        $this->partner = $partner;
        return $this;
    }

    public function getStartDate(): \DateTimeInterface
    {
        return $this->start_date;
    }

    public function setStartDate(\DateTimeInterface $start_date): self
    {
        $this->start_date = $start_date;
        return $this;
    }

    public function getEndDate(): ?\DateTimeInterface
    {
        return $this->end_date;
    }

    public function setEndDate(?\DateTimeInterface $end_date): self
    {
        $this->end_date = $end_date;
        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): self
    {
        $this->reason = $reason;
        return $this;
    }

    public function getCreated(): \DateTimeInterface
    {
        return $this->created;
    }

    public function isActive(): bool
    {
        return $this->end_date === null;
    }
}
