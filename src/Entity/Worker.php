<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Trabajador de la asociación: persona con relación laboral, sujeta a registro
 * de jornada (obligatorio por RD-ley 8/2019) y con gestión de vacaciones.
 *
 * Es una FACETA de la persona, igual que {@see Partner} (socix). Cuelga del
 * {@see User} (la identidad de login) por una OneToOne unidireccional cuyo dueño
 * es el User (columna `worker_id` en `fos_user`), exactamente como Partner. Por
 * eso una misma persona puede ser:
 *   - socix Y trabajador  → User con Partner y Worker (p. ej. Damaso),
 *   - solo trabajador     → User con Worker, sin Partner (p. ej. Sara González,
 *                           que no es socia aunque reciba cesta),
 *   - solo socix          → User con Partner, sin Worker (la mayoría).
 *
 * Tener Worker es lo que deriva {@see User::getRoles()} en ROLE_WORKER, igual
 * que tener Partner deriva ROLE_PARTNER. No se duplica el rol a mano.
 *
 * El nombre vive aquí (y no se reaprovecha del Partner) porque un trabajador
 * puede no ser socix y entonces no hay Partner del que sacarlo. Para quien es
 * ambas cosas se duplica el nombre; es un precio menor frente a tener una vía
 * única para todos.
 *
 * @ORM\Table(name="worker")
 * @ORM\Entity(repositoryClass="App\Repository\WorkerRepository")
 */
class Worker
{
    /**
     * @var int|null
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * Nombre completo tal cual. Un solo campo a propósito, como en {@see Helper}:
     * separar nombre/apellidos parte mal los nombres extranjeros.
     *
     * @var string
     * @ORM\Column(name="name", type="string", length=255)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private $name;

    /**
     * Fecha de alta laboral. Obligatoria: es el suelo del registro de jornada
     * (no hay fichajes legítimos anteriores) y la base para prorratear las
     * vacaciones del primer año.
     *
     * @var \DateTimeImmutable|null
     * @ORM\Column(name="hire_date", type="date_immutable")
     */
    #[Assert\NotNull]
    private $hireDate;

    /**
     * Días de vacaciones al año en jornadas LABORABLES (el convenio habitual son
     * 22 laborables ≈ 30 naturales). El saldo disponible NO se guarda: se calcula
     * al vuelo restando los días aprobados del año (misma filosofía que el resto
     * del proyecto: persistir sólo la verdad, derivar lo demás).
     *
     * @var int
     * @ORM\Column(name="annual_vacation_days", type="integer", options={"default": 22})
     */
    #[Assert\PositiveOrZero]
    private $annualVacationDays = 22;

    /**
     * ¿Sigue empleado? Un trabajador que se va se marca inactivo, NUNCA se borra:
     * su registro de jornada debe conservarse 4 años aunque ya no esté (por eso
     * tampoco hay borrado en cascada hacia {@see TimeEntry}). Inactivo = conserva
     * histórico pero no puede fichar.
     *
     * @var bool
     * @ORM\Column(name="active", type="boolean", options={"default": true})
     */
    private $active = true;

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
     * Fichajes de este trabajador. Inverso de {@see TimeEntry::$worker}.
     *
     * @var Collection<int, TimeEntry>
     * @ORM\OneToMany(targetEntity="TimeEntry", mappedBy="worker")
     */
    private $timeEntries;

    /**
     * Ausencias (vacaciones, bajas, permisos) de este trabajador. Inverso de
     * {@see Absence::$worker}.
     *
     * @var Collection<int, Absence>
     * @ORM\OneToMany(targetEntity="Absence", mappedBy="worker")
     */
    private $absences;

    /**
     * Inicializa las colecciones.
     */
    public function __construct()
    {
        $this->timeEntries = new ArrayCollection();
        $this->absences = new ArrayCollection();
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return self
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return \DateTimeImmutable|null
     */
    public function getHireDate(): ?\DateTimeImmutable
    {
        return $this->hireDate;
    }

    /**
     * @param \DateTimeImmutable $hireDate
     * @return self
     */
    public function setHireDate(\DateTimeImmutable $hireDate): self
    {
        $this->hireDate = $hireDate;

        return $this;
    }

    /**
     * @return int
     */
    public function getAnnualVacationDays(): int
    {
        return $this->annualVacationDays;
    }

    /**
     * @param int $annualVacationDays
     * @return self
     */
    public function setAnnualVacationDays(int $annualVacationDays): self
    {
        $this->annualVacationDays = $annualVacationDays;

        return $this;
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @param bool $active
     * @return self
     */
    public function setActive(bool $active): self
    {
        $this->active = $active;

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
     * @return Collection<int, TimeEntry>
     */
    public function getTimeEntries(): Collection
    {
        return $this->timeEntries;
    }

    /**
     * @return Collection<int, Absence>
     */
    public function getAbsences(): Collection
    {
        return $this->absences;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->name;
    }
}
