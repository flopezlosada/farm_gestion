<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Ronda de pedido del GRUPO DE CONSUMO: un pedido colectivo de productos de un
 * tercero (fruta de temporada, aceite…) que la asociación NO produce y que se
 * reparte junto con la cesta semanal. Lo abre la comisión del grupo de consumo,
 * las socias se apuntan mientras está abierto, y en la fecha de entrega el
 * producto llega con la cesta del socio en su nodo.
 *
 * Ciclo de estados (ver {@see canReceiveOrders()} y el servicio de transiciones):
 *   OPEN       apuntes abiertos; las socias añaden/editan sus líneas.
 *   CLOSED     cerrado el plazo de apuntes; se agregan cantidades por producto.
 *   CONFIRMED  la comisión confirma que se supera el mínimo del productor. SOLO
 *              aquí el pedido pasa a ser vinculante y se pide el pago (fuera de
 *              la app, por transferencia).
 *   CANCELLED  no se alcanzó el mínimo (o se anula); nadie paga.
 *   DELIVERED  entregado con la cesta.
 *
 * El MÍNIMO del productor NO se automatiza: su unidad varía (importe, cantidad,
 * nº de pedidos) e incluso se desconoce. Se guarda como texto informativo
 * ({@see $minimumCondition}) y la comisión confirma la ronda a mano viendo los
 * agregados. La app NO cobra en v1: las socias pagan por transferencia como hoy.
 *
 * @ORM\Table(name="consumer_group_round")
 * @ORM\Entity(repositoryClass="App\Repository\ConsumerGroupRoundRepository")
 */
class ConsumerGroupRound
{
    public const STATUS_OPEN = 0;
    public const STATUS_CLOSED = 1;
    /** @deprecated El "confirmado" ya no es un estado del plazo, es el flag {@see $confirmed}. Se conserva el valor 2 solo para el backfill de la migración. */
    public const STATUS_CONFIRMED = 2;
    public const STATUS_CANCELLED = 3;
    public const STATUS_DELIVERED = 4;

    /**
     * Etiquetas del ESTADO DEL PLAZO (abierto/cerrado/cancelado/entregado). El
     * "confirmado" es un flag aparte, no un estado (un pedido puede estar confirmado
     * y aún abierto).
     */
    public const STATUS_LABELS = [
        self::STATUS_OPEN => 'Abierto',
        self::STATUS_CLOSED => 'Cerrado',
        self::STATUS_CANCELLED => 'Cancelado',
        self::STATUS_DELIVERED => 'Entregado',
    ];

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * Título de la ronda (p. ej. "Fruta de temporada — julio").
     * @ORM\Column(type="string", length=180)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $title = '';

    /**
     * Productor de esta ronda (del catálogo persistente). RESTRICT: no se borra un
     * productor con rondas.
     * @ORM\ManyToOne(targetEntity="Producer")
     * @ORM\JoinColumn(name="producer_id", nullable=false, onDelete="RESTRICT")
     */
    #[Assert\NotNull]
    private ?Producer $producer = null;

    /**
     * @ORM\Column(type="smallint")
     */
    #[Assert\Choice(choices: [
        self::STATUS_OPEN,
        self::STATUS_CLOSED,
        self::STATUS_CANCELLED,
        self::STATUS_DELIVERED,
    ])]
    private int $status = self::STATUS_OPEN;

    /**
     * ¿Confirmado? Se ha alcanzado el mínimo del productor y el pedido se hará. Flag
     * INDEPENDIENTE del plazo: un pedido puede estar confirmado y aún ABIERTO a
     * apuntes/pagos/ampliaciones hasta el cierre. Abre el pago a las socias.
     * @ORM\Column(type="boolean")
     */
    private bool $confirmed = false;

    /**
     * Condición de mínimo del productor, informativa (p. ej. "mínimo 150 €" o
     * "50 kg" o "10 pedidos"). NO se calcula: la comisión decide viéndola.
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    #[Assert\Length(max: 255)]
    private ?string $minimumCondition = null;

    /**
     * Notas / descripción para las socias (opcional).
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $description = null;

    /**
     * Nota interna para el productor (lo que la comisión le pasa con el pedido).
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $providerNote = null;

    /**
     * Motivo de cancelación/rechazo, si la ronda se cae.
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $cancelReason = null;

    /**
     * Fechas de cada paso del ciclo (las estampa la máquina de estados al
     * transicionar). Trazabilidad de la ronda.
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?\DateTime $closedAt = null;

    /** @ORM\Column(type="datetime", nullable=true) */
    private ?\DateTime $confirmedAt = null;

    /** @ORM\Column(type="datetime", nullable=true) */
    private ?\DateTime $deliveredAt = null;

    /** @ORM\Column(type="datetime", nullable=true) */
    private ?\DateTime $cancelledAt = null;

    /**
     * Fecha y hora de cierre de apuntes. Pasada esta fecha, la ronda ya no
     * debería recibir pedidos (el cierre efectivo lo hace la transición a CLOSED).
     * @ORM\Column(type="datetime")
     */
    #[Assert\NotNull]
    private ?\DateTime $ordersCloseAt = null;

    /**
     * Día de entrega del producto (se reparte con la cesta de esa semana).
     * @ORM\Column(type="date")
     */
    #[Assert\NotNull]
    private ?\DateTime $deliveryDate = null;

    /**
     * Quién abrió la ronda (comisión). Nullable: si se borra el User, la ronda
     * sobrevive sin autor.
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="created_by_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $createdBy = null;

    /**
     * Productos incluidos en esta ronda, con su precio de ronda ({@see ConsumerGroupRoundItem}).
     * @ORM\OneToMany(targetEntity="ConsumerGroupRoundItem", mappedBy="round", cascade={"persist", "remove"}, orphanRemoval=true)
     * @ORM\OrderBy({"sortOrder": "ASC", "id": "ASC"})
     * @var Collection<int, ConsumerGroupRoundItem>
     */
    private Collection $items;

    /**
     * Pedidos de las socias en esta ronda.
     * @ORM\OneToMany(targetEntity="ConsumerGroupOrder", mappedBy="round", cascade={"persist", "remove"}, orphanRemoval=true)
     * @var Collection<int, ConsumerGroupOrder>
     */
    private Collection $orders;

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

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->orders = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
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

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    /**
     * Etiqueta legible del estado actual.
     */
    public function getStatusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? 'Desconocido';
    }

    /**
     * ¿Admite apuntes de socias ahora mismo? Solo mientras está OPEN.
     */
    public function canReceiveOrders(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * ¿Puede la comisión gestionar (crear/editar) pedidos de socias? Mientras la
     * ronda esté abierto o cerrado (antes de confirmar/cancelar/entregar).
     */
    public function canManageOrders(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_CLOSED], true);
    }

    /**
     * ¿Es un estado "vivo" que aparece en el panel del socio como accionable o
     * pendiente? (abierto o confirmado; cerrado es un limbo de gestión).
     */
    public function isConfirmed(): bool
    {
        return $this->confirmed;
    }

    public function setConfirmed(bool $confirmed): self
    {
        $this->confirmed = $confirmed;
        return $this;
    }

    public function getMinimumCondition(): ?string
    {
        return $this->minimumCondition;
    }

    public function setMinimumCondition(?string $minimumCondition): self
    {
        $this->minimumCondition = $minimumCondition;
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

    public function getProviderNote(): ?string
    {
        return $this->providerNote;
    }

    public function setProviderNote(?string $providerNote): self
    {
        $this->providerNote = $providerNote;
        return $this;
    }

    public function getCancelReason(): ?string
    {
        return $this->cancelReason;
    }

    public function setCancelReason(?string $cancelReason): self
    {
        $this->cancelReason = $cancelReason;
        return $this;
    }

    public function getClosedAt(): ?\DateTime
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTime $closedAt): self
    {
        $this->closedAt = $closedAt;
        return $this;
    }

    public function getConfirmedAt(): ?\DateTime
    {
        return $this->confirmedAt;
    }

    public function setConfirmedAt(?\DateTime $confirmedAt): self
    {
        $this->confirmedAt = $confirmedAt;
        return $this;
    }

    public function getDeliveredAt(): ?\DateTime
    {
        return $this->deliveredAt;
    }

    public function setDeliveredAt(?\DateTime $deliveredAt): self
    {
        $this->deliveredAt = $deliveredAt;
        return $this;
    }

    public function getCancelledAt(): ?\DateTime
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTime $cancelledAt): self
    {
        $this->cancelledAt = $cancelledAt;
        return $this;
    }

    public function getOrdersCloseAt(): ?\DateTime
    {
        return $this->ordersCloseAt;
    }

    public function setOrdersCloseAt(?\DateTime $ordersCloseAt): self
    {
        $this->ordersCloseAt = $ordersCloseAt;
        return $this;
    }

    public function getDeliveryDate(): ?\DateTime
    {
        return $this->deliveryDate;
    }

    public function setDeliveryDate(?\DateTime $deliveryDate): self
    {
        $this->deliveryDate = $deliveryDate;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    /**
     * @return Collection<int, ConsumerGroupRoundItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(ConsumerGroupRoundItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setRound($this);
        }
        return $this;
    }

    public function removeItem(ConsumerGroupRoundItem $item): self
    {
        if ($this->items->removeElement($item)) {
            if ($item->getRound() === $this) {
                $item->setRound(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, ConsumerGroupOrder>
     */
    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function addOrder(ConsumerGroupOrder $order): self
    {
        if (!$this->orders->contains($order)) {
            $this->orders->add($order);
            $order->setRound($this);
        }
        return $this;
    }

    public function removeOrder(ConsumerGroupOrder $order): self
    {
        if ($this->orders->removeElement($order)) {
            if ($order->getRound() === $this) {
                $order->setRound(null);
            }
        }
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
