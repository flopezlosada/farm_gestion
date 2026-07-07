<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Línea de un {@see ConsumerGroupOrder}: la cantidad que una socia pide de un
 * {@see ConsumerGroupRoundItem} concreto (producto + precio de esa ronda). Una sola
 * línea por (pedido, item de ronda).
 *
 * @ORM\Table(name="consumer_group_order_line", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_cg_order_line_order_item", columns={"order_id", "round_item_id"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\ConsumerGroupOrderLineRepository")
 */
class ConsumerGroupOrderLine
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="ConsumerGroupOrder", inversedBy="lines")
     * @ORM\JoinColumn(name="order_id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?ConsumerGroupOrder $order = null;

    /**
     * @ORM\ManyToOne(targetEntity="ConsumerGroupRoundItem")
     * @ORM\JoinColumn(name="round_item_id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?ConsumerGroupRoundItem $roundItem = null;

    /**
     * Cantidad pedida en la unidad del producto. Decimal como string (puede ser
     * fraccionaria: 1.5 kg). Cero equivale a no pedir ese producto.
     * @ORM\Column(type="decimal", precision=8, scale=2)
     */
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private string $quantity = '0';

    public function __construct(?ConsumerGroupOrder $order = null, ?ConsumerGroupRoundItem $roundItem = null, string $quantity = '0')
    {
        $this->order = $order;
        $this->roundItem = $roundItem;
        $this->quantity = $quantity;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): ?ConsumerGroupOrder
    {
        return $this->order;
    }

    public function setOrder(?ConsumerGroupOrder $order): self
    {
        $this->order = $order;
        return $this;
    }

    public function getRoundItem(): ?ConsumerGroupRoundItem
    {
        return $this->roundItem;
    }

    public function setRoundItem(?ConsumerGroupRoundItem $roundItem): self
    {
        $this->roundItem = $roundItem;
        return $this;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): self
    {
        $this->quantity = $quantity;
        return $this;
    }

    /**
     * Subtotal de la línea (cantidad × precio de la ronda), INFORMATIVO: la app no
     * cobra en v1. Float redondeado a 2 decimales (convención del proyecto); no se
     * usa bcmath para no exigir ext-bcmath en el hosting.
     */
    public function getSubtotal(): float
    {
        $price = (float) ($this->roundItem?->getPrice() ?? '0');
        return round((float) $this->quantity * $price, 2);
    }

    /**
     * Producto del catálogo detrás de esta línea (vía el RoundItem), para pintar.
     */
    public function getProduct(): ?ConsumerGroupProduct
    {
        return $this->roundItem?->getProduct();
    }
}
