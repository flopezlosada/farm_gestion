<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un fichaje: un único evento de entrada o salida de la jornada. El registro de
 * jornada es un FLUJO DE EVENTOS, no una fila "entrada+salida": modelar pares en
 * una fila se rompe en cuanto alguien sale a comer y vuelve (dos entradas, dos
 * salidas) o se olvida de fichar la salida. Los tramos trabajados y las horas se
 * CALCULAN al vuelo emparejando eventos; no se persiste ningún total.
 *
 * INMUTABILIDAD (lo que da validez legal): {@see TimeEntry::$occurredAt} y
 * {@see TimeEntry::$recordedAt} NO se tocan jamás una vez creada la fila. La app
 * no expone editar ni borrar un fichaje. Corregir = ANULAR + reinsertar: el
 * evento erróneo se marca anulado (con quién, cuándo y por qué) y se crea uno
 * nuevo con el dato correcto. Un único mecanismo cubre tanto "me equivoqué de
 * hora" como "fiché sin querer". La única mutación admitida sobre una fila es
 * fijar su anulación una sola vez ({@see TimeEntry::void()}).
 *
 * ZONA HORARIA: ambos sellos se guardan y se pintan en hora de Madrid (decisión
 * 2026-06-20). El PHP del proyecto corre en Europe/Madrid; guardar UTC y dejar
 * que Doctrine hidratara en Madrid desalineaba los apuntes (se fichaba 09:00 y
 * se mostraba 07:00). Para huso único + registro legal en hora local es más
 * simple y correcto operar todo en Madrid; el caso del cambio de hora (nadie
 * ficha a las 2:30 del cambio DST) es despreciable.
 *
 * FICHAJE TARDÍO: el desfase entre `occurredAt` (cuándo dice que ocurrió) y
 * `recordedAt` (cuándo lo puso el servidor) marca por sí solo los apuntes
 * declarados tarde, sin campo extra ({@see TimeEntry::isLate()}). Es lo que hace
 * imposible disfrazar de fichaje en tiempo real un relleno del pasado.
 *
 * @ORM\Table(name="time_entry")
 * @ORM\Entity(repositoryClass="App\Repository\TimeEntryRepository")
 * @ORM\HasLifecycleCallbacks
 */
class TimeEntry
{
    /** Entrada: comienza un tramo de jornada. */
    public const TYPE_IN = 'in';
    /** Salida: cierra el tramo de jornada abierto. */
    public const TYPE_OUT = 'out';

    /** Tipos válidos de evento. */
    public const TYPES = [
        self::TYPE_IN,
        self::TYPE_OUT,
    ];

    /** Lo fichó la propia persona desde su panel (la vía normal y la que da fe). */
    public const SOURCE_SELF = 'self';
    /** Lo registró el supervisor en nombre del trabajador (corrección/incidencia). */
    public const SOURCE_SUPERVISOR = 'supervisor';

    /** Orígenes válidos del fichaje. */
    public const SOURCES = [
        self::SOURCE_SELF,
        self::SOURCE_SUPERVISOR,
    ];

    /**
     * @var int|null
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * Trabajador al que pertenece el fichaje. Sin borrado en cascada a propósito:
     * el registro debe sobrevivir 4 años aunque el trabajador se dé de baja (se
     * marca inactivo, no se borra), así que la FK protege contra borrarlo si
     * tiene fichajes.
     *
     * @var Worker|null
     * @ORM\ManyToOne(targetEntity="Worker", inversedBy="timeEntries")
     * @ORM\JoinColumn(name="worker_id", referencedColumnName="id", nullable=false)
     */
    #[Assert\NotNull]
    private $worker;

    /**
     * @var string
     * @ORM\Column(name="type", type="string", length=10)
     */
    #[Assert\Choice(choices: TimeEntry::TYPES)]
    private $type;

    /**
     * Instante del fichaje (hora de Madrid): la hora que cuenta como jornada. Inmutable.
     *
     * @var \DateTimeImmutable|null
     * @ORM\Column(name="occurred_at", type="datetime_immutable")
     */
    #[Assert\NotNull]
    private $occurredAt;

    /**
     * Sello de inserción del servidor (hora de Madrid): cuándo entró de verdad en
     * la BBDD. Lo estampa {@see TimeEntry::stampRecordedAt()} en el PrePersist, en
     * Madrid explícito (NO vía Gedmo, que tomaría la zona local de PHP de forma
     * implícita), y NO se vuelve a tocar. Es la prueba de que el apunte no se
     * manipuló y el que delata los fichajes tardíos.
     *
     * @var \DateTimeImmutable|null
     * @ORM\Column(name="recorded_at", type="datetime_immutable")
     */
    private $recordedAt;

    /**
     * Login que creó el evento (la propia persona o el supervisor). Parte de la
     * traza de auditoría. Nullable con SET NULL: si algún día se borra ese User,
     * el fichaje sobrevive (la conservación legal manda sobre la traza), aunque
     * pierda el autor.
     *
     * @var User|null
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="author_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    private $author;

    /**
     * @var string
     * @ORM\Column(name="source", type="string", length=20)
     */
    #[Assert\Choice(choices: TimeEntry::SOURCES)]
    private $source = self::SOURCE_SELF;

    /**
     * Instante de anulación (hora de Madrid), null si el fichaje sigue vigente. La anulación
     * es la ÚNICA mutación admitida sobre una fila ya creada, y se hace una sola
     * vez. El registro vigente = fichajes no anulados.
     *
     * @var \DateTimeImmutable|null
     * @ORM\Column(name="voided_at", type="datetime_immutable", nullable=true)
     */
    private $voidedAt;

    /**
     * Login que anuló el fichaje. SET NULL por la misma razón que el autor.
     *
     * @var User|null
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="voided_by_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    private $voidedBy;

    /**
     * Motivo de la anulación (queda en el registro como traza).
     *
     * @var string|null
     * @ORM\Column(name="void_reason", type="string", length=255, nullable=true)
     */
    private $voidReason;

    /**
     * Justificación opcional al crear el fichaje (p. ej. "olvidé fichar la salida
     * de ayer" en un apunte tardío).
     *
     * @var string|null
     * @ORM\Column(name="note", type="string", length=255, nullable=true)
     */
    private $note;

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return Worker|null
     */
    public function getWorker(): ?Worker
    {
        return $this->worker;
    }

    /**
     * @param Worker|null $worker
     * @return self
     */
    public function setWorker(?Worker $worker): self
    {
        $this->worker = $worker;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * @param string $type
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return \DateTimeImmutable|null
     */
    public function getOccurredAt(): ?\DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * @param \DateTimeImmutable $occurredAt
     * @return self
     */
    public function setOccurredAt(\DateTimeImmutable $occurredAt): self
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    /**
     * @return \DateTimeImmutable|null
     */
    public function getRecordedAt(): ?\DateTimeImmutable
    {
        return $this->recordedAt;
    }

    /**
     * Estampa el sello de inserción en hora de Madrid al persistir, si no estaba ya puesto.
     * Lo pone el servidor (no el cliente) y es inmutable a partir de ahí: es la
     * prueba de integridad del fichaje.
     *
     * @ORM\PrePersist
     * @return void
     */
    public function stampRecordedAt(): void
    {
        if ($this->recordedAt === null) {
            $this->recordedAt = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid'));
        }
    }

    /**
     * @return User|null
     */
    public function getAuthor(): ?User
    {
        return $this->author;
    }

    /**
     * @param User|null $author
     * @return self
     */
    public function setAuthor(?User $author): self
    {
        $this->author = $author;

        return $this;
    }

    /**
     * @return string
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * @param string $source
     * @return self
     */
    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * ¿Es entrada?
     *
     * @return bool
     */
    public function isIn(): bool
    {
        return $this->type === self::TYPE_IN;
    }

    /**
     * ¿Está anulado este fichaje? Si lo está, no cuenta en el registro vigente.
     *
     * @return bool
     */
    public function isVoided(): bool
    {
        return $this->voidedAt !== null;
    }

    /**
     * ¿Cuenta este fichaje en el registro vigente? (no anulado).
     *
     * @return bool
     */
    public function isEffective(): bool
    {
        return $this->voidedAt === null;
    }

    /**
     * ¿Se declaró tarde? Cierto cuando el fichaje se registró en un día natural
     * (Madrid) posterior al que dice haber ocurrido. Es la señal que destaca los
     * apuntes del pasado en el panel de huecos, sin necesitar un campo extra.
     *
     * @return bool
     */
    public function isLate(): bool
    {
        if ($this->occurredAt === null || $this->recordedAt === null) {
            return false;
        }

        return $this->recordedAt->format('Y-m-d') > $this->occurredAt->format('Y-m-d');
    }

    /**
     * @return \DateTimeImmutable|null
     */
    public function getVoidedAt(): ?\DateTimeImmutable
    {
        return $this->voidedAt;
    }

    /**
     * @return User|null
     */
    public function getVoidedBy(): ?User
    {
        return $this->voidedBy;
    }

    /**
     * @return string|null
     */
    public function getVoidReason(): ?string
    {
        return $this->voidReason;
    }

    /**
     * @return string|null
     */
    public function getNote(): ?string
    {
        return $this->note;
    }

    /**
     * @param string|null $note
     * @return self
     */
    public function setNote(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    /**
     * Anula este fichaje. Es la ÚNICA mutación admitida sobre una fila ya
     * existente y debe hacerse una sola vez (corregir un dato = anular este +
     * crear uno nuevo, nunca editar el original). Deja la traza completa de quién,
     * cuándo y por qué. Los sellos `occurredAt`/`recordedAt` originales se
     * conservan intactos.
     *
     * @param User                $by     Login que anula.
     * @param string              $reason Motivo de la anulación.
     * @param \DateTimeImmutable  $at     Instante de la anulación (hora de Madrid).
     * @return self
     */
    public function void(User $by, string $reason, \DateTimeImmutable $at): self
    {
        $this->voidedAt = $at;
        $this->voidedBy = $by;
        $this->voidReason = $reason;

        return $this;
    }
}
