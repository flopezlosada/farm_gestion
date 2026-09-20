<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Ronda de pedido del GRUPO DE CONSUMO: un pedido colectivo de productos de un
 * tercero (fruta de temporada, aceite…) que la asociación NO produce y que se
 * reparte junto con la cesta semanal. Lo abre la comisión del grupo de consumo,
 * las socias se apuntan mientras está abierto, y en la fecha de entrega el
 * producto llega con la cesta del socio en su nodo.
 *
 * Estado del PLAZO (ver {@see canReceiveOrders()} y el servicio de transiciones):
 *   OPEN       apuntes abiertos; las socias añaden/editan sus líneas.
 *   CLOSED     cerrado el plazo de apuntes; se agregan cantidades por producto.
 *   CANCELLED  no se alcanzó el mínimo (o se anula); nadie paga.
 *   DELIVERED  entregado con la cesta.
 *
 * Y aparte, el flag {@see $confirmed}: la comisión confirma que se supera el
 * mínimo del productor. SOLO entonces el pedido de las socias es vinculante y se
 * les pide el pago (fuera de la app, por transferencia). No es un estado del
 * plazo, y por eso está separado: un pedido confirmado sigue admitiendo apuntes
 * hasta que se cierra.
 *
 * El MÍNIMO del productor se automatiza SOLO cuando encaja en uno de los dos
 * tipos calculables sin ambigüedad ({@see $minimumType}: importe total o nº de
 * socias con pedido — ver {@see minimumReached()}); en ese caso
 * {@see \App\Service\ConsumerGroup\MinimumAutoConfirmer} confirma la ronda sola
 * al guardar un pedido. Cuando el mínimo es de otro tipo (una cantidad de
 * producto concreto, o directamente se desconoce), se guarda como texto
 * informativo ({@see $minimumCondition}) y la comisión confirma a mano viendo
 * los agregados, como siempre. La app NO cobra en v1: las socias pagan por
 * transferencia como hoy.
 *
 * @ORM\Table(name="consumer_group_round")
 * @ORM\Entity(repositoryClass="App\Repository\ConsumerGroupRoundRepository")
 */
class ConsumerGroupRound
{
    /** Mínimo expresado como importe total (€) del agregado de la ronda. */
    public const MINIMUM_TYPE_AMOUNT = 1;
    /** Mínimo expresado como nº de socias con pedido no vacío. */
    public const MINIMUM_TYPE_PARTICIPANTS = 2;

    public const MINIMUM_TYPE_LABELS = [
        self::MINIMUM_TYPE_AMOUNT => 'Importe total (€)',
        self::MINIMUM_TYPE_PARTICIPANTS => 'Nº de socias con pedido',
    ];

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
     * Nota del mínimo del productor, informativa e independiente de
     * {@see $minimumType}/{@see $minimumValue} (p. ej. "sujeto a disponibilidad" o
     * un mínimo en una unidad que no se automatiza, como "50 kg de aceitunas").
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    #[Assert\Length(max: 255)]
    private ?string $minimumCondition = null;

    /**
     * Qué se compara para el mínimo automatizable ({@see MINIMUM_TYPE_LABELS}), o
     * null si el mínimo de esta ronda no se automatiza (se confirma a mano, como
     * siempre). Solo estos dos tipos son calculables sin ambigüedad a partir del
     * agregado de la ronda: un mínimo en unidades de producto ("50 kg") no lo es,
     * porque una ronda puede tener varios productos con unidades distintas.
     * @ORM\Column(type="smallint", nullable=true)
     */
    #[Assert\Choice(choices: [self::MINIMUM_TYPE_AMOUNT, self::MINIMUM_TYPE_PARTICIPANTS], message: 'Tipo de mínimo no válido.')]
    private ?int $minimumType = null;

    /**
     * Umbral del mínimo, en la unidad de {@see $minimumType}. Decimal como string,
     * igual que el resto de importes/cantidades del módulo. Positivo estricto:
     * un umbral de 0 se cumpliría siempre (incluso con la ronda vacía), que
     * autoconfirmaría cualquier ronda con este tipo en cuanto alguien tocara un
     * apunte, sin que nadie lo haya pedido de verdad.
     * @ORM\Column(type="decimal", precision=8, scale=2, nullable=true)
     */
    #[Assert\Positive(message: 'El umbral del mínimo tiene que ser mayor que cero.')]
    private ?string $minimumValue = null;

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
     * Cuándo se avisó a la asociación de que este pedido está abierto.
     *
     * Es la memoria VISIBLE del aviso: la pantalla dice cuándo salió y el botón
     * cambia de «avisar» a «volver a avisar». Que no se mande dos veces lo
     * garantiza aparte el registro de efectos
     * ({@see \App\Service\ConsumerGroup\ConsumerGroupAnnouncer}); esta columna no
     * es el candado, es lo que lee la comisión.
     * @ORM\Column(type="datetime", nullable=true)
     */
    private ?\DateTime $announcedAt = null;

    /**
     * Día de cierre de apuntes (sin hora: la comisión sólo decide el día, no una
     * hora concreta). Admite apuntes durante todo ese día; pasado, la ronda ya no
     * debería recibir pedidos (el cierre efectivo lo hace la transición a CLOSED).
     * @ORM\Column(type="date")
     */
    #[Assert\NotNull]
    private ?\DateTime $ordersCloseAt = null;

    /**
     * Día de entrega del producto (se reparte con la cesta de esa semana). Puede
     * no conocerse todavía al abrir la ronda (depende del productor): se añade
     * después, antes de la entrega.
     * @ORM\Column(type="date", nullable=true)
     */
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
     * ¿Admite apuntes de socias ahora mismo? Exige estar OPEN y que el plazo no
     * haya vencido.
     *
     * LA FECHA DE CIERRE ERA DECORATIVA. La comisión escribía «cierre: viernes»,
     * la pantalla lo enseñaba, y el domingo se seguía pudiendo apuntar gente
     * hasta que alguien entraba a cerrar el pedido a mano. Quien llegaba tarde
     * entraba, y quien se fiaba de la fecha para pasarle el pedido al productor
     * se encontraba cantidades nuevas después.
     *
     * Sin fecha se sigue admitiendo: es un pedido al que no le han puesto plazo
     * todavía, no uno vencido.
     *
     * NO ES EL CIERRE DEL PLAZO: el estado lo cambia la comisión cuando pasa el
     * pedido al productor ({@see \App\Service\ConsumerGroup\RoundStateMachine}).
     * Esto sólo impide apuntarse, que es lo que la fecha prometía. Por eso la
     * comisión sigue pudiendo tocar el pedido con el plazo vencido: para eso
     * está {@see canManageOrders()}.
     */
    public function canReceiveOrders(): bool
    {
        if ($this->status !== self::STATUS_OPEN) {
            return false;
        }

        return $this->ordersCloseAt === null || $this->ordersCloseAt >= new \DateTime('today');
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

    /**
     * ¿Ya se entregó? Sólo entonces tiene sentido hablar de recogida: antes no
     * hay nada físico que recoger.
     */
    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    /**
     * ¿Ya ha recogido TODA socia con pedido real? "Entregado" solo dice que
     * llegó al local, no que se lo hayan llevado — esto es lo segundo, y no es
     * un estado propio de la ronda (no hay transición de máquina de estados
     * para ello): se calcula solo, se pone al día sin que nadie tenga que
     * tocar nada, y deja de ser cierto si luego se desmarca una recogida.
     */
    public function isFullyPickedUp(): bool
    {
        if (!$this->isDelivered()) {
            return false;
        }

        $withOrder = false;
        foreach ($this->orders as $order) {
            if ($order->isEmpty()) {
                continue;
            }
            $withOrder = true;
            if (!$order->isPickedUp()) {
                return false;
            }
        }

        return $withOrder;
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

    public function getMinimumType(): ?int
    {
        return $this->minimumType;
    }

    public function setMinimumType(?int $minimumType): self
    {
        $this->minimumType = $minimumType;
        return $this;
    }

    public function getMinimumValue(): ?string
    {
        return $this->minimumValue;
    }

    /**
     * "Importe total (€): 150" o similar, para pintar de un vistazo el mínimo
     * automático configurado. Null si no hay uno (mínimo manual, o ninguno).
     */
    public function getMinimumTypeLabel(): ?string
    {
        if ($this->minimumType === null) {
            return null;
        }

        return sprintf('%s: %s', self::MINIMUM_TYPE_LABELS[$this->minimumType] ?? $this->minimumType, $this->minimumValue ?? '—');
    }

    public function setMinimumValue(?string $minimumValue): self
    {
        $this->minimumValue = $minimumValue;
        return $this;
    }

    /**
     * ¿Se alcanza el mínimo automatizable con este agregado? Null si la ronda no
     * tiene un mínimo de este tipo configurado (sigue siendo manual). Lógica pura:
     * recibe el resultado de {@see \App\Service\ConsumerGroup\OrderAggregator::aggregate()},
     * no vuelve a calcularlo.
     *
     * @param array{participantCount: int, total: float} $aggregate
     */
    public function minimumReached(array $aggregate): ?bool
    {
        if ($this->minimumType === null || $this->minimumValue === null) {
            return null;
        }

        $threshold = (float) $this->minimumValue;

        return match ($this->minimumType) {
            // En CÉNTIMOS enteros, no en float directo: sumar varios round(x,2)
            // en coma flotante puede dar 149.99999999999997 en vez de 150.0, y
            // eso dejaría un pedido justo en el mínimo sin confirmarse hasta el
            // siguiente apunte. Redondear a entero antes de comparar lo evita
            // sin arrastrar bcmath (no es una dependencia del proyecto).
            self::MINIMUM_TYPE_AMOUNT => (int) round($aggregate['total'] * 100) >= (int) round($threshold * 100),
            self::MINIMUM_TYPE_PARTICIPANTS => $aggregate['participantCount'] >= $threshold,
            default => null,
        };
    }

    /**
     * El mínimo automático va TODO O NADA: tipo y umbral juntos, o ninguno de
     * los dos. Un tipo sin umbral (o al revés) no da error al guardar —cada
     * campo por separado es válido— pero deja la ronda en un limbo donde
     * {@see getMinimumTypeLabel()} pinta un mínimo automático que
     * {@see minimumReached()} nunca evalúa (falta uno de los dos datos), sin
     * ningún aviso de que no está vigilando nada.
     */
    #[Assert\Callback]
    public function validateMinimumTypeAndValueGoTogether(ExecutionContextInterface $context): void
    {
        if (($this->minimumType === null) !== ($this->minimumValue === null)) {
            $context->buildViolation('El mínimo automático necesita tipo y umbral a la vez, o ninguno de los dos.')
                ->atPath('minimumValue')
                ->addViolation();
        }
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

    /**
     * Cuándo se avisó de la apertura, o null si todavía no se ha avisado.
     */
    public function getAnnouncedAt(): ?\DateTime
    {
        return $this->announcedAt;
    }

    public function setAnnouncedAt(?\DateTime $announcedAt): self
    {
        $this->announcedAt = $announcedAt;
        return $this;
    }

    public function getOrdersCloseAt(): ?\DateTime
    {
        return $this->ordersCloseAt;
    }

    /**
     * Trunca a medianoche: es un día, no un instante. Así {@see canReceiveOrders()}
     * compara días contra días aunque aún no se haya pasado por BBDD (la columna
     * `date` lo haría igualmente al persistir, pero el objeto en memoria debe
     * comportarse igual antes del flush).
     */
    public function setOrdersCloseAt(?\DateTime $ordersCloseAt): self
    {
        $this->ordersCloseAt = $ordersCloseAt !== null ? (clone $ordersCloseAt)->setTime(0, 0, 0) : null;
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
