<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Histórico inmutable de eventos de negocio sobre un socio: altas, bajas,
 * pausas, reanudaciones, inicio y fin de cesta, cambios de grupo A/B
 * (permanentes o puntuales) y cambios de nodo. Alimenta la estadística
 * de evolución y el feed de actividad del socio.
 *
 * No es Gedmo Loggable a propósito: queremos eventos de negocio nombrados,
 * no un diff de columnas.
 *
 * @ORM\Table(name="partner_event", indexes={
 *     @ORM\Index(name="idx_partner_event_partner_occurred", columns={"partner_id", "occurred_at"}),
 *     @ORM\Index(name="idx_partner_event_type_occurred", columns={"type", "occurred_at"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\PartnerEventRepository")
 */
class PartnerEvent
{
    public const TYPE_JOIN = 'JOIN';
    public const TYPE_LEAVE = 'LEAVE';
    public const TYPE_PAUSE = 'PAUSE';
    public const TYPE_RESUME = 'RESUME';
    public const TYPE_BASKET_START = 'BASKET_START';
    public const TYPE_BASKET_END = 'BASKET_END';
    public const TYPE_GROUP_CHANGE_PERMANENT = 'GROUP_CHANGE_PERMANENT';
    public const TYPE_WEEK_SWAP = 'WEEK_SWAP';
    public const TYPE_NODE_CHANGE = 'NODE_CHANGE';

    public const TYPES = [
        self::TYPE_JOIN,
        self::TYPE_LEAVE,
        self::TYPE_PAUSE,
        self::TYPE_RESUME,
        self::TYPE_BASKET_START,
        self::TYPE_BASKET_END,
        self::TYPE_GROUP_CHANGE_PERMANENT,
        self::TYPE_WEEK_SWAP,
        self::TYPE_NODE_CHANGE,
    ];

    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_CLI = 'cli';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="Partner")
     * @ORM\JoinColumn(name="partner_id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?Partner $partner = null;

    /**
     * Uno de PartnerEvent::TYPE_* (string corto en mayúsculas).
     * @ORM\Column(type="string", length=40)
     */
    #[Assert\NotBlank]
    private string $type;

    /**
     * Cuándo sucedió el evento, no cuándo se registró (ver $created).
     * Para eventos importados retroactivamente, se setea a la fecha real.
     * @ORM\Column(type="datetime")
     */
    #[Assert\NotNull]
    private \DateTimeInterface $occurredAt;

    /**
     * Datos contextuales del evento. Forma variable según $type, por ejemplo:
     *   - GROUP_CHANGE_PERMANENT: {"from":"A","to":"B"}
     *   - WEEK_SWAP:              {"from_date":"2026-05-22","to_date":"2026-05-29"}
     *   - NODE_CHANGE:            {"from_group_id":3,"to_group_id":5}
     *   - BASKET_START / BASKET_END: {"basket_share_id":1,"delivery_group":"A"}
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $payload = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $notes = null;

    /**
     * Quién originó el evento. Convenciones:
     *   - "system"               → transición automática del workflow
     *   - "gestor:{user.id}"     → cambio manual desde admin
     *   - "partner:{partner.id}" → autoservicio del socio
     *   - "cli"                  → comando de importación o cron
     * @ORM\Column(type="string", length=80, nullable=true)
     */
    private ?string $actor = null;

    /**
     * @ORM\Column(type="datetime")
     */
    private \DateTime $created;

    /**
     * @param Partner $partner Socio al que pertenece el evento.
     * @param string  $type    Uno de PartnerEvent::TYPE_*.
     * @param \DateTimeInterface|null $occurredAt Fecha real del evento; "ahora" si null.
     * @throws \InvalidArgumentException Si $type no es un valor conocido.
     */
    public function __construct(Partner $partner, string $type, ?\DateTimeInterface $occurredAt = null)
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Tipo de evento desconocido: %s', $type));
        }

        $this->partner = $partner;
        $this->type = $type;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
        $this->created = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPartner(): Partner
    {
        return $this->partner;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getOccurredAt(): \DateTimeInterface
    {
        return $this->occurredAt;
    }

    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function setPayload(?array $payload): self
    {
        $this->payload = $payload;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function getActor(): ?string
    {
        return $this->actor;
    }

    public function setActor(?string $actor): self
    {
        $this->actor = $actor;
        return $this;
    }

    public function getCreated(): \DateTime
    {
        return $this->created;
    }
}
