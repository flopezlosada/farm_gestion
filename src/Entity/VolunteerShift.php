<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un momento concreto en que una tarea de voluntariado se hace: el sábado 6 a
 * las 9:00. Es la unidad a la que se apunta la gente ({@see VolunteerSignup}).
 *
 * POR QUÉ EXISTE. Antes el momento vivía en la propia {@see VolunteerOffer}, así
 * que repetir un trabajo significaba duplicar su ficha entera por cada fecha.
 * Eso aguanta el reparto —cincuenta y dos al año— y se rompe con todo lo demás:
 * sacar al perro mañana y tarde son setecientas treinta fichas al año, y
 * corregir una errata en la explicación son setecientas treinta ediciones. La
 * tarea dice QUÉ se hace y cómo; el turno dice CUÁNDO. Una tarea, muchos turnos.
 *
 * NO CONFUNDIR CON {@see PartnerDeliveryShift}, que es el turno A/B de las
 * cestas quincenales y no tiene nada que ver: aquél dice en qué semana le toca
 * cesta a alguien, éste es una cita de trabajo con hora.
 *
 * PLAZAS Y HORAS SON OPCIONALES AQUÍ. Lo normal es que las mande la tarea; el
 * turno sólo las lleva cuando difieren ({@see getSlots()} y
 * {@see getCreditedMinutes()} resuelven la herencia). Así el reparto puede pedir
 * cuatro personas el sábado y dos el domingo sin partirse en dos tareas, y
 * "abrir el invernadero" no repite el mismo número en cien filas.
 *
 * SE ANULA, NO SE BORRA ({@see $cancelledAt}). Un festivo, una avería, un día
 * que no se hace: la fila se queda para que quien estuviera apuntado siga
 * pudiendo ver qué pasó, y para que el generador de turnos no lo vuelva a crear
 * en la siguiente pasada.
 *
 * @ORM\Table(name="volunteer_shift", uniqueConstraints={
 *     @ORM\UniqueConstraint(name="uniq_volunteer_shift_offer_start", columns={"offer_id", "starts_at"})
 * }, indexes={
 *     @ORM\Index(name="idx_volunteer_shift_starts_at", columns={"starts_at"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\VolunteerShiftRepository")
 */
class VolunteerShift
{
    /** La tarea está en borrador: este turno no se ve fuera de gestión. */
    public const PHASE_DRAFT = 'draft';

    /** Anulado (o la tarea entera lo está): no hay nada que hacer con él. */
    public const PHASE_CANCELLED = 'cancelled';

    /** La tarea está en pausa: el turno sigue ahí, pero no se pide gente. */
    public const PHASE_PAUSED = 'paused';

    /** Por llegar: el trabajo es llenar las plazas. */
    public const PHASE_OPEN = 'open';

    /** Empezó hoy mismo: el trabajo es pasar lista. */
    public const PHASE_TODAY = 'today';

    /** Pasó y falta gente por decir si fue: el trabajo es cerrarlo. */
    public const PHASE_TO_CLOSE = 'to_close';

    /** Pasó y está todo respondido: sólo queda consultar lo imputado. */
    public const PHASE_CLOSED = 'closed';

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\VolunteerOffer", inversedBy="shifts")
     * @ORM\JoinColumn(name="offer_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    private ?VolunteerOffer $offer = null;

    /**
     * @ORM\Column(name="starts_at", type="datetime")
     */
    #[Assert\NotNull(message: 'Un turno necesita fecha y hora.')]
    private ?\DateTimeInterface $startsAt = null;

    /**
     * Fin previsto. Nullable porque hay trabajo sin franja: "traducir el boletín
     * antes del día 20" tiene fecha límite y no horario.
     *
     * @ORM\Column(name="ends_at", type="datetime", nullable=true)
     */
    private ?\DateTimeInterface $endsAt = null;

    /**
     * Plazas SÓLO de este turno. Null = las de la tarea, que es lo normal.
     *
     * @ORM\Column(name="own_slots", type="integer", nullable=true)
     */
    #[Assert\Positive(message: 'Si pones plazas en el turno, tienen que ser más de cero.')]
    private ?int $ownSlots = null;

    /**
     * Minutos que computa SÓLO este turno. Null = los de la tarea.
     *
     * @ORM\Column(name="own_credited_minutes", type="integer", nullable=true)
     */
    #[Assert\Positive(message: 'Los minutos que computa tienen que ser más de cero.')]
    private ?int $ownCreditedMinutes = null;

    /**
     * Gente de fuera que vino a este turno: un grupo de estudiantes, gente de
     * otra asociación, quien pasaba por allí.
     *
     * VA EN EL TURNO Y NO EN LA TAREA, y ahí estaba el error de antes: que un
     * martes vinieran tres estudiantes no dice nada del martes siguiente, y
     * heredarlo daría por cubiertas unas plazas que están vacías.
     *
     * Un número y no filas: no son personas del sistema —no tienen ficha, ni
     * cuenta, ni horas— y darles una les inventaría una identidad que no existe.
     * Lo único que hace falta saber es cuántos brazos son.
     *
     * @ORM\Column(type="integer", options={"default": 0})
     */
    #[Assert\PositiveOrZero(message: 'La gente de fuera no puede ser un número negativo.')]
    private int $guests = 0;

    /**
     * Quiénes eran esos de fuera ("3 estudiantes del IES"). Sin esto, dentro de
     * tres meses nadie entiende por qué ese turno salió adelante con dos socixs.
     *
     * @ORM\Column(name="guests_note", type="string", length=160, nullable=true)
     */
    #[Assert\Length(max: 160)]
    private ?string $guestsNote = null;

    /**
     * Lo puso o lo cambió una persona, en vez de salir de la receta.
     *
     * LO PROTEGE DEL SYNC, y por eso existe. Al guardar la tarea, el generador
     * retira los turnos futuros vacíos que la receta ya no dicta
     * ({@see \App\Service\Volunteering\ShiftGenerator::sync()}); sin esta marca,
     * el turno que alguien movió a las siete porque ese viernes había asamblea
     * desaparecería la próxima vez que se tocara cualquier cosa de la tarea, y
     * lo haría en silencio.
     *
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private bool $manual = false;

    /**
     * Cuándo se anuló este turno, o null si sigue en pie. Ver el docblock de la
     * clase: se anula, no se borra.
     *
     * @ORM\Column(name="cancelled_at", type="datetime", nullable=true)
     */
    private ?\DateTimeInterface $cancelledAt = null;

    /**
     * Por qué se anuló ("festivo", "llovía"). Lo lee quien mira el histórico.
     *
     * @ORM\Column(name="cancelled_reason", type="string", length=160, nullable=true)
     */
    #[Assert\Length(max: 160)]
    private ?string $cancelledReason = null;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\VolunteerSignup", mappedBy="shift", cascade={"persist"})
     *
     * @var Collection<int, VolunteerSignup>
     */
    private Collection $signups;

    /**
     * @ORM\Column(name="created_at", type="datetime")
     */
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->signups = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    /**
     * Plazas que hace falta cubrir en este turno: las suyas si las tiene, y si
     * no las de la tarea. Null = sin tope ("cuanta más gente venga, mejor").
     *
     * Es este método —y no la columna— el que consume todo el mundo, para que
     * nadie tenga que acordarse de resolver la herencia. Lo crudo se lee con
     * {@see getOwnSlots()}, que es lo que edita el formulario.
     *
     * @return int|null plazas a cubrir, o null si no hay tope
     */
    public function getSlots(): ?int
    {
        return $this->ownSlots ?? $this->offer?->getSlots();
    }

    /**
     * Minutos que computa este turno a quien lo hace: los suyos si los tiene, y
     * si no los de la tarea. Null = no se ha fijado y se decide al cerrarlo.
     *
     * @return int|null minutos que computa, o null si no está fijado
     */
    public function getCreditedMinutes(): ?int
    {
        return $this->ownCreditedMinutes ?? $this->offer?->getCreditedMinutes();
    }

    /**
     * Si este turno está anulado. También lo está si lo está la tarea entera:
     * anular "el reparto" no puede dejar sus turnos pidiendo gente.
     *
     * @return bool true si no hay nada que hacer con él
     */
    public function isCancelled(): bool
    {
        return null !== $this->cancelledAt
            || VolunteerOffer::STATUS_CANCELLED === $this->offer?->getStatus();
    }

    /**
     * Anula el turno sin borrarlo, dejando fecha y motivo.
     *
     * @param string|null             $reason por qué se anula
     * @param \DateTimeInterface|null $when   cuándo; por defecto, ahora
     */
    public function cancel(?string $reason = null, ?\DateTimeInterface $when = null): self
    {
        $this->cancelledAt = $when ?? new \DateTime();
        $this->cancelledReason = $reason;

        return $this;
    }

    /**
     * Vuelve a poner el turno en pie después de anularlo.
     *
     * Método propio y no un `setCancelledAt(null)`: reactivar es un hecho con
     * nombre, y un setter que sólo se llama con null invita a llamarse con
     * cualquier otra cosa.
     */
    public function reopen(): self
    {
        $this->cancelledAt = null;
        $this->cancelledReason = null;

        return $this;
    }

    /**
     * Plazas ya ocupadas, contando acompañantes y gente de fuera, y sin contar
     * las inscripciones canceladas. Es lo que decide si sigue faltando gente,
     * así que vive aquí y no repartido por cada pantalla que lo necesite.
     *
     * @return int personas comprometidas ahora mismo
     */
    public function getFilledSlots(): int
    {
        // La gente de fuera cuenta como brazos aunque no tenga inscripción: si
        // vienen tres estudiantes, un turno de seis ya sólo necesita tres
        // socixs, y seguir pidiendo seis traería gente para nada.
        $filled = $this->guests;

        foreach ($this->signups as $signup) {
            if (!$signup->isCancelled()) {
                // Vía getHeadcount() y no sumando acompañantes aquí: es ese
                // método el que sabe que quien coordina no cuenta como brazo.
                $filled += $signup->getHeadcount();
            }
        }

        return $filled;
    }

    /**
     * Quién está comprometidx en este turno: las inscripciones vivas, sin las
     * canceladas. Incluye a quien coordina — en el sitio va a estar.
     *
     * Ojo con confundirlo con {@see getFilledSlots()}: aquél cuenta BRAZOS (con
     * acompañantes, sin coordinación) para decidir si falta gente; éste cuenta
     * PERSONAS con nombre para poder decir quiénes son.
     *
     * @return list<VolunteerSignup> las inscripciones que siguen en pie
     */
    public function getCommittedSignups(): array
    {
        $committed = [];
        foreach ($this->signups as $signup) {
            // Sin persona no hay nombre que enseñar, y quien consume esto es una
            // pantalla que va a pedirle el nombre. La columna es nullable.
            if (!$signup->isCancelled() && null !== $signup->getPartner()) {
                $committed[] = $signup;
            }
        }

        return $committed;
    }

    /**
     * Plazas que siguen sin cubrir, o null si el turno no tiene tope.
     *
     * @return int|null plazas libres; null si no hay número de plazas
     */
    public function getRemainingSlots(): ?int
    {
        $slots = $this->getSlots();

        if (null === $slots) {
            return null;
        }

        return max(0, $slots - $this->getFilledSlots());
    }

    /**
     * Si todavía cabe alguien. Un turno sin tope siempre admite gente.
     *
     * @return bool true si queda sitio
     */
    public function hasRoom(): bool
    {
        return 0 !== $this->getRemainingSlots();
    }

    /**
     * Cuánta gente ha confirmado que fue A TRABAJAR.
     *
     * Quien sólo organizó la tarea no cuenta aquí, y de ahí depende que
     * {@see isDone()} diga la verdad: un turno en el que consta la persona que
     * lo montó pero no fue nadie a hacerlo NO se hizo.
     *
     * @return int inscripciones de participación con asistencia confirmada
     */
    public function getAttendedCount(): int
    {
        $attended = 0;
        foreach ($this->signups as $signup) {
            if (true === $signup->getAttended() && !$signup->isCoordination()) {
                ++$attended;
            }
        }

        return $attended;
    }

    /**
     * Si ya no queda nadie por responder si fue o no. Un turno sin inscripciones
     * vivas cuenta como respondido: no hay nada que preguntar.
     *
     * @return bool true si todas las inscripciones vivas están respondidas
     */
    public function isSettled(): bool
    {
        foreach ($this->signups as $signup) {
            if (!$signup->isCancelled() && !$signup->isSettled()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Si el turno se hizo: ya pasó y al menos una persona confirmó que fue.
     *
     * Se DERIVA en vez de guardarse. Un `completed_at` habría que mantenerlo en
     * sincronía cada vez que alguien confirma, se desapunta o gestión corrige, y
     * en cuanto se desincronizara mentiría sin que se notara.
     *
     * @param \DateTimeInterface|null $now momento de referencia; por defecto, ahora
     *
     * @return bool true si el turno se dio por hecho
     */
    public function isDone(?\DateTimeInterface $now = null): bool
    {
        $now ??= new \DateTime();

        return $this->startsAt <= $now && $this->getAttendedCount() > 0;
    }

    /**
     * Si el turno pasó sin que fuera nadie. Es el dato incómodo y el que conviene
     * tener: un turno que nadie cubrió dice más sobre cómo va el voluntariado
     * que cualquier contador de horas.
     *
     * @param \DateTimeInterface|null $now momento de referencia; por defecto, ahora
     *
     * @return bool true si pasó, está respondido y no fue nadie
     */
    public function wasMissed(?\DateTimeInterface $now = null): bool
    {
        $now ??= new \DateTime();

        return !$this->isCancelled()
            && $this->startsAt <= $now
            && $this->isSettled()
            && 0 === $this->getAttendedCount();
    }

    /**
     * En qué momento de su vida está este turno, que es lo mismo que decir QUÉ
     * TRABAJO TOCA HACER con él.
     *
     * Existe porque los tres momentos piden cosas distintas de quien gestiona
     * —antes se persiguen plazas, el día se pasa lista, después se imputan
     * horas— y la pantalla los trataba igual.
     *
     * El orden de las comprobaciones no es cosmético:
     *
     *  - Borrador, anulado y en pausa ganan a todo. Un turno anulado que ya pasó
     *    no está "por cerrar": no hay nada que cerrar.
     *  - La fecha decide antes que {@see isSettled()}, que es true de forma vacía
     *    cuando no hay nadie apuntado. Sin ese orden, un turno de la semana que
     *    viene sin inscripciones saldría "cerrado".
     *  - TODAY abarca hasta el final del día natural, no sólo la franja horaria:
     *    quien pasa lista lo hace mientras descarga, y a veces después.
     *
     * @param \DateTimeInterface|null $now momento de referencia; por defecto, ahora
     *
     * @return string una de las constantes self::PHASE_*
     */
    public function getPhase(?\DateTimeInterface $now = null): string
    {
        $status = $this->offer?->getStatus();

        if (VolunteerOffer::STATUS_DRAFT === $status) {
            return self::PHASE_DRAFT;
        }

        if ($this->isCancelled()) {
            return self::PHASE_CANCELLED;
        }

        $now ??= new \DateTime();

        // La pausa sólo tapa lo que está por venir. Un turno pasado sigue
        // pidiendo que se le pase lista aunque la tarea se haya pausado después:
        // el trabajo se hizo, y las horas de quien fue no se pierden porque
        // alguien pare la tarea en septiembre.
        if (VolunteerOffer::STATUS_PAUSED === $status && $this->startsAt > $now) {
            return self::PHASE_PAUSED;
        }

        if (null === $this->startsAt || $this->startsAt > $now) {
            return self::PHASE_OPEN;
        }

        // Ya respondido es "cerrado" aunque sea hoy: si todo el mundo confirmó
        // desde su panel esta misma tarde, no queda lista que pasar.
        if ($this->isSettled()) {
            return self::PHASE_CLOSED;
        }

        return $this->startsAt->format('Y-m-d') === $now->format('Y-m-d')
            ? self::PHASE_TODAY
            : self::PHASE_TO_CLOSE;
    }

    /**
     * Los minutos ya imputados por este turno, sumando lo de todo el mundo.
     *
     * Sale de `VolunteerSignup::creditedMinutes` —lo congelado en cada
     * inscripción— y no de multiplicar plazas por lo que vale el turno, que es
     * otra cosa: hay quien se queda media hora menos y quien lo organizó y
     * computa distinto. Los acompañantes NO multiplican: las horas cuelgan de un
     * socix con ficha, y quien viene con su criatura no ha trabajado el doble.
     *
     * @return int minutos imputados; 0 si no se ha cerrado nada todavía
     */
    public function getCreditedMinutesTotal(): int
    {
        $total = 0;
        foreach ($this->signups as $signup) {
            if (!$signup->isCancelled() && true === $signup->getAttended()) {
                $total += $signup->getCreditedMinutes() ?? 0;
            }
        }

        return $total;
    }

    /**
     * Cuánta gente sigue sin decir si fue o no. Es el tamaño exacto del trabajo
     * pendiente en la fase de cierre.
     *
     * @return int inscripciones vivas sin responder
     */
    public function getPendingConfirmationCount(): int
    {
        $pending = 0;
        foreach ($this->signups as $signup) {
            if (!$signup->isCancelled() && !$signup->isSettled()) {
                ++$pending;
            }
        }

        return $pending;
    }

    /**
     * Si el turno admite gente nueva: la tarea publicada, el turno en pie, sin
     * haber empezado y con sitio. Sólo sobre éstos se piden voluntarios y se
     * lanzan avisos.
     *
     * @param \DateTimeInterface|null $now momento de referencia; por defecto, ahora
     *
     * @return bool true si admite gente nueva
     */
    public function isOpen(?\DateTimeInterface $now = null): bool
    {
        if (VolunteerOffer::STATUS_PUBLISHED !== $this->offer?->getStatus()) {
            return false;
        }

        if ($this->isCancelled()) {
            return false;
        }

        $now ??= new \DateTime();

        return $this->startsAt > $now && $this->hasRoom();
    }

    /**
     * Si este turno ya pasó.
     *
     * @param \DateTimeInterface|null $now momento de referencia; por defecto, ahora
     *
     * @return bool true si su fecha quedó atrás
     */
    public function isPast(?\DateTimeInterface $now = null): bool
    {
        return $this->startsAt <= ($now ?? new \DateTime());
    }

    /**
     * @return int|null el identificador, o null si aún no se ha persistido
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return VolunteerOffer|null la tarea de la que es turno
     */
    public function getOffer(): ?VolunteerOffer
    {
        return $this->offer;
    }

    /**
     * @param VolunteerOffer|null $offer la tarea de la que es turno
     */
    public function setOffer(?VolunteerOffer $offer): self
    {
        $this->offer = $offer;

        return $this;
    }

    /**
     * @return \DateTimeInterface|null cuándo empieza
     */
    public function getStartsAt(): ?\DateTimeInterface
    {
        return $this->startsAt;
    }

    /**
     * @param \DateTimeInterface $startsAt cuándo empieza
     */
    public function setStartsAt(\DateTimeInterface $startsAt): self
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    /**
     * @return \DateTimeInterface|null cuándo acaba, o null si no tiene franja
     */
    public function getEndsAt(): ?\DateTimeInterface
    {
        return $this->endsAt;
    }

    /**
     * @param \DateTimeInterface|null $endsAt cuándo acaba
     */
    public function setEndsAt(?\DateTimeInterface $endsAt): self
    {
        $this->endsAt = $endsAt;

        return $this;
    }

    /**
     * Las plazas propias de este turno, sin resolver la herencia. Es lo que
     * edita el formulario; para saber cuántas plazas hay de verdad,
     * {@see getSlots()}.
     *
     * @return int|null plazas propias, o null si hereda las de la tarea
     */
    public function getOwnSlots(): ?int
    {
        return $this->ownSlots;
    }

    /**
     * @param int|null $ownSlots plazas sólo de este turno; null para heredar
     */
    public function setOwnSlots(?int $ownSlots): self
    {
        $this->ownSlots = $ownSlots;

        return $this;
    }

    /**
     * Los minutos propios de este turno, sin resolver la herencia. Para saber lo
     * que computa de verdad, {@see getCreditedMinutes()}.
     *
     * @return int|null minutos propios, o null si hereda los de la tarea
     */
    public function getOwnCreditedMinutes(): ?int
    {
        return $this->ownCreditedMinutes;
    }

    /**
     * @param int|null $ownCreditedMinutes minutos sólo de este turno; null para heredar
     */
    public function setOwnCreditedMinutes(?int $ownCreditedMinutes): self
    {
        $this->ownCreditedMinutes = $ownCreditedMinutes;

        return $this;
    }

    /**
     * @return int cuánta gente de fuera vino
     */
    public function getGuests(): int
    {
        return $this->guests;
    }

    /**
     * Acepta null aunque la columna no lo admita: el campo del formulario es
     * opcional, y dejarlo vacío llega aquí como null.
     *
     * @param int|null $guests cuánta gente de fuera vino; null es ninguna
     */
    public function setGuests(?int $guests): self
    {
        $this->guests = max(0, $guests ?? 0);

        return $this;
    }

    /**
     * @return string|null quiénes eran los de fuera, o null
     */
    public function getGuestsNote(): ?string
    {
        return $this->guestsNote;
    }

    /**
     * @param string|null $guestsNote quiénes eran los de fuera
     */
    public function setGuestsNote(?string $guestsNote): self
    {
        $this->guestsNote = $guestsNote;

        return $this;
    }

    /**
     * Si este turno lo puso o lo cambió una persona. Ver el docblock del campo:
     * es lo que lo protege de que el sync lo retire.
     *
     * @return bool true si lo tocó alguien a mano
     */
    public function isManual(): bool
    {
        return $this->manual;
    }

    /**
     * @param bool $manual true si lo pone o lo cambia una persona
     */
    public function setManual(bool $manual): self
    {
        $this->manual = $manual;

        return $this;
    }

    /**
     * @return \DateTimeInterface|null cuándo se anuló, o null si sigue en pie
     */
    public function getCancelledAt(): ?\DateTimeInterface
    {
        return $this->cancelledAt;
    }

    /**
     * @return string|null por qué se anuló, o null
     */
    public function getCancelledReason(): ?string
    {
        return $this->cancelledReason;
    }

    /**
     * @return Collection<int, VolunteerSignup> las inscripciones, canceladas incluidas
     */
    public function getSignups(): Collection
    {
        return $this->signups;
    }

    /**
     * @param VolunteerSignup $signup la inscripción a enganchar
     */
    public function addSignup(VolunteerSignup $signup): self
    {
        if (!$this->signups->contains($signup)) {
            $this->signups->add($signup);
            $signup->setShift($this);
        }

        return $this;
    }

    /**
     * @return \DateTimeInterface cuándo se creó el turno
     */
    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
