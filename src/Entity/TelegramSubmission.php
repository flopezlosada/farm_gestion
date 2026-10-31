<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Lo que alguien ha mandado al bot, guardado ANTES de que ningún apartado se lo
 * quede: el texto, su propia copia del fichero, quién lo mandó y adónde ha ido a
 * parar.
 *
 * Es lo que permite corregir al bot. Si lo mandó a facturas y era otra cosa, el
 * envío sigue aquí con su fichero, así que se deshace lo de facturas y se manda al
 * apartado bueno, desde Telegram («Es otra cosa») o desde la web.
 *
 * Tres estados:
 *  - pendiente: el bot no estaba seguro y ha preguntado. No ha entrado en ningún
 *    apartado; lo confirma quien lo mandó o, desde la web, la gestión.
 *  - encaminado: está en un apartado ({@see $destination}, {@see $targetRef}).
 *  - descartado: no era nada que guardar.
 *
 * @ORM\Table(name="telegram_submission", indexes={
 *     @ORM\Index(name="idx_telegram_submission_status", columns={"status", "created_at"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\TelegramSubmissionRepository")
 */
class TelegramSubmission
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ROUTED = 'routed';
    public const STATUS_DISCARDED = 'discarded';

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
    private User $user;

    /**
     * Chat de Telegram de quien lo mandó, para contestarle aunque se encamine
     * desde la web días después.
     *
     * @ORM\Column(name="chat_id", type="string", length=32)
     */
    private string $chatId;

    /**
     * Qué es: una de las constantes de {@see \App\Service\Telegram\TelegramInput}.
     *
     * @ORM\Column(name="kind", type="string", length=20)
     */
    private string $kind;

    /**
     * El texto del mensaje o el pie de la foto.
     *
     * @ORM\Column(name="text", type="text", nullable=true)
     */
    private ?string $text = null;

    /**
     * Nombre del fichero en el archivo del bot ({@see \App\Service\Telegram\TelegramFileStore}).
     *
     * @ORM\Column(name="file_name", type="string", length=255, nullable=true)
     */
    private ?string $fileName = null;

    /**
     * @ORM\Column(name="original_name", type="string", length=255, nullable=true)
     */
    private ?string $originalName = null;

    /**
     * @ORM\Column(name="mime_type", type="string", length=100, nullable=true)
     */
    private ?string $mimeType = null;

    /**
     * @ORM\Column(name="sent_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $sentAt;

    /**
     * @ORM\Column(name="status", type="string", length=20)
     */
    private string $status = self::STATUS_PENDING;

    /**
     * Clave del apartado donde está ({@see \App\Service\Telegram\Destination\TelegramDestination::key()}).
     *
     * @ORM\Column(name="destination", type="string", length=40, nullable=true)
     */
    private ?string $destination = null;

    /**
     * Qué ha creado ese apartado, en sus términos (el id de la factura…). Sólo el
     * apartado sabe leerlo: es lo que necesita para deshacerlo.
     *
     * @ORM\Column(name="target_ref", type="string", length=64, nullable=true)
     */
    private ?string $targetRef = null;

    /**
     * Lo que el bot le dijo a quien lo mandó que había hecho, o la duda que le
     * planteó: lo mismo que se ve en la web.
     *
     * @ORM\Column(name="summary", type="string", length=255, nullable=true)
     */
    private ?string $summary = null;

    /**
     * El mensaje del bot con los botones, para cambiarlo cuando se resuelve.
     *
     * @ORM\Column(name="bot_message_id", type="integer", nullable=true)
     */
    private ?int $botMessageId = null;

    /**
     * Quién lo resolvió desde la web. Null si lo resolvió el propio bot o quien lo
     * mandó, desde Telegram.
     *
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="resolved_by_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $resolvedBy = null;

    /**
     * @ORM\Column(name="resolved_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $resolvedAt = null;

    /**
     * El `update_id` con que lo entregó Telegram. Se guarda para que un apartado que
     * no debe registrar dos veces el mismo mensaje (un arqueo) lo reconozca también al
     * colocarlo después desde la web. Null en los envíos de antes de guardarlo.
     *
     * @ORM\Column(name="update_id", type="bigint", nullable=true)
     */
    private ?string $updateId = null;

    /**
     * @ORM\Column(name="created_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @param User               $user   Quién lo manda.
     * @param string             $chatId Chat de Telegram por el que contestarle.
     * @param string             $kind   Qué es (foto, documento, voz, texto).
     * @param string             $text   Texto o pie ('' si no hay).
     * @param \DateTimeImmutable $sentAt Cuándo lo mandó.
     * @param int|null           $updateId Su `update_id` de Telegram, si se sabe.
     */
    public function __construct(User $user, string $chatId, string $kind, string $text, \DateTimeImmutable $sentAt, ?int $updateId = null)
    {
        $this->updateId = $updateId !== null ? (string) $updateId : null;
        $this->user = $user;
        $this->chatId = $chatId;
        $this->kind = $kind;
        $this->text = $text !== '' ? $text : null;
        $this->sentAt = $sentAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** El `update_id` con que lo entregó Telegram, si se sabe. */
    public function getUpdateId(): ?int
    {
        return $this->updateId !== null ? (int) $this->updateId : null;
    }

    /**
     * Le pone su copia del fichero.
     *
     * @param string      $fileName     Nombre en el archivo del bot.
     * @param string      $originalName Nombre con que llegó.
     * @param string|null $mimeType     Tipo, según el contenido.
     */
    public function attachFile(string $fileName, string $originalName, ?string $mimeType): void
    {
        $this->fileName = $fileName;
        $this->originalName = mb_substr($originalName, 0, 255);
        $this->mimeType = $mimeType;
    }

    /**
     * Queda a la espera de que alguien diga qué es.
     *
     * @param string $question La duda, tal como se le ha planteado.
     */
    public function askAbout(string $question): void
    {
        $this->status = self::STATUS_PENDING;
        $this->destination = null;
        $this->targetRef = null;
        $this->summary = mb_substr($question, 0, 255);
        $this->resolvedAt = null;
        $this->resolvedBy = null;
    }

    /**
     * Ha entrado en un apartado.
     *
     * @param string             $destination Clave del apartado.
     * @param string             $targetRef   Lo que ha creado allí.
     * @param string             $summary     Lo que se le dice a quien lo mandó.
     * @param User|null          $by          Quién lo encaminó desde la web; null si fue por Telegram.
     * @param \DateTimeImmutable $now         Momento actual.
     */
    public function routeTo(string $destination, string $targetRef, string $summary, ?User $by, \DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_ROUTED;
        $this->destination = $destination;
        $this->targetRef = $targetRef;
        $this->summary = mb_substr($summary, 0, 255);
        $this->resolvedBy = $by;
        $this->resolvedAt = $now;
    }

    /**
     * No era nada que guardar.
     *
     * @param string             $summary Por qué, o lo que se le ha dicho.
     * @param User|null          $by      Quién lo descartó desde la web; null si fue por Telegram.
     * @param \DateTimeImmutable $now     Momento actual.
     */
    public function discard(string $summary, ?User $by, \DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_DISCARDED;
        $this->destination = null;
        $this->targetRef = null;
        $this->summary = mb_substr($summary, 0, 255);
        $this->resolvedBy = $by;
        $this->resolvedAt = $now;
    }

    /**
     * Apunta el mensaje del bot que lleva los botones de este envío.
     *
     * @param int|null $messageId Id del mensaje en el chat.
     */
    public function setBotMessageId(?int $messageId): void
    {
        $this->botMessageId = $messageId;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getChatId(): string
    {
        return $this->chatId;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getText(): string
    {
        return (string) $this->text;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRouted(): bool
    {
        return $this->status === self::STATUS_ROUTED;
    }

    public function getDestination(): ?string
    {
        return $this->destination;
    }

    public function getTargetRef(): ?string
    {
        return $this->targetRef;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getBotMessageId(): ?int
    {
        return $this->botMessageId;
    }

    public function getResolvedBy(): ?User
    {
        return $this->resolvedBy;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
