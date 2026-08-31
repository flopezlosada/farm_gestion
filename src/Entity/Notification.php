<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Un aviso guardado para que quien lo recibe lo encuentre al entrar en la web.
 *
 * ES EL SUELO DE TODOS LOS AVISOS, y de ahí sale todo lo demás de esta clase. El
 * correo y el push son empujones: llegan al sitio donde estás y se pierden si no
 * los ves. Esta fila no se pierde. Por eso NO es un tercer canal configurable y
 * NO tiene casilla en la pantalla de preferencias: es lo que queda cuando alguien
 * apaga los otros dos, y es justo lo que permite que apagarlos sea aceptable.
 * Si algún día se le pone un interruptor, apagar los tres deja a un socix sin
 * enterarse de su recogida por ningún sitio.
 *
 * EL DESTINATARIO ES UN {@see User} Y NO UN {@see Partner}, aunque los cuatro
 * envíos de la asociación decidan a quién avisar en términos de socixs. Dos
 * motivos:
 *  - esto se ve entrando en la web, y quien entra es una cuenta; un socix sin
 *    cuenta no tiene dónde leerlo;
 *  - hay avisos que no son de socixs. El de «a esta gente le faltan datos» va a
 *    quien coordina socixs, que puede ser una cuenta de gestión sin Partner
 *    detrás.
 * Los envíos resuelven socix → cuenta(s) con {@see \App\Repository\UserRepository::findByPartners()}.
 *
 * NO GUARDA A QUÉ APUNTA, y no es un olvido. El destino sale del `kind` en un
 * único sitio ({@see \App\Service\Notification\NotificationLink}), el mismo que
 * usa la notificación push, para que la fila de la bandeja y el aviso del móvil
 * no puedan llevar a pantallas distintas. Una columna por módulo (la cesta, la
 * tarea, el socix…) sería una columna nueva por cada aviso que se añada.
 *
 * @ORM\Table(name="notification", indexes={
 *     @ORM\Index(name="idx_notification_recipient", columns={"recipient_id", "read_at", "created_at"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\NotificationRepository")
 */
class Notification
{
    /** Recordatorio de recogida de la cesta. */
    public const KIND_PICKUP_REMINDER = 'pickup.reminder';

    /** Hace falta gente para una tarea de voluntariado. */
    public const KIND_VOLUNTEERING_CALL = 'volunteering.call';

    /** Te toca la tarea de voluntariado a la que te apuntaste. */
    public const KIND_VOLUNTEERING_REMINDER = 'volunteering.reminder';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\User")
     * @ORM\JoinColumn(name="recipient_id", nullable=false, onDelete="CASCADE")
     */
    private User $recipient;

    /**
     * Clase de aviso, de las declaradas en KIND_*. Decide el icono de la bandeja
     * y, sobre todo, a dónde lleva.
     *
     * @ORM\Column(type="string", length=40)
     */
    private string $kind;

    /**
     * @ORM\Column(type="string", length=200)
     */
    private string $title;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $body = null;

    /**
     * @ORM\Column(name="created_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * Cuándo lo abrió quien lo recibió, o null si aún no lo ha abierto. Es lo que
     * cuenta la campanita.
     *
     * @ORM\Column(name="read_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $readAt = null;

    /**
     * @param User        $recipient a quién va
     * @param string      $kind      una de las constantes KIND_*
     * @param string      $title     lo que se lee de un vistazo
     * @param string|null $body      el detalle, si hay algo más que decir
     */
    public function __construct(User $recipient, string $kind, string $title, ?string $body = null)
    {
        $this->recipient = $recipient;
        $this->kind = $kind;
        $this->title = $title;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return null !== $this->readAt;
    }

    /**
     * Lo marca leído, y sólo la primera vez: la fecha de lectura es cuándo se
     * abrió, no la última vez que se pasó por delante.
     */
    public function markRead(): self
    {
        $this->readAt ??= new \DateTimeImmutable();

        return $this;
    }
}
