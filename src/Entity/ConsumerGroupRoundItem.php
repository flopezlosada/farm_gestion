<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Producto de una ronda concreta: enlaza un {@see ConsumerGroupProduct} del catálogo
 * del productor con una {@see ConsumerGroupRound}, fijando el PRECIO DE ESA RONDA
 * (el precio varía de ronda a ronda; el del catálogo es solo de referencia).
 *
 * Las socias apuntan cantidad contra este RoundItem ({@see ConsumerGroupOrderLine}),
 * no contra el producto del catálogo directamente. Una sola fila por (ronda, producto).
 *
 * @ORM\Table(name="consumer_group_round_item", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_cg_round_item_round_product", columns={"round_id", "product_id"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\ConsumerGroupRoundItemRepository")
 */
class ConsumerGroupRoundItem
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="ConsumerGroupRound", inversedBy="items")
     * @ORM\JoinColumn(name="round_id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?ConsumerGroupRound $round = null;

    /**
     * Producto del catálogo. RESTRICT: un producto usado en alguna ronda no se puede
     * borrar (protege el histórico y la analítica por producto); se desactiva.
     * @ORM\ManyToOne(targetEntity="ConsumerGroupProduct")
     * @ORM\JoinColumn(name="product_id", nullable=false, onDelete="RESTRICT")
     */
    #[Assert\NotNull]
    private ?ConsumerGroupProduct $product = null;

    /**
     * Precio por unidad PARA ESTA RONDA, en euros. Decimal como string.
     * @ORM\Column(type="decimal", precision=8, scale=2)
     */
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private string $price = '0';

    /**
     * Orden de presentación dentro de la ronda.
     * @ORM\Column(type="smallint")
     */
    private int $sortOrder = 0;

    public function __construct(?ConsumerGroupRound $round = null, ?ConsumerGroupProduct $product = null, string $price = '0')
    {
        $this->round = $round;
        $this->product = $product;
        $this->price = $price;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRound(): ?ConsumerGroupRound
    {
        return $this->round;
    }

    public function setRound(?ConsumerGroupRound $round): self
    {
        $this->round = $round;
        return $this;
    }

    public function getProduct(): ?ConsumerGroupProduct
    {
        return $this->product;
    }

    public function setProduct(?ConsumerGroupProduct $product): self
    {
        $this->product = $product;
        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;
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

    /**
     * Nombre del producto (del catálogo), para pintar sin navegar la relación.
     */
    public function getName(): string
    {
        return $this->product?->getName() ?? '';
    }

    /**
     * Unidad del producto (del catálogo).
     */
    public function getUnit(): string
    {
        return $this->product?->getUnit() ?? '';
    }
}
