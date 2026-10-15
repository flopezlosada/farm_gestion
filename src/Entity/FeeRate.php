<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * El precio base de un concepto de la cuota desde una fecha: «la cesta quincenal
 * cuesta 55 € al mes desde el 1 de enero de 2026».
 *
 * Un precio no se cambia: se añade otro con una fecha posterior y el anterior queda
 * como lo que valía hasta entonces. Así se puede saber lo que tocaba pagar en
 * cualquier mes. Por eso no tiene setters.
 *
 * Es la BASE, igual para todo el mundo. Lo que cada socix paga de más o de menos va
 * aparte, en sus ajustes.
 *
 * Uno por concepto y fecha: dos precios que empiezan el mismo día no dicen cuál vale.
 *
 * @ORM\Table(name="fee_rate", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_fee_rate_concept_from", columns={"concept", "valid_from"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\FeeRateRepository")
 */
class FeeRate
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=20, enumType=FeeConcept::class)
     */
    private FeeConcept $concept;

    /**
     * Euros al mes: por cesta en la verdura, por docena en los huevos.
     *
     * @ORM\Column(type="decimal", precision=8, scale=2)
     */
    private string $price;

    /**
     * @ORM\Column(name="valid_from", type="date_immutable")
     */
    private \DateTimeImmutable $validFrom;

    /**
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="created_by_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $createdBy;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created_at", type="datetime")
     */
    private ?\DateTimeInterface $createdAt = null;

    /**
     * @param string $price Euros, «55.00».
     *
     * @throws \InvalidArgumentException Si el precio no es mayor que cero.
     */
    public function __construct(FeeConcept $concept, string $price, \DateTimeInterface $validFrom, ?User $createdBy)
    {
        $cents = IssuedInvoice::toCents($price);
        if ($cents <= 0) {
            throw new \InvalidArgumentException('Un precio tiene que ser mayor que cero.');
        }
        $this->concept = $concept;
        $this->price = IssuedInvoice::fromCents($cents);
        $this->validFrom = \DateTimeImmutable::createFromInterface($validFrom)->setTime(0, 0);
        $this->createdBy = $createdBy;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConcept(): FeeConcept
    {
        return $this->concept;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function getValidFrom(): \DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
