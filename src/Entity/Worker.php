<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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
#[UniqueEntity(fields: ['partner'], message: 'Esa ficha de socix ya es de otro trabajador.')]
class Worker
{
    /** Jornada completa. */
    public const WORKDAY_FULL_TIME = 'full_time';
    /** Jornada a tiempo parcial (art. 12 ET): su resumen mensual se le entrega por obligación legal. */
    public const WORKDAY_PART_TIME = 'part_time';

    /** Tipos de jornada con su etiqueta para pantalla y PDF. */
    public const WORKDAY_TYPES = [
        self::WORKDAY_FULL_TIME => 'Jornada completa',
        self::WORKDAY_PART_TIME => 'Tiempo parcial',
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
     * (no hay fichajes legítimos anteriores; ver también
     * {@see self::$recordingStartDate}) y la base para prorratear las vacaciones
     * del primer año.
     *
     * @var \DateTimeImmutable|null
     * @ORM\Column(name="hire_date", type="date_immutable")
     */
    #[Assert\NotNull]
    private $hireDate;

    /**
     * Desde cuándo se lleva su registro de jornada en esta aplicación. Para quien
     * ya trabajaba cuando se empezó a fichar aquí: los días entre el alta y esta
     * fecha no son huecos (no hay registro en la app que pedir), pero tampoco se
     * puede mover el alta, que es la fecha real del contrato y sostiene las
     * vacaciones del primer año. Null = desde el alta.
     *
     * @var \DateTimeImmutable|null
     * @ORM\Column(name="recording_start_date", type="date_immutable", nullable=true)
     */
    private $recordingStartDate;

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
     * Tipo de jornada del contrato. Decide una obligación legal: a quien trabaja a
     * tiempo parcial hay que entregarle cada mes el resumen de sus horas junto con
     * la nómina (art. 12.4.c ET), y si no se hace se presume que el contrato es a
     * jornada completa.
     *
     * Admite null sólo por los trabajadores dados de alta antes de existir el
     * campo: no se sabe qué contrato tienen y adivinarlo sería peor (un parcial
     * tomado por completo se quedaría sin su resumen sin que nadie se enterase).
     * El formulario lo exige, y la pantalla de cierre del mes avisa de quien lo
     * tenga sin definir.
     *
     * @var string|null
     * @ORM\Column(name="workday_type", type="string", length=20, nullable=true)
     */
    #[Assert\NotNull(message: 'Indica el tipo de jornada del contrato.')]
    #[Assert\Choice(choices: [self::WORKDAY_FULL_TIME, self::WORKDAY_PART_TIME])]
    private $workdayType;

    /**
     * Último día que trabaja en la asociación (incluido), o null mientras sigue.
     * Un trabajador que se va NUNCA se borra: su registro de jornada debe
     * conservarse 4 años (por eso tampoco hay borrado en cascada hacia
     * {@see TimeEntry}). De baja = conserva histórico pero no puede fichar.
     *
     * Es la única verdad de si sigue empleado ({@see self::isActive()} se deriva
     * de aquí): un «activo» guardado aparte podría decir otra cosa que la fecha.
     *
     * @var \DateTimeImmutable|null
     * @ORM\Column(name="leave_date", type="date_immutable", nullable=true)
     */
    private $leaveDate;

    /**
     * Su ficha de socix, si recibe cesta. Mientras trabaja su cesta es gratis; tras
     * la baja, según {@see self::$basketAfterLeave}.
     *
     * Va aparte del {@see User} aunque la misma persona pueda tener las dos facetas
     * en su cuenta: esa cuenta es el ACCESO para fichar, y se retira al irse
     * ({@see \App\Security\WorkerUserProvisioner::revokeAccess()}), justo cuando
     * más falta hace saber de quién es la cesta. Tampoco todo trabajador con cesta
     * tiene cuenta.
     *
     * @var Partner|null
     * @ORM\OneToOne(targetEntity="Partner")
     * @ORM\JoinColumn(name="partner_id", referencedColumnName="id", nullable=true, unique=true)
     */
    private $partner;

    /**
     * Qué se hace con su cesta tras la baja. Null = nadie lo ha decidido todavía (o
     * sigue trabajando): con baja y cesta, la cuota avisa y no se cobra.
     *
     * @var WorkerLeaveBasket|null
     * @ORM\Column(name="basket_after_leave", type="string", length=10, nullable=true, enumType=WorkerLeaveBasket::class)
     */
    private $basketAfterLeave;

    /**
     * Si se le regala la cesta tras la baja, hasta qué día (incluido). Null = sin
     * fin. Sólo tiene sentido con {@see WorkerLeaveBasket::Gift}.
     *
     * @var \DateTimeImmutable|null
     * @ORM\Column(name="basket_gift_until", type="date_immutable", nullable=true)
     */
    private $basketGiftUntil;

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
     * @return \DateTimeImmutable|null Desde cuándo se registra su jornada en la app; null = desde el alta.
     */
    public function getRecordingStartDate(): ?\DateTimeImmutable
    {
        return $this->recordingStartDate;
    }

    /**
     * @param \DateTimeImmutable|null $recordingStartDate Null = desde el alta.
     * @return self
     */
    public function setRecordingStartDate(?\DateTimeImmutable $recordingStartDate): self
    {
        $this->recordingStartDate = $recordingStartDate;

        return $this;
    }

    /**
     * Primer día en que se le exige registro de jornada: el más tardío entre el
     * alta y el inicio del registro en la app. Antes de ese día no hay huecos.
     *
     * @return \DateTimeImmutable|null Null sólo si no tiene alta (ficha incompleta).
     */
    public function getRecordFloor(): ?\DateTimeImmutable
    {
        if ($this->hireDate === null || $this->recordingStartDate === null) {
            return $this->hireDate;
        }

        return max($this->hireDate, $this->recordingStartDate);
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
     * @return string|null Uno de WORKDAY_*, o null si está sin definir.
     */
    public function getWorkdayType(): ?string
    {
        return $this->workdayType;
    }

    /**
     * @param string|null $workdayType Uno de WORKDAY_*.
     * @return self
     */
    public function setWorkdayType(?string $workdayType): self
    {
        $this->workdayType = $workdayType;

        return $this;
    }

    /**
     * Etiqueta del tipo de jornada, o null si está sin definir.
     *
     * @return string|null
     */
    public function getWorkdayTypeLabel(): ?string
    {
        return self::WORKDAY_TYPES[$this->workdayType] ?? null;
    }

    /**
     * ¿Trabaja a tiempo parcial? Es lo que decide si se le manda el resumen del mes.
     *
     * @return bool
     */
    public function isPartTime(): bool
    {
        return $this->workdayType === self::WORKDAY_PART_TIME;
    }

    /**
     * ¿Sigue empleado hoy?
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->isActiveOn(self::today());
    }

    /**
     * Hoy en Madrid, explícito como en {@see TimeEntry}: el corte de la baja no debe
     * depender de la zona horaria con que arranque PHP (en UTC, de 00:00 a 02:00
     * seguiría siendo ayer y quien ya se fue podría fichar).
     *
     * @return \DateTimeImmutable
     */
    public static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'));
    }

    /**
     * ¿Seguía en la asociación ese día, es decir, aún no se había ido? Sólo mira la
     * baja, como siempre hizo «activo»: alguien con el alta la semana que viene ya está
     * activo (y el registro de jornada lo corta por su cuenta en el alta).
     *
     * @param \DateTimeInterface $day
     * @return bool
     */
    public function isActiveOn(\DateTimeInterface $day): bool
    {
        return $this->leaveDate === null || $day->format('Y-m-d') <= $this->leaveDate->format('Y-m-d');
    }

    /**
     * @return \DateTimeImmutable|null Último día que trabaja; null mientras sigue.
     */
    public function getLeaveDate(): ?\DateTimeImmutable
    {
        return $this->leaveDate;
    }

    /**
     * @param \DateTimeImmutable|null $leaveDate Último día que trabaja, incluido.
     * @return self
     */
    public function setLeaveDate(?\DateTimeImmutable $leaveDate): self
    {
        $this->leaveDate = $leaveDate;

        return $this;
    }

    /**
     * @return Partner|null Su ficha de socix, si recibe cesta.
     */
    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    /**
     * @param Partner|null $partner
     * @return self
     */
    public function setPartner(?Partner $partner): self
    {
        $this->partner = $partner;

        return $this;
    }

    /**
     * @return WorkerLeaveBasket|null Null = sin decidir.
     */
    public function getBasketAfterLeave(): ?WorkerLeaveBasket
    {
        return $this->basketAfterLeave;
    }

    /**
     * Decide qué pasa con su cesta tras la baja. Pagarla no tiene «hasta»: si se
     * elige, la fecha del regalo se borra para que no quede un regalo a medias.
     *
     * @param WorkerLeaveBasket|null $basketAfterLeave Null = sin decidir.
     * @return self
     */
    public function setBasketAfterLeave(?WorkerLeaveBasket $basketAfterLeave): self
    {
        $this->basketAfterLeave = $basketAfterLeave;
        if ($basketAfterLeave !== WorkerLeaveBasket::Gift) {
            $this->basketGiftUntil = null;
        }

        return $this;
    }

    /**
     * @return \DateTimeImmutable|null Último día del regalo; null = sin fin (o no hay regalo).
     */
    public function getBasketGiftUntil(): ?\DateTimeImmutable
    {
        return $this->basketGiftUntil;
    }

    /**
     * @param \DateTimeImmutable|null $basketGiftUntil Último día del regalo, incluido; null = sin fin.
     * @return self
     */
    public function setBasketGiftUntil(?\DateTimeImmutable $basketGiftUntil): self
    {
        $this->basketGiftUntil = $this->basketAfterLeave === WorkerLeaveBasket::Gift ? $basketGiftUntil : null;

        return $this;
    }

    /**
     * Cómo va su cesta la semana de ese día: gratis (trabajaba, o se le regala tras la
     * baja), sin decidir (de baja y nadie ha dicho qué hacer) o null = la paga como
     * cualquiera (antes del alta, o tras la baja si se decidió así o acabó el regalo).
     *
     * Se mira el día del reparto (el viernes del ciclo semanal): quien entra el
     * miércoles ya tiene gratis la cesta de ese viernes, y quien se va el miércoles
     * ya no.
     *
     * @param \DateTimeInterface $day
     * @return WorkerBasketWeek|null
     */
    public function basketWeek(\DateTimeInterface $day): ?WorkerBasketWeek
    {
        if ($this->hireDate === null || $day->format('Y-m-d') < $this->hireDate->format('Y-m-d')) {
            return null;
        }
        if ($this->isActiveOn($day)) {
            return WorkerBasketWeek::Free;
        }

        return match ($this->basketAfterLeave) {
            null => WorkerBasketWeek::Undecided,
            WorkerLeaveBasket::Paid => null,
            WorkerLeaveBasket::Gift => $this->basketGiftUntil === null || $day->format('Y-m-d') <= $this->basketGiftUntil->format('Y-m-d')
                ? WorkerBasketWeek::Free
                : null,
        };
    }

    /**
     * Las fechas tienen que encajar: la baja no antes del alta, y el regalo no acaba
     * antes de la baja. Sin baja no hay nada que decidir sobre la cesta.
     *
     * @param ExecutionContextInterface $context
     * @return void
     */
    #[Assert\Callback]
    public function validateLeave(ExecutionContextInterface $context): void
    {
        if ($this->leaveDate !== null && $this->hireDate !== null && $this->leaveDate < $this->hireDate) {
            $context->buildViolation('La baja no puede ser anterior al alta.')->atPath('leaveDate')->addViolation();
        }
        if ($this->leaveDate === null && $this->basketAfterLeave !== null) {
            $context->buildViolation('Sólo se decide qué pasa con la cesta cuando hay fecha de baja.')->atPath('basketAfterLeave')->addViolation();
        }
        if ($this->leaveDate !== null && $this->basketGiftUntil !== null && $this->basketGiftUntil < $this->leaveDate) {
            $context->buildViolation('El regalo no puede acabar antes de la baja.')->atPath('basketGiftUntil')->addViolation();
        }
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
