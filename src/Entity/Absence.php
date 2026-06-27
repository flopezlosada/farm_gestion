<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ausencia de un {@see Worker}: vacaciones, baja, permiso. NO es un fichaje: el
 * tiempo no trabajado no se modela como entrada/salida inventada, va aparte para
 * no ensuciar el registro de jornada (que sólo contiene tiempo realmente
 * trabajado). En la vista del día conviven ausencias y fichajes, pero son cosas
 * distintas.
 *
 * A diferencia de los fichajes (que se editan sin aprobación porque son hechos
 * pasados que el trabajador declara), las vacaciones SÍ llevan flujo de
 * aprobación: son una petición a futuro que el supervisor concede, y de lo
 * APROBADO depende el saldo de vacaciones.
 *
 * El saldo (días/año − días de vacaciones aprobadas del año) se calcula al
 * vuelo, no se persiste.
 *
 * @ORM\Table(name="absence")
 * @ORM\Entity(repositoryClass="App\Repository\AbsenceRepository")
 */
class Absence
{
    /** Vacaciones: las únicas que descuentan del saldo anual. */
    public const TYPE_VACATION = 'vacation';
    /** Baja médica. */
    public const TYPE_SICK_LEAVE = 'sick_leave';
    /** Permiso retribuido (mudanza, médico, etc.). */
    public const TYPE_PERMIT = 'permit';
    /** Cualquier otra ausencia. */
    public const TYPE_OTHER = 'other';

    /** Tipos válidos de ausencia. */
    public const TYPES = [
        self::TYPE_VACATION,
        self::TYPE_SICK_LEAVE,
        self::TYPE_PERMIT,
        self::TYPE_OTHER,
    ];

    /** Solicitada, pendiente de que el supervisor decida. */
    public const STATUS_REQUESTED = 'requested';
    /** Aprobada: la vacación cuenta contra el saldo. */
    public const STATUS_APPROVED = 'approved';
    /** Rechazada por el supervisor. */
    public const STATUS_REJECTED = 'rejected';
    /** Cancelada (por el trabajador o el supervisor). */
    public const STATUS_CANCELLED = 'cancelled';

    /** Estados válidos del ciclo de vida de la ausencia. */
    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** Resultado de {@see Absence::cancelAsOf()}: ya había terminado, no se cancela. */
    public const CANCEL_TOO_LATE = 'too_late';
    /** Resultado de {@see Absence::cancelAsOf()}: no había empezado, cancelada entera. */
    public const CANCEL_FULL = 'full';
    /** Resultado de {@see Absence::cancelAsOf()}: en curso, truncada a hoy. */
    public const CANCEL_TRUNCATED = 'truncated';

    /**
     * @var int|null
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var Worker|null
     * @ORM\ManyToOne(targetEntity="Worker", inversedBy="absences")
     * @ORM\JoinColumn(name="worker_id", referencedColumnName="id", nullable=false)
     */
    #[Assert\NotNull]
    private $worker;

    /**
     * @var string
     * @ORM\Column(name="type", type="string", length=20)
     */
    #[Assert\Choice(choices: Absence::TYPES)]
    private $type = self::TYPE_VACATION;

    /**
     * Primer día de la ausencia (incluido).
     *
     * @var \DateTimeImmutable|null
     * @ORM\Column(name="start_date", type="date_immutable")
     */
    #[Assert\NotNull]
    private $startDate;

    /**
     * Último día de la ausencia (incluido). Intervalo cerrado [inicio, fin]: una
     * ausencia de un solo día tiene inicio = fin.
     *
     * @var \DateTimeImmutable|null
     * @ORM\Column(name="end_date", type="date_immutable")
     */
    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(propertyPath: "startDate", message: "La fecha de fin no puede ser anterior al inicio.")]
    private $endDate;

    /**
     * @var string
     * @ORM\Column(name="status", type="string", length=20)
     */
    #[Assert\Choice(choices: Absence::STATUSES)]
    private $status = self::STATUS_REQUESTED;

    /**
     * Login que aprobó (o decidió sobre) la ausencia. SET NULL para que la
     * ausencia sobreviva si se borra ese User.
     *
     * @var User|null
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="approved_by_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    private $approvedBy;

    /**
     * @var string|null
     * @ORM\Column(name="note", type="text", nullable=true)
     */
    private $note;

    /**
     * @var \DateTime
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created", type="datetime")
     */
    private $created;

    /**
     * @var \DateTime
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(name="updated", type="datetime")
     */
    private $updated;

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
     * @return string
     */
    public function getType(): string
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
    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    /**
     * @param \DateTimeImmutable $startDate
     * @return self
     */
    public function setStartDate(\DateTimeImmutable $startDate): self
    {
        $this->startDate = $startDate;

        return $this;
    }

    /**
     * @return \DateTimeImmutable|null
     */
    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    /**
     * @param \DateTimeImmutable $endDate
     * @return self
     */
    public function setEndDate(\DateTimeImmutable $endDate): self
    {
        $this->endDate = $endDate;

        return $this;
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @param string $status
     * @return self
     */
    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return User|null
     */
    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    /**
     * @param User|null $approvedBy
     * @return self
     */
    public function setApprovedBy(?User $approvedBy): self
    {
        $this->approvedBy = $approvedBy;

        return $this;
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
     * @return \DateTime|null
     */
    public function getCreated(): ?\DateTime
    {
        return $this->created;
    }

    /**
     * @return \DateTime|null
     */
    public function getUpdated(): ?\DateTime
    {
        return $this->updated;
    }

    /**
     * ¿Descuenta esta ausencia del saldo de vacaciones? Sólo las vacaciones
     * APROBADAS comen saldo: una solicitud sin aprobar, una baja o un permiso no.
     *
     * @return bool
     */
    public function countsAgainstVacationBalance(): bool
    {
        return $this->type === self::TYPE_VACATION && $this->status === self::STATUS_APPROVED;
    }

    /**
     * Nº de días naturales que abarca la ausencia, ambos extremos incluidos. El
     * cómputo en días LABORABLES (descontando findes y festivos) para el saldo
     * vive en el servicio, que es quien conoce el calendario.
     *
     * @return int
     */
    public function getCalendarDayCount(): int
    {
        if ($this->startDate === null || $this->endDate === null) {
            return 0;
        }

        return (int) $this->startDate->diff($this->endDate)->days + 1;
    }

    /**
     * Cancela la ausencia tomando como referencia el día de hoy, aplicando la
     * misma regla tanto si la cancela el trabajador como el supervisor:
     *
     *  - Si ya terminó (fin < hoy), no se toca nada: cancelarla "desharía" días
     *    ya disfrutados. Devuelve {@see Absence::CANCEL_TOO_LATE}.
     *  - Si aún no ha empezado (inicio > hoy), se cancela entera (estado
     *    CANCELLED). Devuelve {@see Absence::CANCEL_FULL}.
     *  - Si está en curso (inicio ≤ hoy ≤ fin), se TRUNCA a hoy: se conservan
     *    los días disfrutados hasta hoy incluido y se libera el resto. Devuelve
     *    {@see Absence::CANCEL_TRUNCATED}.
     *
     * Es lógica pura (solo muta el estado/fin de la propia ausencia); el
     * controller decide el flash y el flush según el resultado.
     *
     * @param \DateTimeImmutable $today Día de hoy (a medianoche, hora de Madrid).
     * @return string Una de las constantes Absence::CANCEL_*.
     */
    public function cancelAsOf(\DateTimeImmutable $today): string
    {
        // Sin fechas completas no hay nada que truncar ni cancelar con sentido:
        // se trata como "ya no se puede" para no mutar una ausencia malformada.
        if ($this->startDate === null || $this->endDate === null) {
            return self::CANCEL_TOO_LATE;
        }

        if ($this->endDate < $today) {
            return self::CANCEL_TOO_LATE;
        }

        if ($this->startDate !== null && $this->startDate > $today) {
            $this->status = self::STATUS_CANCELLED;

            return self::CANCEL_FULL;
        }

        $this->endDate = $today;

        return self::CANCEL_TRUNCATED;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->type, $this->status);
    }
}
