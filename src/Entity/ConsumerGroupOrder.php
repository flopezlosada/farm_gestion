<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Pedido de UNA socia en una {@see ConsumerGroupRound}. Entidad PROPIA, NO parte de
 * la cesta: cualquier socia puede apuntarse, tenga cesta esa semana o no. NO es
 * vinculante hasta que la ronda pasa a CONFIRMED (se supera el mínimo del productor);
 * mientras la ronda está OPEN, la socia puede editar o vaciar sus líneas.
 *
 * Una sola fila por (ronda, socia): las cantidades por producto son las líneas
 * ({@see ConsumerGroupOrderLine}). Vaciar todas las líneas equivale a no pedir.
 *
 * @ORM\Table(name="consumer_group_order", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_cg_order_round_partner", columns={"round_id", "partner_id"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\ConsumerGroupOrderRepository")
 */
class ConsumerGroupOrder
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="ConsumerGroupRound", inversedBy="orders")
     * @ORM\JoinColumn(name="round_id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?ConsumerGroupRound $round = null;

    /**
     * @ORM\ManyToOne(targetEntity="Partner")
     * @ORM\JoinColumn(name="partner_id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?Partner $partner = null;

    /**
     * @ORM\OneToMany(targetEntity="ConsumerGroupOrderLine", mappedBy="order", cascade={"persist", "remove"}, orphanRemoval=true)
     * @var Collection<int, ConsumerGroupOrderLine>
     */
    private Collection $lines;

    /**
     * ¿La socia ha pagado su pedido? La app no cobra: es una marca manual que
     * lleva la comisión para el seguimiento del cobro (por transferencia).
     * @ORM\Column(type="boolean")
     */
    private bool $paid = false;

    /**
     * Cuándo se marcó como pagado.
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?\DateTime $paidAt = null;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $created = null;

    /**
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(type="datetime")
     */
    private ?\DateTime $updated = null;

    public function __construct(?ConsumerGroupRound $round = null, ?Partner $partner = null)
    {
        $this->round = $round;
        $this->partner = $partner;
        $this->lines = new ArrayCollection();
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

    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    public function setPartner(?Partner $partner): self
    {
        $this->partner = $partner;
        return $this;
    }

    /**
     * @return Collection<int, ConsumerGroupOrderLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(ConsumerGroupOrderLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setOrder($this);
        }
        return $this;
    }

    public function removeLine(ConsumerGroupOrderLine $line): self
    {
        if ($this->lines->removeElement($line)) {
            if ($line->getOrder() === $this) {
                $line->setOrder(null);
            }
        }
        return $this;
    }

    /**
     * ¿El pedido está vacío (sin líneas con cantidad)? Un pedido vacío equivale a
     * no haber pedido nada en esta ronda.
     */
    public function isEmpty(): bool
    {
        foreach ($this->lines as $line) {
            if ((float) $line->getQuantity() > 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Importe total del pedido de la socia (Σ subtotales de sus líneas), informativo.
     */
    public function getTotal(): float
    {
        $total = 0.0;
        foreach ($this->lines as $line) {
            $total += $line->getSubtotal();
        }
        return round($total, 2);
    }

    public function isPaid(): bool
    {
        return $this->paid;
    }

    public function setPaid(bool $paid): self
    {
        $this->paid = $paid;
        return $this;
    }

    public function getPaidAt(): ?\DateTime
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTime $paidAt): self
    {
        $this->paidAt = $paidAt;
        return $this;
    }

    public function getCreated(): ?\DateTime
    {
        return $this->created;
    }

    public function getUpdated(): ?\DateTime
    {
        return $this->updated;
    }
}
