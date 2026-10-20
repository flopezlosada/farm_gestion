<?php

namespace App\Entity;

use App\Service\Staff\WorkMonth;
use Doctrine\ORM\Mapping as ORM;

/**
 * La aprobación del registro de jornada de un trabajador para un mes. Desde que
 * se aprueba, ese mes no se toca: ni fichajes nuevos, ni horas corregidas, ni
 * anulaciones, ni ausencias que lo cubran. Es lo que da valor a la aprobación —
 * si se pudiera seguir cambiando por debajo, el resumen que se entregó y el
 * registro dejarían de coincidir sin que nadie lo viera.
 *
 * Si hay que corregir algo, se REABRE: queda escrito quién y cuándo, y hay que
 * volver a aprobarlo, que es otra fila. Así cada fila cuenta una aprobación
 * entera y el historial no se pierde. Vale lo que diga la más reciente. Mismo
 * patrón que {@see AccountantDelivery} en contabilidad.
 *
 * @ORM\Table(name="work_month_close", indexes={
 *     @ORM\Index(name="idx_work_month_close_month", columns={"worker_id", "year", "month"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\WorkMonthCloseRepository")
 */
class WorkMonthClose
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="Worker")
     * @ORM\JoinColumn(name="worker_id", nullable=false, onDelete="CASCADE")
     */
    private Worker $worker;

    /** @ORM\Column(type="smallint") */
    private int $year;

    /** @ORM\Column(type="smallint") */
    private int $month;

    /** @ORM\Column(name="approved_at", type="datetime_immutable") */
    private \DateTimeImmutable $approvedAt;

    /**
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="approved_by_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $approvedBy;

    /** @ORM\Column(name="reopened_at", type="datetime_immutable", nullable=true) */
    private ?\DateTimeImmutable $reopenedAt = null;

    /**
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="reopened_by_id", nullable=true, onDelete="SET NULL")
     */
    private ?User $reopenedBy = null;

    /**
     * @param Worker             $worker Trabajador cuyo mes se aprueba.
     * @param WorkMonth          $month  El mes aprobado.
     * @param User|null          $by     Quién lo aprueba.
     * @param \DateTimeImmutable $at     Cuándo.
     */
    public function __construct(Worker $worker, WorkMonth $month, ?User $by, \DateTimeImmutable $at)
    {
        $this->worker = $worker;
        $this->year = $month->year;
        $this->month = $month->month;
        $this->approvedBy = $by;
        $this->approvedAt = $at;
    }

    /**
     * Reabre el mes para corregirlo. La aprobación deja de valer: habrá que
     * volver a aprobarlo.
     *
     * @param User|null          $by Quién lo reabre.
     * @param \DateTimeImmutable $at Cuándo.
     * @return void
     *
     * @throws \LogicException Si ya estaba reabierta.
     */
    public function reopen(?User $by, \DateTimeImmutable $at): void
    {
        if ($this->reopenedAt !== null) {
            throw new \LogicException('Este mes ya está reabierto.');
        }
        $this->reopenedAt = $at;
        $this->reopenedBy = $by;
    }

    /**
     * Si esta aprobación sigue valiendo, es decir, si el mes está cerrado.
     *
     * @return bool
     */
    public function isStanding(): bool
    {
        return $this->reopenedAt === null;
    }

    /**
     * @return WorkMonth
     */
    public function getMonth(): WorkMonth
    {
        return WorkMonth::of($this->year, $this->month);
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return Worker
     */
    public function getWorker(): Worker
    {
        return $this->worker;
    }

    /**
     * @return \DateTimeImmutable
     */
    public function getApprovedAt(): \DateTimeImmutable
    {
        return $this->approvedAt;
    }

    /**
     * @return User|null
     */
    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    /**
     * @return \DateTimeImmutable|null
     */
    public function getReopenedAt(): ?\DateTimeImmutable
    {
        return $this->reopenedAt;
    }

    /**
     * @return User|null
     */
    public function getReopenedBy(): ?User
    {
        return $this->reopenedBy;
    }
}
