<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * El presupuesto de un año: lo que se espera ingresar y gastar, mes a mes y partida a
 * partida ({@see BudgetLine}).
 *
 * Hay varios por año a propósito. El que aprueba la asamblea es uno, pero a mitad de
 * año se rehace con lo que ya ha pasado y una previsión más realista —en 2026 ocurrió
 * en julio, al descubrir un desfase de 6.029,92 € en los ingresos—. Guardar sólo el
 * último borraría la referencia contra la que la asociación se comprometió, que es
 * justo la que hay que rendir en la asamblea siguiente.
 *
 * @ORM\Table(name="budget", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_budget_year_name", columns={"budget_year", "name"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\BudgetRepository")
 */
#[UniqueEntity(fields: ['year', 'name'], message: 'Ese año ya tiene un presupuesto con ese nombre.', errorPath: 'name')]
class Budget
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(name="budget_year", type="smallint")
     */
    #[Assert\NotNull]
    #[Assert\Range(min: 2000, max: 2100)]
    private ?int $year = null;

    /**
     * Cómo distinguirlo de los demás del mismo año: «Aprobado en asamblea»,
     * «Revisión de julio», «Escenario sin temporero»…
     * @ORM\Column(type="string", length=100)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name = '';

    /**
     * Si es el que aprobó la asamblea. Los demás son escenarios de trabajo. Cuando hay
     * varios aprobados del mismo año (una revisión llevada a asamblea extraordinaria),
     * vale el de {@see getApprovedAt} más reciente.
     * @ORM\Column(name="is_approved", type="boolean")
     */
    private bool $approved = false;

    /**
     * @ORM\Column(name="approved_at", type="date", nullable=true)
     */
    private ?\DateTimeInterface $approvedAt = null;

    /**
     * El porqué de este presupuesto: qué supuestos se tomaron, qué cambió respecto al
     * anterior. Es lo que en 2026 vivía en un documento suelto llamado «Explicación
     * desfase presupuesto 2026».
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $notes = null;

    /**
     * Saldo de tesorería con el que arranca el año, sumando todas las cuentas. Es el
     * punto de partida del acumulado mes a mes: sin él, el flujo de caja dice cuánto
     * se gana o se pierde, pero no si queda dinero.
     * @ORM\Column(name="opening_cash", type="decimal", precision=10, scale=2)
     */
    #[Assert\NotNull]
    private string $openingCash = '0';

    /**
     * @ORM\OneToMany(targetEntity="BudgetLine", mappedBy="budget", cascade={"persist", "remove"}, orphanRemoval=true)
     *
     * @var Collection<int, BudgetLine>
     */
    private Collection $lines;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created_at", type="datetime")
     */
    private ?\DateTimeInterface $createdAt = null;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): self
    {
        $this->year = $year;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function isApproved(): bool
    {
        return $this->approved;
    }

    public function setApproved(bool $approved): self
    {
        $this->approved = $approved;

        return $this;
    }

    public function getApprovedAt(): ?\DateTimeInterface
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeInterface $approvedAt): self
    {
        $this->approvedAt = $approvedAt;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    public function getOpeningCash(): string
    {
        return $this->openingCash;
    }

    public function setOpeningCash(string $openingCash): self
    {
        $this->openingCash = $openingCash;

        return $this;
    }

    /** @return Collection<int, BudgetLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(BudgetLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setBudget($this);
        }

        return $this;
    }

    public function removeLine(BudgetLine $line): self
    {
        $this->lines->removeElement($line);

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function __toString(): string
    {
        return $this->year.' · '.$this->name;
    }
}
