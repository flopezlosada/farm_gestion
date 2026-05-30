<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Histórico inmutable de eventos de cuenta sobre un User. Por ahora sólo
 * bloqueo y desbloqueo de acceso (enabled), pero el tipo es extensible.
 * Espejo ligero de PartnerEvent: aquí no hace falta payload ni una fecha
 * de "ocurrencia" distinta de la de registro, así que se simplifica.
 *
 * No es Gedmo Loggable a propósito: queremos eventos de cuenta nombrados,
 * no un diff de columnas.
 *
 * @ORM\Table(name="user_event", indexes={
 *     @ORM\Index(name="idx_user_event_user_created", columns={"user_id", "created"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\UserEventRepository")
 */
class UserEvent
{
    public const TYPE_BLOCK = 'BLOCK';
    public const TYPE_UNBLOCK = 'UNBLOCK';

    public const TYPES = [
        self::TYPE_BLOCK,
        self::TYPE_UNBLOCK,
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
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="user_id", nullable=false, onDelete="CASCADE")
     */
    #[Assert\NotNull]
    private ?User $user = null;

    /**
     * Uno de UserEvent::TYPE_* (string corto en mayúsculas).
     * @ORM\Column(type="string", length=40)
     */
    #[Assert\NotBlank]
    private string $type;

    /**
     * Quién originó el evento. Convenciones:
     *   - "gestor:{user.id}" → acción manual desde admin
     *   - "system"           → automático
     *   - "cli"              → comando o cron
     * @ORM\Column(type="string", length=80, nullable=true)
     */
    private ?string $actor = null;

    /**
     * Motivo opcional del bloqueo/desbloqueo.
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $notes = null;

    /**
     * @ORM\Column(type="datetime")
     */
    private \DateTime $created;

    /**
     * @param User   $user Cuenta a la que pertenece el evento.
     * @param string $type Uno de UserEvent::TYPE_*.
     * @throws \InvalidArgumentException Si $type no es un valor conocido.
     */
    public function __construct(User $user, string $type)
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Tipo de evento desconocido: %s', $type));
        }

        $this->user = $user;
        $this->type = $type;
        $this->created = new \DateTime();
    }

    /**
     * @return int|null Identificador, null si aún no se ha persistido.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return User Cuenta a la que pertenece el evento.
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @return string Uno de UserEvent::TYPE_*.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return string|null Originador del evento (ver convención en $actor).
     */
    public function getActor(): ?string
    {
        return $this->actor;
    }

    /**
     * @param string|null $actor Originador del evento.
     * @return self Para encadenar.
     */
    public function setActor(?string $actor): self
    {
        $this->actor = $actor;
        return $this;
    }

    /**
     * @return string|null Motivo del bloqueo/desbloqueo, si se indicó.
     */
    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /**
     * @param string|null $notes Motivo del bloqueo/desbloqueo.
     * @return self Para encadenar.
     */
    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    /**
     * @return \DateTime Cuándo se registró el evento.
     */
    public function getCreated(): \DateTime
    {
        return $this->created;
    }
}
