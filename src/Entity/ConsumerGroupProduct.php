<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Producto del CATÁLOGO de un {@see Producer}: persistente y reutilizable entre
 * rondas. Lleva un precio de REFERENCIA (orientativo) que precarga el precio de la
 * ronda al añadirlo, pero el precio efectivo de cada ronda vive en
 * {@see ConsumerGroupRoundItem} porque varía de una ronda a otra.
 *
 * @ORM\Table(name="consumer_group_product")
 * @ORM\Entity(repositoryClass="App\Repository\ConsumerGroupProductRepository")
 */
class ConsumerGroupProduct
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="Producer", inversedBy="products")
     * @ORM\JoinColumn(name="producer_id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?Producer $producer = null;

    /**
     * Categoría del producto (verdura, fruta…). Opcional. SET NULL: borrar una
     * categoría no borra sus productos, solo los deja sin categorizar.
     * @ORM\ManyToOne(targetEntity="ConsumerGroupCategory")
     * @ORM\JoinColumn(name="category_id", nullable=true, onDelete="SET NULL")
     */
    private ?ConsumerGroupCategory $category = null;

    /**
     * @ORM\Column(type="string", length=180)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $name = '';

    /**
     * Ruta de la imagen del producto (opcional). El widget de subida se cablea
     * aparte; el campo queda listo.
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private ?string $image = null;

    /**
     * Unidad de venta (p. ej. "kg", "L", "docena", "caja", "ud"). Texto libre: los
     * productores no comparten catálogo de unidades.
     * @ORM\Column(type="string", length=30)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 30)]
    private string $unit = '';

    /**
     * Descripción del producto (variedad, formato, origen…), opcional.
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $description = null;

    /**
     * Precio de referencia por unidad, en euros (orientativo; el precio real lo
     * fija cada ronda). Decimal como string para no arrastrar coma flotante.
     * @ORM\Column(type="decimal", precision=8, scale=2, nullable=true)
     */
    #[Assert\PositiveOrZero]
    private ?string $referencePrice = null;

    /**
     * Producto retirado del catálogo: no se ofrece en rondas nuevas, pero se conserva
     * (las rondas pasadas que lo usaron lo referencian por RoundItem).
     * @ORM\Column(type="boolean")
     */
    private bool $active = true;

    /**
     * Orden de presentación dentro del catálogo del productor.
     * @ORM\Column(type="smallint")
     */
    private int $sortOrder = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProducer(): ?Producer
    {
        return $this->producer;
    }

    public function setProducer(?Producer $producer): self
    {
        $this->producer = $producer;
        return $this;
    }

    public function getCategory(): ?ConsumerGroupCategory
    {
        return $this->category;
    }

    public function setCategory(?ConsumerGroupCategory $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): self
    {
        $this->image = $image;
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

    public function getUnit(): string
    {
        return $this->unit;
    }

    public function setUnit(string $unit): self
    {
        $this->unit = $unit;
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

    public function getReferencePrice(): ?string
    {
        return $this->referencePrice;
    }

    public function setReferencePrice(?string $referencePrice): self
    {
        $this->referencePrice = $referencePrice;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
