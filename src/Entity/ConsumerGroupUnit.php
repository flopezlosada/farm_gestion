<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Unidad de venta de un producto del grupo de consumo (kg, L, docena, garrafa
 * de 5 L…). GLOBAL, no por productor: la comisión quiere poder comparar entre
 * productos distintos ("cuántos kilos se compraron en 2020"), y eso sólo
 * funciona si "kg" es siempre la misma fila, no un texto libre repetido —y a
 * veces mal escrito— en cada producto ({@see \App\Entity\ConsumerGroupProduct::$unit}
 * antes de esto). Mismo patrón que {@see ConsumerGroupCategory}.
 *
 * `name` es UNIQUE a nivel de columna; la collation utf8mb4 de la app es
 * case-insensitive, así que "Kg" choca con "kg" sin lógica aparte.
 *
 * @ORM\Table(name="consumer_group_unit")
 * @ORM\Entity(repositoryClass="App\Repository\ConsumerGroupUnitRepository")
 */
#[UniqueEntity(fields: ['name'], message: 'Ya existe una unidad con ese nombre.')]
class ConsumerGroupUnit
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=60, unique=true)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 60)]
    private string $name = '';

    /**
     * @ORM\Column(type="smallint")
     */
    private int $sortOrder = 0;

    /**
     * Retirada del catálogo: no se ofrece en productos nuevos, pero los que ya
     * la usan la conservan (FK RESTRICT: no se puede borrar mientras esté en uso).
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
        // Espacios sueltos ("kg " vs "kg") no chocan con el UNIQUE de la
        // columna al ser cadenas distintas, y crearían duplicados invisibles.
        $this->name = trim(preg_replace('/\s+/', ' ', $name));
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
