<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Categoría de producto del grupo de consumo (verdura, fruta, aceite, legumbre…).
 * Entidad propia —no un enum— para poder crecer sin fricción: la comisión añade
 * categorías según hagan falta. Global (compartida por todos los productores).
 *
 * @ORM\Table(name="consumer_group_category")
 * @ORM\Entity(repositoryClass="App\Repository\ConsumerGroupCategoryRepository")
 */
class ConsumerGroupCategory
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=120, unique=true)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    /**
     * @ORM\Column(type="smallint")
     */
    private int $sortOrder = 0;

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $active = true;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
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

    public function __toString(): string
    {
        return $this->name;
    }
}
