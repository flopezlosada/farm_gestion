<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Quién puede mandar facturas al bot de Telegram: una persona de la aplicación y
 * su cuenta de Telegram.
 *
 * Nace como una INVITACIÓN (código de un solo uso, con caducidad) y se completa
 * cuando esa persona abre el enlace y toca «Iniciar»: entonces se apunta su id de
 * Telegram y el código se borra. Nadie teclea nada, y el bot sólo admite
 * documentos de quien tenga un vínculo completo.
 *
 * Se vincula al {@see User} y no al {@see Worker}: manda facturas la tesorera,
 * que no es trabajadora, igual que los trabajadores, que también son User.
 *
 * Se guarda el id de USUARIO de Telegram (`from.id`), no nada propio del bot: si
 * algún día hay que cambiar de bot, la gente sigue vinculada.
 *
 * @ORM\Table(name="telegram_link")
 * @ORM\Entity(repositoryClass="App\Repository\TelegramLinkRepository")
 */
class TelegramLink
{
    /** Días que vale una invitación sin usar. */
    public const INVITATION_DAYS = 7;

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\OneToOne(targetEntity="User")
     * @ORM\JoinColumn(name="user_id", nullable=false, unique=true, onDelete="CASCADE")
     */
    private User $user;

    /**
     * Id de la cuenta de Telegram. Null mientras la invitación no se ha usado.
     * Cabe en 52 bits según Telegram: un BIGINT sobra.
     *
     * @ORM\Column(name="telegram_user_id", type="bigint", nullable=true, unique=true)
     */
    private ?string $telegramUserId = null;

    /**
     * Nombre con que aparece en Telegram al vincularse, para reconocerlo en el
     * listado (el nombre de usuario es opcional en Telegram; el nombre, no).
     *
     * @ORM\Column(name="telegram_name", type="string", length=150, nullable=true)
     */
    private ?string $telegramName = null;

    /**
     * Código de la invitación. Null una vez usada.
     *
     * @ORM\Column(name="invitation_code", type="string", length=64, nullable=true, unique=true)
     */
    private ?string $invitationCode = null;

    /**
     * @ORM\Column(name="invitation_expires_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $invitationExpiresAt = null;

    /**
     * @ORM\Column(name="linked_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $linkedAt = null;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created_at", type="datetime")
     */
    private ?\DateTimeInterface $createdAt = null;

    /**
     * @param User $user Persona a la que se invita.
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * Prepara una invitación nueva, que anula la anterior si la hubiera. No toca un
     * vínculo ya hecho: re-invitar sirve para pasar a otra cuenta de Telegram, y
     * mientras no se use la nueva, sigue valiendo la de siempre.
     *
     * @param \DateTimeImmutable $now Momento actual.
     *
     * @return string El código, para el enlace.
     */
    public function invite(\DateTimeImmutable $now): string
    {
        // 32 caracteres hexadecimales: dentro de lo que Telegram admite en el
        // parámetro de /start (letras, cifras, _ y -; hasta 64).
        $this->invitationCode = bin2hex(random_bytes(16));
        $this->invitationExpiresAt = $now->modify('+' . self::INVITATION_DAYS . ' days');

        return $this->invitationCode;
    }

    /**
     * Si la invitación sigue sirviendo.
     *
     * @param \DateTimeImmutable $now Momento actual.
     */
    public function isInvitationValid(\DateTimeImmutable $now): bool
    {
        return $this->invitationCode !== null
            && $this->invitationExpiresAt !== null
            && $this->invitationExpiresAt > $now;
    }

    /**
     * Completa el vínculo con la cuenta que ha usado la invitación, y la gasta.
     *
     * @param string             $telegramUserId Id de usuario de Telegram.
     * @param string|null        $telegramName   Nombre que muestra en Telegram.
     * @param \DateTimeImmutable $now            Momento actual.
     */
    public function link(string $telegramUserId, ?string $telegramName, \DateTimeImmutable $now): void
    {
        $this->telegramUserId = $telegramUserId;
        $this->telegramName = $telegramName !== null ? mb_substr($telegramName, 0, 150) : null;
        $this->linkedAt = $now;
        $this->invitationCode = null;
        $this->invitationExpiresAt = null;
    }

    /** Si ya puede mandar facturas. */
    public function isLinked(): bool
    {
        return $this->telegramUserId !== null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTelegramUserId(): ?string
    {
        return $this->telegramUserId;
    }

    public function getTelegramName(): ?string
    {
        return $this->telegramName;
    }

    public function getInvitationCode(): ?string
    {
        return $this->invitationCode;
    }

    public function getInvitationExpiresAt(): ?\DateTimeImmutable
    {
        return $this->invitationExpiresAt;
    }

    public function getLinkedAt(): ?\DateTimeImmutable
    {
        return $this->linkedAt;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
