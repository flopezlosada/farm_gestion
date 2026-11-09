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
 * }, indexes={
 *     @ORM\Index(name="IDX_CG_ORDER_REMITTANCE_LINE", columns={"remittance_line_id"})
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
     * Cómo se pagó, o null si está pendiente. Es la ÚNICA marca de pago: «pagado»
     * sin saber cómo no se puede representar. La transferencia y el metálico los
     * marca la comisión a mano; el recibo, la remesa del grupo de consumo al enviarse.
     *
     * @ORM\Column(name="paid_via", type="string", length=10, nullable=true, enumType=ConsumerGroupPaymentMethod::class)
     */
    private ?ConsumerGroupPaymentMethod $paidVia = null;

    /**
     * Cuándo se marcó como pagado.
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?\DateTime $paidAt = null;

    /**
     * El recibo de la remesa del grupo de consumo en el que va este pedido. Se pone al
     * preparar el borrador y es lo que dice, al enviarla, qué pedidos quedan cobrados:
     * recalcular el mes entonces marcaría como pagado un pedido confirmado después del
     * borrador, que no va en ningún recibo. Si se tira el borrador o se quita el
     * recibo, vuelve a null y el pedido entra en la siguiente.
     *
     * @ORM\ManyToOne(targetEntity="RemittanceLine")
     * @ORM\JoinColumn(name="remittance_line_id", nullable=true, onDelete="SET NULL")
     */
    private ?RemittanceLine $remittanceLine = null;

    /**
     * ¿Ha recogido la socia este pedido? Lo puede marcar ella misma desde su
     * panel o la comisión desde gestión: no hay un único punto de control físico
     * (el producto sale con la cesta, pero puede recogerlo otra persona de la
     * familia, o llegar tarde al nodo), así que es una marca manual, igual que
     * el pago.
     * @ORM\Column(type="boolean")
     */
    private bool $pickedUp = false;

    /**
     * Cuándo se marcó como recogido.
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?\DateTime $pickedUpAt = null;

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

    /**
     * Concepto con el que hacer la transferencia de este pedido.
     *
     * EXISTE PARA QUE LA COMISIÓN PUEDA CONCILIAR EL EXTRACTO. La app no cobra:
     * alguien mira los ingresos del banco y marca a mano quién ha pagado. Sin un
     * concepto acordado, en el extracto aparecen nombres de titulares de cuenta
     * que no siempre son los de la socia y ninguna pista de a qué pedido
     * corresponde el ingreso, así que la conciliación se hace preguntando.
     *
     * Lleva el número de pedido y el nombre, que es lo que hace falta para
     * casarlo: corto, porque el campo de concepto del banco recorta.
     */
    public function getPaymentReference(): string
    {
        return sprintf('GC%d %s', $this->round?->getId() ?? 0, (string) $this->partner);
    }

    /** Si consta pagado, de la forma que sea. */
    public function isPaid(): bool
    {
        return $this->paidVia !== null;
    }

    /** Cómo se pagó, o null si está pendiente. */
    public function getPaidVia(): ?ConsumerGroupPaymentMethod
    {
        return $this->paidVia;
    }

    /** Cuándo se marcó como pagado, o null si está pendiente. */
    public function getPaidAt(): ?\DateTime
    {
        return $this->paidAt;
    }

    /**
     * Dejarlo pagado de esa forma, y cuándo (ahora si no se dice).
     *
     * @param ConsumerGroupPaymentMethod $via cómo entró el dinero
     * @param \DateTime|null             $at  cuándo; null = ahora
     */
    public function markPaid(ConsumerGroupPaymentMethod $via, ?\DateTime $at = null): self
    {
        $this->paidVia = $via;
        $this->paidAt = $at ?? new \DateTime();

        return $this;
    }

    /** Volver a dejarlo pendiente de pago. */
    public function markUnpaid(): self
    {
        $this->paidVia = null;
        $this->paidAt = null;

        return $this;
    }

    /**
     * Si, sin estar pagado, se le va a cobrar en el recibo del mes: la socia paga por
     * recibo y tiene el mandato completo. Para la entrega cuenta como pagado, porque el
     * cobro está asegurado; la consulta de entregables lo repite en DQL
     * ({@see \App\Repository\ConsumerGroupOrderRepository}).
     */
    public function willBeChargedByReceipt(): bool
    {
        return !$this->isPaid() && $this->partner !== null && $this->partner->canBeChargedByReceipt();
    }

    /** El recibo de la remesa del grupo de consumo en el que va, si va en alguno. */
    public function getRemittanceLine(): ?RemittanceLine
    {
        return $this->remittanceLine;
    }

    /**
     * Meterlo en un recibo de la remesa del grupo de consumo, o sacarlo (null).
     *
     * @param RemittanceLine|null $line el recibo de la socia en el borrador
     */
    public function setRemittanceLine(?RemittanceLine $line): self
    {
        $this->remittanceLine = $line;

        return $this;
    }

    public function isPickedUp(): bool
    {
        return $this->pickedUp;
    }

    public function setPickedUp(bool $pickedUp): self
    {
        $this->pickedUp = $pickedUp;
        return $this;
    }

    public function getPickedUpAt(): ?\DateTime
    {
        return $this->pickedUpAt;
    }

    public function setPickedUpAt(?\DateTime $pickedUpAt): self
    {
        $this->pickedUpAt = $pickedUpAt;
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
