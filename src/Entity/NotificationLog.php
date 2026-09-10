<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Una línea por aviso que la aplicación ha intentado entregar a alguien, por el
 * canal que sea. Es la BITÁCORA: se escribe siempre, salga bien o mal, y no se
 * modifica nunca.
 *
 * NO ES {@see EmittedEffect}, aunque se parezcan, y la diferencia importa. Aquel
 * es el guardián de idempotencia: guarda "este aviso ya se produjo" para no
 * repetirlo, tiene una fila por aviso —el reenvío no añade otra— y **retira el
 * apunte cuando el envío falla**, precisamente para poder reintentarlo. Con esas
 * tres reglas es imposible responder "¿qué se mandó y qué pasó?": los fallos no
 * dejan rastro y los reenvíos no se ven. Esta tabla contesta eso; la otra sigue
 * decidiendo qué no se repite.
 *
 * Nace de un caso real: una socia avisó de que no recibía el correo de su cesta
 * y averiguar si se le había mandado costó media docena de consultas a mano
 * contra la base de producción, porque en la web no había dónde mirarlo.
 *
 * SE ESCRIBE EN EL TRANSPORTE, no en cada emisor ({@see \App\Mailer\RecordingMailer}
 * para el correo, {@see \App\Service\Push\PushSender} para el móvil). Es lo que
 * hace que valga para TODOS los avisos, incluidos los que nadie instrumentó y
 * los que se añadan mañana: quien manda un correo no tiene que acordarse de
 * apuntarlo.
 *
 * @ORM\Table(
 *     name="notification_log",
 *     indexes={
 *         @ORM\Index(name="IDX_notif_log_sent", columns={"sent_at"}),
 *         @ORM\Index(name="IDX_notif_log_partner", columns={"partner_id", "sent_at"}),
 *         @ORM\Index(name="IDX_notif_log_kind", columns={"kind", "sent_at"}),
 *         @ORM\Index(name="IDX_notif_log_target", columns={"target"}),
 *         @ORM\Index(name="IDX_notif_log_run", columns={"cron_run_id"})
 *     }
 * )
 * @ORM\Entity(repositoryClass="App\Repository\NotificationLogRepository")
 */
class NotificationLog
{
    /** Correo electrónico. */
    public const CHANNEL_EMAIL = 'email';

    /** Aviso al móvil (push del navegador). */
    public const CHANNEL_PUSH = 'push';

    /** Entregado al servicio de salida sin error. */
    public const STATUS_SENT = 'sent';

    /**
     * Se intentó y reventó (servidor de correo caído, dirección rechazada,
     * fallo de cifrado del push). El motivo queda en {@see self::$error}.
     */
    public const STATUS_FAILED = 'failed';

    /**
     * No se llegó a mandar porque el interruptor general de envíos estaba
     * apagado. Se registra en vez de callar: si no, un maestro apagado se ve
     * igual que "la tarea no encontró a nadie", que es el equívoco que ya costó
     * dos semanas de diagnóstico con el reloj caído.
     */
    public const STATUS_DISCARDED = 'discarded';

    /** Tope de caracteres del motivo de error: la columna no es un log. */
    public const ERROR_MAX_LENGTH = 255;

    /**
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private ?int $id = null;

    /**
     * Instante del intento, no de la entrega: un correo aceptado por el
     * servidor de salida puede llegar minutos después, o no llegar.
     *
     * @ORM\Column(name="sent_at", type="datetime_immutable")
     */
    private \DateTimeImmutable $sentAt;

    /**
     * @ORM\Column(name="channel", type="string", length=10)
     */
    private string $channel = self::CHANNEL_EMAIL;

    /**
     * Qué aviso es, en la jerga del sistema: "pickup_reminder",
     * "delivery_confirmation", "volunteer_call"… En el correo se deduce del
     * nombre de la plantilla, así que sale gratis y no hay que tocar a quien
     * envía; en el push lo pone quien lo dispara.
     *
     * @ORM\Column(name="kind", type="string", length=60)
     */
    private string $kind = '';

    /**
     * Asunto del correo o título del aviso al móvil. Es lo que hace legible el
     * listado sin abrir nada: "Recordatorio: tu cesta…" dice más que la clave.
     *
     * @ORM\Column(name="subject", type="string", length=255, nullable=true)
     */
    private ?string $subject = null;

    /**
     * A quién iba, cuando se sabe. El push conoce a la persona; el correo solo
     * conoce la dirección, y resolverla a socix en el momento del envío
     * costaría una consulta por correo en la tarea que más gente toca. Se cruza
     * al consultar, por {@see self::$target}.
     *
     * @ORM\ManyToOne(targetEntity="Partner")
     * @ORM\JoinColumn(name="partner_id", nullable=true, onDelete="SET NULL")
     */
    private ?Partner $partner = null;

    /**
     * Dónde se entregó, tal cual: la dirección de correo, o cuántos navegadores
     * recibieron el push. Se guarda el valor literal del momento del envío
     * porque es la verdad de lo que pasó: si mañana cambia el correo de la
     * ficha, el registro sigue diciendo a dónde fue.
     *
     * @ORM\Column(name="target", type="string", length=255)
     */
    private string $target = '';

    /**
     * @ORM\Column(name="status", type="string", length=12)
     */
    private string $status = self::STATUS_SENT;

    /**
     * @ORM\Column(name="error", type="string", length=255, nullable=true)
     */
    private ?string $error = null;

    /**
     * Tarea del planificador que lo originó, si lo originó una
     * ({@see \App\Service\AppSettings::CRONS}). Los avisos que salen de una
     * acción de alguien en la web no tienen tarea y lo dejan a null.
     *
     * @ORM\Column(name="task_key", type="string", length=100, nullable=true)
     */
    private ?string $taskKey = null;

    /**
     * Ejecución concreta que lo mandó. Es lo que permite ir de "esta tarea corrió
     * el martes" a "y éstos son los 31 avisos que salieron de ahí", que es la
     * pregunta que de verdad se hace quien está diagnosticando.
     *
     * @ORM\ManyToOne(targetEntity="CronRun")
     * @ORM\JoinColumn(name="cron_run_id", nullable=true, onDelete="SET NULL")
     */
    private ?CronRun $cronRun = null;

    public function __construct()
    {
        $this->sentAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(\DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): self
    {
        $this->channel = $channel;

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

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): self
    {
        $this->subject = $subject === null ? null : mb_substr($subject, 0, 255);

        return $this;
    }

    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    public function setPartner(?Partner $partner): self
    {
        $this->partner = $partner;

        return $this;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function setTarget(string $target): self
    {
        $this->target = mb_substr($target, 0, 255);

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Recorta el motivo a lo que cabe: los mensajes de excepción de un
     * transporte de correo traen a veces la traza entera.
     */
    public function setError(?string $error): self
    {
        $this->error = $error === null ? null : mb_substr($error, 0, self::ERROR_MAX_LENGTH);

        return $this;
    }

    public function getTaskKey(): ?string
    {
        return $this->taskKey;
    }

    public function setTaskKey(?string $taskKey): self
    {
        $this->taskKey = $taskKey;

        return $this;
    }

    public function getCronRun(): ?CronRun
    {
        return $this->cronRun;
    }

    public function setCronRun(?CronRun $cronRun): self
    {
        $this->cronRun = $cronRun;

        return $this;
    }

    /** ¿Salió sin error? */
    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }
}
