<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un trabajo concreto que la asociación ofrece al voluntariado: qué, cuándo,
 * dónde, cuánta gente hace falta y a quién se le puede pedir. Es la unidad que
 * se publica; quien se apunta crea un {@see VolunteerSignup}.
 *
 * OFERTA ≠ INSCRIPCIÓN, y no es ceremonia: es lo que permite que una oferta
 * exista con cero apuntados —que es justo el estado que hay que hacer visible—
 * y que el aviso se pueda repetir sobre la misma oferta sin duplicarla.
 *
 * LAS HORAS QUE COMPUTA NO SON LA DURACIÓN. `creditedMinutes` es lo que la
 * asociación decide que vale este trabajo, y puede no coincidir con
 * `startsAt`/`endsAt`: dos horas de reparto un viernes por la tarde pueden valer
 * lo mismo que tres de oficina si así se acuerda, y una tarea a distancia no
 * tiene horario del que deducir nada. Van en MINUTOS enteros a propósito: un
 * decimal de Doctrine vuelve del driver como string y acaba sumándose con
 * floats, y "media hora" es 30 sin ambigüedad.
 *
 * A QUIÉN SE LE AVISA lo gobierna `openToAnyone`, y nace en false a propósito
 * ({@see VolunteerCall}). Marcarlo significa "esto lo puede hacer cualquiera sin
 * saber nada previo": recoger cestas, sí; desbrozar, no. Si el valor por defecto
 * fuera true, toda oferta creada sin pensar acabaría avisando a los 246 socixs,
 * y el permiso de notificaciones del navegador sólo se puede quemar una vez.
 *
 * @ORM\Table(name="volunteer_offer", indexes={
 *     @ORM\Index(name="idx_volunteer_offer_starts_at", columns={"starts_at"}),
 *     @ORM\Index(name="idx_volunteer_offer_status", columns={"status"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\VolunteerOfferRepository")
 */
class VolunteerOffer
{
    /** Redactándose: no se ve fuera de gestión y no genera ningún aviso. */
    public const STATUS_DRAFT = 'draft';

    /** Publicada: visible para socixs, admite inscripciones y avisos. */
    public const STATUS_PUBLISHED = 'published';

    /** Anulada: se conserva con sus inscripciones, pero ya no se pide gente. */
    public const STATUS_CANCELLED = 'cancelled';

    /** Redactándose. No la ve nadie fuera de gestión: no hay trabajo pendiente. */
    public const PHASE_DRAFT = 'draft';

    /** Anulada. Se conserva para consultarla, pero ya no hay nada que hacer. */
    public const PHASE_CANCELLED = 'cancelled';

    /** Publicada y aún por llegar: el trabajo es llenar las plazas. */
    public const PHASE_OPEN = 'open';

    /** Empezó hoy mismo: el trabajo es pasar lista. */
    public const PHASE_TODAY = 'today';

    /** Pasó y falta gente por decir si fue: el trabajo es cerrarla. */
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
     * @ORM\Column(type="string", length=160)
     */
    #[Assert\NotBlank(message: 'La oferta necesita un título.')]
    #[Assert\Length(max: 160)]
    private string $title = '';

    /**
     * En qué consiste el trabajo, con detalle suficiente para que alguien que
     * no ha estado nunca sepa si puede con ello y qué tiene que llevar.
     *
     * @ORM\Column(type="text", nullable=true)
     */
    private ?string $description = null;

    /**
     * Categorías del trabajo. Lado propietario de la relación.
     *
     * @ORM\ManyToMany(targetEntity="App\Entity\VolunteerCategory")
     * @ORM\JoinTable(name="volunteer_offer_category")
     *
     * @var Collection<int, VolunteerCategory>
     */
    private Collection $categories;

    /**
     * @ORM\Column(name="starts_at", type="datetime")
     */
    #[Assert\NotNull(message: 'Hace falta saber cuándo es.')]
    private ?\DateTimeInterface $startsAt = null;

    /**
     * Fin previsto. Nullable porque una tarea a distancia ("traducir el boletín
     * antes del día 20") tiene fecha límite pero no franja horaria.
     *
     * @ORM\Column(name="ends_at", type="datetime", nullable=true)
     */
    private ?\DateTimeInterface $endsAt = null;

    /**
     * Se hace desde casa. Excluye lugar y nodo: {@see setRemote()} los limpia
     * para que no quede una oferta "a distancia en La Cabrera".
     *
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private bool $remote = false;

    /**
     * Dónde, en texto libre, cuando no es un punto de recogida ("la nave",
     * "parcela de arriba"). Null si es a distancia.
     *
     * @ORM\Column(type="string", length=160, nullable=true)
     */
    private ?string $place = null;

    /**
     * Punto de recogida donde ocurre el trabajo, si ocurre en uno.
     *
     * Es EL enganche con el reparto, y el que hace que el aviso valga: quien
     * recoge su cesta ahí ya va a estar en ese sitio ese día, así que pedirle
     * media hora es la fricción más baja que existe. Null cuando el trabajo no
     * pasa en un nodo (la finca, a distancia).
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\Node")
     * @ORM\JoinColumn(name="node_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    private ?Node $node = null;

    /**
     * Cuánta gente hace falta. Null = sin tope (una llamada abierta del tipo
     * "cuanta más gente venga a la plantación, mejor").
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    #[Assert\Positive(message: 'Si pones un número de plazas, tiene que ser mayor que cero.')]
    private ?int $slots = null;

    /**
     * Si quien se apunta puede traer acompañantes. En una asociación de
     * unidades familiares el "voy y llevo a los peques" es el caso normal, no
     * la excepción, pero hay trabajos donde no cabe.
     *
     * @ORM\Column(name="companions_allowed", type="boolean", options={"default": false})
     */
    private bool $companionsAllowed = false;

    /**
     * Minutos que computa este trabajo a quien lo hace. Null = no computa
     * (aún) y hay que fijarlo al cerrarlo.
     *
     * @ORM\Column(name="credited_minutes", type="integer", nullable=true)
     */
    #[Assert\Positive(message: 'Los minutos que computa tienen que ser mayores que cero.')]
    private ?int $creditedMinutes = null;

    /**
     * Si el aviso se puede ampliar a socixs que no han declarado preferencias.
     * Ver el docblock de la clase: nace en false por diseño.
     *
     * @ORM\Column(name="open_to_anyone", type="boolean", options={"default": false})
     */
    private bool $openToAnyone = false;

    /**
     * Quien coordina ha decidido que esta tarea sube a lo alto del panel de cada
     * socix. Es el control editorial sobre la portada: hay semanas en las que una
     * cosa importa más que el orden natural de fechas.
     *
     * DESTACAR NO ES FILTRAR, y ésa es la decisión. Una portada que enseñara
     * ÚNICAMENTE lo destacado quedaría muda el día —seguro— en que nadie se
     * acuerde de marcar nada, y quedaría muda justo en la pantalla por la que
     * pasa todo el mundo. Así, cuando hay marcas mandan las marcas, y cuando no
     * las hay sigue funcionando el orden de siempre: lo del punto de recogida
     * propio primero, después por fecha.
     *
     * NO SE COPIA AL REPETIR UNA TAREA ({@see $copiedFrom}). Destacar es una
     * decisión sobre una semana concreta; arrastrarla a las doce copias de un
     * trimestre dejaría la portada con doce tareas destacadas, que es lo mismo
     * que ninguna. Quien copie una oferta deja este campo en false.
     *
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private bool $featured = false;

    /**
     * @ORM\Column(type="string", length=16, options={"default": "draft"})
     */
    #[Assert\Choice(choices: [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_CANCELLED])]
    private string $status = self::STATUS_DRAFT;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\VolunteerSignup", mappedBy="offer", cascade={"persist"})
     *
     * @var Collection<int, VolunteerSignup>
     */
    private Collection $signups;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\User")
     * @ORM\JoinColumn(name="created_by_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    private ?User $createdBy = null;

    /**
     * Quién monta ESTA tarea: busca gente, la cuadra, avisa y está pendiente.
     *
     * NO ES EL COORDINADOR DEL ÁREA, y confundirlos era el error. Un área tiene
     * varias personas coordinándola ({@see VolunteerCategory::$coordinators} es
     * ManyToMany) y el reparto son cincuenta y dos tareas al año: saber quién
     * lleva "Reparto" no dice quién montó el del 31 de agosto. Además aquéllos
     * son `User` —hay quien coordina sin ser socix— y las horas se le computan a
     * un `Partner`, así que no se puede derivar el uno del otro.
     *
     * SE DICE AL CREAR LA TAREA y no al cerrarla. Es una propiedad del trabajo,
     * como el sitio o la hora; preguntarlo después, mientras se pasa lista, era
     * pedir una decisión de configuración en medio de otra faena — y como no se
     * preguntaba en ningún sitio obligatorio, lo normal era que no constara
     * nadie y quien más sostiene el voluntariado saliera con el contador a cero.
     *
     * Nullable porque no toda tarea tiene a alguien al mando: una llamada
     * abierta a plantar puede no tenerlo.
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\Partner")
     * @ORM\JoinColumn(name="coordinator_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    private ?Partner $coordinator = null;

    /**
     * De qué tarea salió ésta, si se creó repitiendo otra.
     *
     * Sirve para poder responder "¿de dónde salieron estas doce?" cuando alguien
     * repite el reparto de un trimestre y luego quiere entender por qué hay
     * doce tareas iguales. `SET NULL` al borrar el original: perder la
     * referencia es aceptable, perder las copias no.
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\VolunteerOffer")
     * @ORM\JoinColumn(name="copied_from_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    private ?VolunteerOffer $copiedFrom = null;

    /**
     * @ORM\Column(name="created_at", type="datetime")
     */
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
        $this->signups = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    /**
     * Una copia de esta tarea en otra fecha, lista para persistir.
     *
     * Copia lo que define el trabajo y NO lo que pasó con él: ni inscripciones,
     * ni avisos enviados, ni fecha de creación. Repetir el reparto del viernes
     * que viene no puede arrastrar a quien se apuntó al de la semana pasada.
     *
     * La duración se conserva desplazando el final lo mismo que el principio: si
     * la tarea duraba dos horas, la copia dura dos horas. Calcularlo con el
     * intervalo y no copiando `endsAt` tal cual evita que la copia acabe antes
     * de empezar.
     *
     * Nace como BORRADOR aunque el original estuviera publicada, a propósito:
     * doce tareas creadas de golpe deben poder revisarse —y ajustarse los
     * festivos, que aquí no se modelan— antes de que empiecen a pedir gente
     * solas.
     *
     * @param \DateTimeInterface $startsAt cuándo empieza la copia
     *
     * @return self la copia, sin persistir
     */
    public function copyForDate(\DateTimeInterface $startsAt): self
    {
        $copy = new self();
        $copy->title = $this->title;
        $copy->description = $this->description;
        $copy->remote = $this->remote;
        $copy->place = $this->place;
        $copy->node = $this->node;
        $copy->slots = $this->slots;
        $copy->companionsAllowed = $this->companionsAllowed;
        $copy->creditedMinutes = $this->creditedMinutes;
        $copy->openToAnyone = $this->openToAnyone;
        // Quien monta el reparto de los viernes lo monta todos los viernes: la
        // copia hereda coordinación porque es parte de cómo se hace este
        // trabajo, no de lo que pasó en una fecha concreta.
        $copy->coordinator = $this->coordinator;
        $copy->createdBy = $this->createdBy;
        $copy->copiedFrom = $this;
        $copy->status = self::STATUS_DRAFT;
        $copy->startsAt = $startsAt;

        foreach ($this->categories as $category) {
            $copy->categories->add($category);
        }

        if (null !== $this->startsAt && null !== $this->endsAt) {
            $duration = $this->startsAt->diff($this->endsAt);
            $copy->endsAt = (\DateTimeImmutable::createFromInterface($startsAt))->add($duration);
        }

        return $copy;
    }

    /**
     * @return self|null la tarea de la que se copió ésta, o null
     */
    public function getCopiedFrom(): ?self
    {
        return $this->copiedFrom;
    }

    /**
     * Plazas ya ocupadas, contando acompañantes y sin contar las inscripciones
     * canceladas. Es lo que decide si sigue faltando gente, así que vive aquí y
     * no repartido por cada pantalla que lo necesite.
     *
     * @return int personas comprometidas ahora mismo
     */
    public function getFilledSlots(): int
    {
        $filled = 0;
        foreach ($this->signups as $signup) {
            if (!$signup->isCancelled()) {
                // Vía getHeadcount() y no sumando acompañantes aquí: es ese
                // método el que sabe que quien coordina no cuenta como brazo.
                // Duplicar la cuenta haría que una tarea de dos plazas se diera
                // por llena con una sola persona trabajando.
                $filled += $signup->getHeadcount();
            }
        }

        return $filled;
    }

    /**
     * Quién está comprometidx ahora mismo: las inscripciones vivas, sin las
     * canceladas. Incluye a quien coordina — en el sitio va a estar, y es
     * justo eso lo que se cuenta cuando se dice quién hay.
     *
     * Ojo con confundirlo con {@see getFilledSlots()}: aquél cuenta BRAZOS
     * (con acompañantes, sin coordinación) para decidir si falta gente; éste
     * cuenta PERSONAS con nombre para poder decir quiénes son.
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
     * Plazas que siguen sin cubrir, o null si la oferta no tiene tope.
     *
     * @return int|null plazas libres; null si no hay número de plazas
     */
    public function getRemainingSlots(): ?int
    {
        if (null === $this->slots) {
            return null;
        }

        return max(0, $this->slots - $this->getFilledSlots());
    }

    /**
     * Si todavía cabe alguien. Una oferta sin tope siempre admite gente.
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
     * {@see isDone()} diga la verdad: una tarea en la que consta la persona que
     * la montó pero no fue nadie a hacerla NO se hizo, y darla por hecha
     * escondería justo el dato que hay que ver.
     *
     * Sus horas sí se le computan; eso es otra cosa y vive en la inscripción.
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
     * Si ya no queda nadie por responder si fue o no. Es lo que hace que la
     * tarea deje de salir en "pendientes de confirmar", tanto en el panel de
     * quien se apuntó como en la lista de gestión.
     *
     * Una tarea sin inscripciones vivas cuenta como respondida: no hay nada que
     * preguntar.
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
     * Si la tarea se hizo: ya pasó y al menos una persona confirmó que fue.
     *
     * Se DERIVA en vez de guardarse en un campo. Un `completed_at` habría que
     * mantenerlo en sincronía cada vez que alguien confirma, se desapunta o
     * gestión corrige, y en cuanto se desincronizara mentiría sin que se notara.
     * Aquí la respuesta sale siempre de los mismos datos que la producen.
     *
     * @param \DateTimeInterface|null $now momento de referencia; por defecto, ahora
     *
     * @return bool true si la tarea se dio por hecha
     */
    public function isDone(?\DateTimeInterface $now = null): bool
    {
        $now ??= new \DateTime();

        return $this->startsAt <= $now && $this->getAttendedCount() > 0;
    }

    /**
     * Si la tarea pasó sin que fuera nadie. Es el dato incómodo y el que
     * conviene tener: una tarea que nadie cubrió dice más sobre cómo va el
     * voluntariado que cualquier contador de horas.
     *
     * @param \DateTimeInterface|null $now momento de referencia; por defecto, ahora
     *
     * @return bool true si pasó, está respondida y no fue nadie
     */
    public function wasMissed(?\DateTimeInterface $now = null): bool
    {
        $now ??= new \DateTime();

        return $this->startsAt <= $now
            && $this->isSettled()
            && 0 === $this->getAttendedCount();
    }

    /**
     * En qué momento de su vida está esta tarea, que es lo mismo que decir QUÉ
     * TRABAJO TOCA HACER con ella.
     *
     * Existe porque los tres momentos de una tarea piden cosas distintas de
     * quien la gestiona —antes se persiguen plazas, el día se pasa lista,
     * después se imputan horas— y la pantalla los trataba igual: el formulario
     * de cerrar colgaba debajo de la tabla estuviera la tarea a cuatro días o
     * cerrada hace un mes.
     *
     * Se DERIVA, como {@see isDone()} y por el mismo motivo: un campo `phase`
     * habría que moverlo con un cron a medianoche y cada vez que alguien
     * confirma, y en cuanto se desincronizara mentiría en silencio.
     *
     * El orden de las comprobaciones no es cosmético:
     *
     *  - Borrador y anulada ganan a todo. Una tarea anulada que ya pasó no está
     *    "por cerrar": no hay nada que cerrar. El mockup del rediseño sólo
     *    contemplaba cuatro fases y se dejaba estas dos fuera.
     *  - La fecha decide antes que {@see isSettled()}, que es true de forma
     *    vacía cuando no hay nadie apuntado. Sin ese orden, una tarea de la
     *    semana que viene sin inscripciones saldría "cerrada".
     *  - TODAY abarca desde que empieza hasta el final del día natural, no sólo
     *    su franja horaria: quien pasa lista lo hace en el nodo mientras
     *    descarga, y a veces cuando ya ha recogido.
     *
     * @param \DateTimeInterface|null $now momento de referencia; por defecto, ahora
     *
     * @return string una de las constantes self::PHASE_*
     */
    public function getPhase(?\DateTimeInterface $now = null): string
    {
        if (self::STATUS_DRAFT === $this->status) {
            return self::PHASE_DRAFT;
        }

        if (self::STATUS_CANCELLED === $this->status) {
            return self::PHASE_CANCELLED;
        }

        $now ??= new \DateTime();

        if (null === $this->startsAt || $this->startsAt > $now) {
            return self::PHASE_OPEN;
        }

        // Ya respondida es "cerrada" aunque sea hoy: si todo el mundo confirmó
        // desde su panel esta misma tarde, no queda lista que pasar.
        if ($this->isSettled()) {
            return self::PHASE_CLOSED;
        }

        return $this->startsAt->format('Y-m-d') === $now->format('Y-m-d')
            ? self::PHASE_TODAY
            : self::PHASE_TO_CLOSE;
    }

    /**
     * Los minutos ya imputados por esta tarea, sumando lo de todo el mundo.
     *
     * Es el dato que faltaba en la ficha, y no es un adorno: es lo que esta
     * tarea aporta a la cuenta de horas de la asociación, y quien la cierra
     * tiene que poder verlo ANTES de darle al botón. Sale de
     * `VolunteerSignup::creditedMinutes` —lo congelado en cada inscripción— y no
     * de multiplicar plazas por lo que vale la tarea, que es otra cosa: hay
     * quien se queda media hora menos y quien la organizó y computa distinto.
     *
     * Los acompañantes NO multiplican. Las horas cuelgan de un socix con ficha,
     * y quien viene con su criatura no ha trabajado el doble.
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
     * pendiente en la fase de cierre, y lo que decide si merece la pena
     * perseguirlo o cerrar la tarea a mano.
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
     * Si la oferta está viva: publicada y sin haber empezado todavía. Sólo
     * sobre éstas se piden voluntarios y se lanzan avisos.
     *
     * @param \DateTimeInterface|null $now momento de referencia; por defecto, ahora
     *
     * @return bool true si admite gente nueva
     */
    public function isOpen(?\DateTimeInterface $now = null): bool
    {
        if (self::STATUS_PUBLISHED !== $this->status) {
            return false;
        }

        $now ??= new \DateTime();

        return $this->startsAt > $now && $this->hasRoom();
    }

    /**
     * @return int|null el identificador, o null si aún no se ha persistido
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string el título de la oferta
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @param string $title el título de la oferta
     */
    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * @return string|null en qué consiste el trabajo, o null
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @param string|null $description en qué consiste el trabajo
     */
    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return Collection<int, VolunteerCategory> las categorías del trabajo
     */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    /**
     * @param VolunteerCategory $category la categoría a añadir
     */
    public function addCategory(VolunteerCategory $category): self
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
        }

        return $this;
    }

    /**
     * @param VolunteerCategory $category la categoría a quitar
     */
    public function removeCategory(VolunteerCategory $category): self
    {
        $this->categories->removeElement($category);

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
     * @return bool true si se hace desde casa
     */
    public function isRemote(): bool
    {
        return $this->remote;
    }

    /**
     * Marca la oferta como remota o presencial. Al marcarla remota se limpian
     * lugar y nodo: una oferta "a distancia en La Cabrera" es un estado que no
     * significa nada y que luego el aviso dirigido leería como presencial.
     *
     * @param bool $remote true si se hace desde casa
     */
    public function setRemote(bool $remote): self
    {
        $this->remote = $remote;
        if ($remote) {
            $this->place = null;
            $this->node = null;
        }

        return $this;
    }

    /**
     * @return string|null dónde se hace, en texto libre, o null
     */
    public function getPlace(): ?string
    {
        return $this->place;
    }

    /**
     * @param string|null $place dónde se hace, en texto libre
     */
    public function setPlace(?string $place): self
    {
        $this->place = $place;

        return $this;
    }

    /**
     * @return Node|null el punto de recogida donde ocurre, o null
     */
    public function getNode(): ?Node
    {
        return $this->node;
    }

    /**
     * @param Node|null $node el punto de recogida donde ocurre
     */
    public function setNode(?Node $node): self
    {
        $this->node = $node;

        return $this;
    }

    /**
     * @return int|null plazas totales, o null si no hay tope
     */
    public function getSlots(): ?int
    {
        return $this->slots;
    }

    /**
     * @param int|null $slots plazas totales; null para no poner tope
     */
    public function setSlots(?int $slots): self
    {
        $this->slots = $slots;

        return $this;
    }

    /**
     * @return bool true si se pueden traer acompañantes
     */
    public function isCompanionsAllowed(): bool
    {
        return $this->companionsAllowed;
    }

    /**
     * @param bool $companionsAllowed true si se pueden traer acompañantes
     */
    public function setCompanionsAllowed(bool $companionsAllowed): self
    {
        $this->companionsAllowed = $companionsAllowed;

        return $this;
    }

    /**
     * @return int|null minutos que computa el trabajo, o null si no se ha fijado
     */
    public function getCreditedMinutes(): ?int
    {
        return $this->creditedMinutes;
    }

    /**
     * @param int|null $creditedMinutes minutos que computa el trabajo
     */
    public function setCreditedMinutes(?int $creditedMinutes): self
    {
        $this->creditedMinutes = $creditedMinutes;

        return $this;
    }

    /**
     * @return bool true si el aviso puede ampliarse a quien no ha dicho nada
     */
    public function isOpenToAnyone(): bool
    {
        return $this->openToAnyone;
    }

    /**
     * @param bool $openToAnyone true si lo puede hacer cualquiera sin saber nada previo
     */
    public function setOpenToAnyone(bool $openToAnyone): self
    {
        $this->openToAnyone = $openToAnyone;

        return $this;
    }

    /**
     * @return bool true si sube a lo alto del panel de cada socix
     */
    public function isFeatured(): bool
    {
        return $this->featured;
    }

    /**
     * @param bool $featured true para subirla a lo alto del panel de cada socix
     */
    public function setFeatured(bool $featured): self
    {
        $this->featured = $featured;

        return $this;
    }

    /**
     * @return string el estado: draft, published o cancelled
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @param string $status el estado: draft, published o cancelled
     */
    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
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
            $signup->setOffer($this);
        }

        return $this;
    }

    /**
     * @return Partner|null quién monta esta tarea, o null si no hay nadie al mando
     */
    public function getCoordinator(): ?Partner
    {
        return $this->coordinator;
    }

    /**
     * @param Partner|null $coordinator quién monta esta tarea
     */
    public function setCoordinator(?Partner $coordinator): self
    {
        $this->coordinator = $coordinator;

        return $this;
    }

    /**
     * @return User|null quién creó la oferta, o null si se borró esa cuenta
     */
    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    /**
     * @param User|null $createdBy quién crea la oferta
     */
    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /**
     * @return \DateTimeInterface cuándo se creó la oferta
     */
    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
