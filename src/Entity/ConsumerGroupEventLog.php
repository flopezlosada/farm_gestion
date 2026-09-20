<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Una línea por cada cosa que pasa en el GRUPO DE CONSUMO: abrir/cerrar/confirmar/
 * cancelar/entregar un pedido, cambios de productos y precios, pedidos de socias
 * (crear/editar/vaciar) y altas/bajas del catálogo de un productor. Se escribe
 * siempre que algo cambia y no se modifica nunca — es la bitácora, no el estado.
 *
 * NO es {@see NotificationLog} (esa es de avisos ENTREGADOS por email/push) ni
 * {@see \App\Entity\EmittedEffect} (guardián de idempotencia que se autoborra en
 * fallo). Aquí no hay reintentos ni transporte: es simplemente "qué pasó, quién y
 * cuándo", para poder responder eso sin reconstruirlo a mano desde el histórico de
 * git o memoria.
 *
 * `round` es NULLABLE: un alta/edición/borrado de producto en el catálogo de un
 * {@see Producer} no está ligado a ningún pedido concreto.
 *
 * @ORM\Table(
 *     name="consumer_group_event_log",
 *     indexes={
 *         @ORM\Index(name="IDX_cg_event_round", columns={"round_id", "occurred_at"}),
 *         @ORM\Index(name="IDX_cg_event_occurred", columns={"occurred_at"})
 *     }
 * )
 * @ORM\Entity(repositoryClass="App\Repository\ConsumerGroupEventLogRepository")
 */
class ConsumerGroupEventLog
{
    public const KIND_ROUND_CREATED = 'round_created';
    public const KIND_ROUND_UPDATED = 'round_updated';
    public const KIND_ROUND_TRANSITIONED = 'round_transitioned';
    public const KIND_ROUND_CONFIRMED = 'round_confirmed';
    public const KIND_ITEMS_UPDATED = 'items_updated';
    public const KIND_ASSOCIATION_ORDER_UPDATED = 'association_order_updated';
    public const KIND_ORDER_CREATED = 'order_created';
    public const KIND_ORDER_UPDATED = 'order_updated';
    public const KIND_ORDER_EMPTIED = 'order_emptied';
    public const KIND_ORDER_PAYMENT_TOGGLED = 'order_payment_toggled';
    public const KIND_ORDER_PICKUP_TOGGLED = 'order_pickup_toggled';
    public const KIND_PRODUCT_CREATED = 'product_created';
    public const KIND_PRODUCT_UPDATED = 'product_updated';
    public const KIND_PRODUCT_DELETED = 'product_deleted';
    public const KIND_PRODUCER_LOGIN = 'producer_login';

    /** Etiquetas en español para pintar en la pestaña de actividad. */
    public const KIND_LABELS = [
        self::KIND_ROUND_CREATED => 'Pedido creado',
        self::KIND_ROUND_UPDATED => 'Datos del pedido',
        self::KIND_ROUND_TRANSITIONED => 'Cambio de estado',
        self::KIND_ROUND_CONFIRMED => 'Pedido confirmado',
        self::KIND_ITEMS_UPDATED => 'Productos y precios',
        self::KIND_ASSOCIATION_ORDER_UPDATED => 'Pedido para el local',
        self::KIND_ORDER_CREATED => 'Pedido de socia',
        self::KIND_ORDER_UPDATED => 'Pedido de socia',
        self::KIND_ORDER_EMPTIED => 'Pedido de socia',
        self::KIND_ORDER_PAYMENT_TOGGLED => 'Pago',
        self::KIND_ORDER_PICKUP_TOGGLED => 'Recogida',
        self::KIND_PRODUCT_CREATED => 'Catálogo',
        self::KIND_PRODUCT_UPDATED => 'Catálogo',
        self::KIND_PRODUCT_DELETED => 'Catálogo',
        self::KIND_PRODUCER_LOGIN => 'Acceso del productor',
    ];

    /**
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="ConsumerGroupRound")
     * @ORM\JoinColumn(name="round_id", nullable=true, onDelete="SET NULL")
     */
    private ?ConsumerGroupRound $round = null;

    /**
     * @ORM\Column(name="kind", type="string", length=60)
     */
    private string $kind = '';

    /**
     * Quién lo hizo, cuando se sabe. Puede ser null (acción del sistema/cron).
     *
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="actor_user_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $actorUser = null;

    /**
     * Snapshot legible del actor en el momento ("Ana García (socia)", "El
     * Berrueco (productor)"): si la cuenta se borra después, el histórico sigue
     * diciendo quién fue. Mismo motivo que {@see NotificationLog::$target}.
     *
     * @ORM\Column(name="actor_label", type="string", length=180)
     */
    private string $actorLabel = '';

    /**
     * Descripción corta ya formada ("Pedido cerrado", "3,5 kg de tomate → 2 kg"),
     * para que el listado sea legible sin ir a reconstruir el detalle.
     *
     * @ORM\Column(name="summary", type="string", length=255)
     */
    private string $summary = '';

    /**
     * @ORM\Column(name="occurred_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $occurredAt;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
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

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    public function getKindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }

    public function getActorUser(): ?User
    {
        return $this->actorUser;
    }

    public function setActorUser(?User $actorUser): self
    {
        $this->actorUser = $actorUser;

        return $this;
    }

    public function getActorLabel(): string
    {
        return $this->actorLabel;
    }

    public function setActorLabel(string $actorLabel): self
    {
        $this->actorLabel = mb_substr($actorLabel, 0, 180);

        return $this;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): self
    {
        $this->summary = mb_substr($summary, 0, 255);

        return $this;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
