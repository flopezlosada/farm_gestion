<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Algo que pasó en el voluntariado: se publicó una tarea, alguien se apuntó, se
 * anuló, se pidió gente, se cambió quién coordina un área…
 *
 * Mismo patrón que {@see PartnerEvent}, que es el rastro de actividad que el
 * proyecto ya usa para socixs: un `type` de vocabulario cerrado, un `payload`
 * con lo que varía según el tipo, y un `actor` con la misma convención
 * ("gestor:{id}", "partner:{id}", "system", "cli"). Copiar ese patrón en vez de
 * inventar otro es lo que hace que dentro de un año los dos rastros se lean
 * igual.
 *
 * POR QUÉ GUARDA EL ÁREA APARTE de la tarea. Quien coordina un área tiene que
 * ver su actividad y sólo la suya. Los eventos de una tarea se pueden filtrar
 * por las categorías de esa tarea, pero hay eventos que NO tienen tarea —crear
 * un tipo de trabajo, nombrar coordinadora— y ésos necesitan decir a qué área
 * pertenecen. De ahí que `category` exista además de `offer`.
 *
 * ES UN REGISTRO, NO UN ESTADO. Nada del módulo lee estos eventos para decidir
 * nada: si desaparecieran, la aplicación seguiría funcionando igual y sólo se
 * perdería la memoria de lo ocurrido. Por eso no hay borrado ni edición.
 *
 * @ORM\Table(name="volunteer_event", indexes={
 *     @ORM\Index(name="idx_volunteer_event_occurred", columns={"occurred_at"}),
 *     @ORM\Index(name="idx_volunteer_event_offer", columns={"offer_id"}),
 *     @ORM\Index(name="idx_volunteer_event_category", columns={"category_id"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\VolunteerEventRepository")
 */
class VolunteerEvent
{
    /** Se publicó o se creó una tarea. */
    public const TYPE_OFFER_CREATED = 'OFFER_CREATED';

    /** Se editó una tarea (fecha, sitio, plazas, lo que sea). */
    public const TYPE_OFFER_UPDATED = 'OFFER_UPDATED';

    /** Se anuló una tarea. */
    public const TYPE_OFFER_CANCELLED = 'OFFER_CANCELLED';

    /** Se crearon copias de una tarea en fechas siguientes. */
    public const TYPE_OFFER_REPEATED = 'OFFER_REPEATED';

    /** Un socix se apuntó. */
    public const TYPE_SIGNUP = 'SIGNUP';

    /** Un socix se dio de baja de una tarea. */
    public const TYPE_WITHDRAW = 'WITHDRAW';

    /** Se confirmó que alguien fue, y se le computaron minutos. */
    public const TYPE_ATTENDED = 'ATTENDED';

    /** Se dejó constancia de que alguien no fue. */
    public const TYPE_ABSENT = 'ABSENT';

    /** Gestión anotó a alguien a mano (vino sin apuntarse, o lo organizó). */
    public const TYPE_PERSON_ADDED = 'PERSON_ADDED';

    /** Se mandó un aviso pidiendo gente, con su alcance. */
    public const TYPE_CALL_SENT = 'CALL_SENT';

    /** Se creó un tipo de trabajo. */
    public const TYPE_CATEGORY_CREATED = 'CATEGORY_CREATED';

    /** Se editó un tipo de trabajo. */
    public const TYPE_CATEGORY_UPDATED = 'CATEGORY_UPDATED';

    /** Cambió quién coordina un área. */
    public const TYPE_COORDINATORS_CHANGED = 'COORDINATORS_CHANGED';

    /** Un socix cambió de qué quiere que se le avise. */
    public const TYPE_PREFERENCES_CHANGED = 'PREFERENCES_CHANGED';

    /** Cómo se lee cada tipo en pantalla. Vive aquí y no en la plantilla para
     *  que añadir un tipo sea tocar un sitio y no dos. */
    public const LABELS = [
        self::TYPE_OFFER_CREATED => 'Tarea creada',
        self::TYPE_OFFER_UPDATED => 'Tarea editada',
        self::TYPE_OFFER_CANCELLED => 'Tarea anulada',
        self::TYPE_OFFER_REPEATED => 'Tarea repetida',
        self::TYPE_SIGNUP => 'Se apuntó',
        self::TYPE_WITHDRAW => 'Se dio de baja',
        self::TYPE_ATTENDED => 'Confirmó que fue',
        self::TYPE_ABSENT => 'No fue',
        self::TYPE_PERSON_ADDED => 'Anotadx a mano',
        self::TYPE_CALL_SENT => 'Aviso enviado',
        self::TYPE_CATEGORY_CREATED => 'Tipo de trabajo creado',
        self::TYPE_CATEGORY_UPDATED => 'Tipo de trabajo editado',
        self::TYPE_COORDINATORS_CHANGED => 'Cambio de coordinación',
        self::TYPE_PREFERENCES_CHANGED => 'Cambio de preferencias',
    ];

    /** Actor de una tarea automática (el planificador). */
    public const ACTOR_SYSTEM = 'system';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=40)
     */
    private string $type;

    /**
     * La tarea a la que se refiere, si se refiere a alguna.
     *
     * `SET NULL` al borrarla: el rastro de que aquello ocurrió sobrevive a que
     * la tarea desaparezca, que es justo para lo que sirve un registro.
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\VolunteerOffer")
     * @ORM\JoinColumn(name="offer_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    private ?VolunteerOffer $offer = null;

    /**
     * El área a la que pertenece el evento, cuando no viene dada por una tarea.
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\VolunteerCategory")
     * @ORM\JoinColumn(name="category_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    private ?VolunteerCategory $category = null;

    /**
     * A quién afecta, si afecta a alguien concreto.
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\Partner")
     * @ORM\JoinColumn(name="partner_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    private ?Partner $partner = null;

    /**
     * Quién lo originó, con la convención de {@see PartnerEvent}:
     * "gestor:{user.id}", "partner:{partner.id}", "system" o "cli".
     *
     * Texto y no una relación a User a propósito: el rastro tiene que
     * sobrevivir al borrado de la cuenta, y "quién lo hizo" incluye actores que
     * no son cuentas (el planificador, un comando).
     *
     * @ORM\Column(type="string", length=80, nullable=true)
     */
    private ?string $actor = null;

    /**
     * Lo que cambia según el tipo. Por ejemplo:
     *   - OFFER_UPDATED:        {"moved":true,"relocated":false}
     *   - CALL_SENT:            {"scope":"matching","recipients":12}
     *   - ATTENDED:             {"minutes":30,"role":"participant"}
     *   - OFFER_REPEATED:       {"times":4,"cadence":"weekly","until":"2026-12-31"}
     *   - COORDINATORS_CHANGED: {"names":["Laura Tierno"]}
     *
     * @ORM\Column(type="json", nullable=true)
     */
    private ?array $payload = null;

    /**
     * @ORM\Column(name="occurred_at", type="datetime")
     */
    private \DateTimeInterface $occurredAt;

    public function __construct()
    {
        $this->type = self::TYPE_OFFER_CREATED;
        $this->occurredAt = new \DateTime();
    }

    /**
     * Cómo se lee este evento, o el código crudo si es de un tipo que ya no
     * está en el catálogo (un registro viejo tras retirar un tipo).
     *
     * @return string la etiqueta legible
     */
    public function getLabel(): string
    {
        return self::LABELS[$this->type] ?? $this->type;
    }

    /**
     * @return int|null el identificador, o null si aún no se ha persistido
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string el tipo de evento
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @param string $type el tipo de evento
     */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return VolunteerOffer|null la tarea a la que se refiere
     */
    public function getOffer(): ?VolunteerOffer
    {
        return $this->offer;
    }

    /**
     * @param VolunteerOffer|null $offer la tarea a la que se refiere
     */
    public function setOffer(?VolunteerOffer $offer): self
    {
        $this->offer = $offer;

        return $this;
    }

    /**
     * @return VolunteerCategory|null el área del evento
     */
    public function getCategory(): ?VolunteerCategory
    {
        return $this->category;
    }

    /**
     * @param VolunteerCategory|null $category el área del evento
     */
    public function setCategory(?VolunteerCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    /**
     * @return Partner|null a quién afecta
     */
    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    /**
     * @param Partner|null $partner a quién afecta
     */
    public function setPartner(?Partner $partner): self
    {
        $this->partner = $partner;

        return $this;
    }

    /**
     * @return string|null quién lo originó
     */
    public function getActor(): ?string
    {
        return $this->actor;
    }

    /**
     * @param string|null $actor quién lo originó
     */
    public function setActor(?string $actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    /**
     * @return array<string, mixed>|null los datos del evento
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed>|null $payload los datos del evento
     */
    public function setPayload(?array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    /**
     * @return \DateTimeInterface cuándo ocurrió
     */
    public function getOccurredAt(): \DateTimeInterface
    {
        return $this->occurredAt;
    }

    /**
     * @param \DateTimeInterface $occurredAt cuándo ocurrió
     */
    public function setOccurredAt(\DateTimeInterface $occurredAt): self
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }
}
