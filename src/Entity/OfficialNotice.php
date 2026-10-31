<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Un aviso de notificación electrónica oficial (DEHú, Agencia Tributaria, Seguridad
 * Social) que ha llegado al buzón con la firma de su organismo.
 *
 * El correo sólo AVISA de que hay una notificación en la sede; la notificación no
 * está aquí. Si nadie la abre en 10 días naturales desde que se puso a disposición,
 * cuenta como notificada y empiezan a correr los plazos. Por eso la fila se queda en
 * la portada de contabilidad hasta que alguien entra en la sede y lo marca, y queda
 * quién y cuándo.
 *
 * No guarda nada del correo (ni asunto, ni cuerpo, ni enlaces): lo único que hace
 * falta es saber que llegó y de quién. Del Message-ID guarda sólo su huella, para no
 * avisar dos veces del mismo correo.
 *
 * @ORM\Table(name="official_notice")
 * @ORM\Entity(repositoryClass="App\Repository\OfficialNoticeRepository")
 */
class OfficialNotice
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * Huella SHA-256 del Message-ID (o del correo entero si no lo trae).
     *
     * @ORM\Column(name="message_key", type="string", length=64, unique=true, options={"fixed": true})
     */
    private string $messageKey;

    /**
     * El organismo, como se llamaba en la configuración al llegar.
     *
     * @ORM\Column(type="string", length=80)
     */
    private string $agency;

    /**
     * Dirección del remitente.
     *
     * @ORM\Column(type="string", length=255)
     */
    private string $sender;

    /**
     * Cuándo se mandó el correo (su cabecera Date), o cuándo se recogió si no la trae.
     *
     * @ORM\Column(name="received_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $receivedAt;

    /**
     * @ORM\Column(name="created_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $createdAt;

    /**
     * @ORM\Column(name="reviewed_at", type="datetime_immutable", nullable=true)
     */
    private ?\DateTimeImmutable $reviewedAt = null;

    /**
     * Quién la marcó como revisada. Si se borra la cuenta, queda la fecha.
     *
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="reviewed_by_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $reviewedBy = null;

    /**
     * @param string             $messageKey Huella del correo, {@see self::keyFor()}.
     * @param string             $agency     Organismo que avisa.
     * @param string             $sender     Dirección del remitente.
     * @param \DateTimeImmutable $receivedAt Cuándo se mandó el correo.
     */
    public function __construct(string $messageKey, string $agency, string $sender, \DateTimeImmutable $receivedAt)
    {
        $this->messageKey = $messageKey;
        $this->agency = mb_substr($agency, 0, 80);
        $this->sender = mb_substr($sender, 0, 255);
        $this->receivedAt = $receivedAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * La huella con la que se reconoce un correo ya avisado: la del Message-ID, que es
     * lo que identifica un correo aunque llegue dos veces; si no lo trae, la del correo
     * entero.
     *
     * @param string|null $messageId  Message-ID del correo.
     * @param string      $rawMessage Correo completo, por si no lo trae.
     *
     * @return string SHA-256 en hexadecimal.
     */
    public static function keyFor(?string $messageId, string $rawMessage): string
    {
        $id = trim((string) $messageId, " \t\r\n<>");

        return hash('sha256', $id !== '' ? 'message-id:' . $id : 'raw:' . $rawMessage);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    public function getAgency(): string
    {
        return $this->agency;
    }

    public function getSender(): string
    {
        return $this->sender;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    /** Si alguien ya ha entrado en la sede a mirarla. */
    public function isReviewed(): bool
    {
        return $this->reviewedAt !== null;
    }

    /**
     * Alguien ha entrado en la sede y la ha mirado. Una vez revisada no se vuelve a
     * marcar: queda quien lo hizo primero.
     *
     * @param User               $by Quién la revisó.
     * @param \DateTimeImmutable $at Cuándo.
     *
     * @return bool false si ya estaba revisada (no cambia nada).
     */
    public function markReviewed(User $by, \DateTimeImmutable $at): bool
    {
        if ($this->isReviewed()) {
            return false;
        }
        $this->reviewedAt = $at;
        $this->reviewedBy = $by;

        return true;
    }
}
