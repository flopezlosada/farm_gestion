<?php
/**
 * Tipo de componente que puede contener una entrega: verdura, huevos, y en el
 * futuro fruta, legumbres, etc. La "cesta" (WeeklyBasket) es el contenedor de
 * lo que el socio recoge un día; sus contenidos se modelan como WeeklyBasketItem,
 * cada uno apuntando a un BasketComponent con su cantidad.
 *
 * Históricamente cesta=verdura y los huevos eran un caso especial colgado del
 * PartnerBasketShare (egg_amount/egg_period). Este catálogo los pone al mismo
 * nivel: huevos es un componente más. Añadir un componente nuevo = una fila más,
 * sin tocar estructura.
 *
 * Modelado en el rediseño del calendario de recogida (2026-05-29).
 */

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * App\Entity\BasketComponent
 *
 * @ORM\Table(name="basket_component", uniqueConstraints={@ORM\UniqueConstraint(name="UNIQ_basket_component_name", columns={"name"})})
 * @ORM\Entity(repositoryClass="App\Repository\BasketComponentRepository")
 */
class BasketComponent
{
    /** ID fijo del componente verdura en el catálogo. */
    public const ID_VEGETABLES = 1;
    /** ID fijo del componente huevos en el catálogo. */
    public const ID_EGGS = 2;

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name ?? null;
    }

    /**
     * @param string $name
     * @return self
     */
    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function __toString(): string
    {
        return $this->getName() ?? '';
    }
}
