<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un trabajo que la asociación ofrece al voluntariado: qué es, cómo se hace,
 * dónde, cuánta gente hace falta, cuánto computa y a quién se le puede pedir.
 *
 * NO LLEVA FECHA. Los momentos en que este trabajo se hace son sus turnos
 * ({@see VolunteerShift}), y es a un turno a lo que se apunta la gente. Antes la
 * fecha vivía aquí y repetir un trabajo significaba duplicar la ficha entera:
 * "sacar al perro, mañana y tarde" eran setecientas treinta tareas al año, y
 * corregir su explicación, setecientas treinta ediciones. La tarea dice QUÉ; el
 * turno, CUÁNDO.
 *
 * LA RECETA DE REPETICIÓN SÍ VIVE AQUÍ ({@see $repeatType} y compañía), al
 * contrario de lo que se hacía antes. Y puede hacerlo justamente porque ahora
 * hay un solo sitio donde está escrita: cuando cae un festivo se anula ESE turno
 * ({@see VolunteerShift::cancel()}) y la receta sigue diciendo la verdad —"esto
 * se hace los viernes"—, que era el problema que tenía la receta copiada en cada
 * una de las cincuenta y dos fichas.
 *
 * LAS HORAS QUE COMPUTA NO SON LA DURACIÓN. `creditedMinutes` es lo que la
 * asociación decide que vale este trabajo, y puede no coincidir con la franja
 * horaria del turno: dos horas de reparto un viernes por la tarde pueden valer
 * lo mismo que tres de oficina si así se acuerda, y una tarea a distancia no
 * tiene horario del que deducir nada. Van en MINUTOS enteros a propósito: un
 * decimal de Doctrine vuelve del driver como string y acaba sumándose con
 * floats, y "media hora" es 30 sin ambigüedad. Un turno suelto puede llevar
 * otros ({@see VolunteerShift::getCreditedMinutes()}).
 *
 * A QUIÉN SE LE AVISA lo gobierna `openToAnyone`, y nace en false a propósito
 * ({@see VolunteerCall}). Marcarlo significa "esto lo puede hacer cualquiera sin
 * saber nada previo": recoger cestas, sí; desbrozar, no. Si el valor por defecto
 * fuera true, toda tarea creada sin pensar acabaría avisando a los 246 socixs, y
 * el permiso de notificaciones del navegador sólo se puede quemar una vez.
 *
 * @ORM\Table(name="volunteer_offer", indexes={
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

    /**
     * En pausa: sigue existiendo, con su historia y sus turnos, pero no se pide
     * gente para los que están por venir.
     *
     * Existe porque hay trabajo continuo que se para una temporada —el
     * invernadero en invierno, el perro cuando su familia está en el pueblo— y
     * anularlo era mentira: la tarea no ha terminado, está parada. Anular
     * obligaba además a volver a crearla con toda su configuración.
     */
    public const STATUS_PAUSED = 'paused';

    /** Anulada: se conserva con su historia, pero ya no se pide gente. */
    public const STATUS_CANCELLED = 'cancelled';

    /** Estados válidos. */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_PAUSED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Se hace una vez y no se repite: una plantación, una reunión. Sigue
     * teniendo receta —un día y sus tramos— para que crear turnos sea siempre el
     * mismo camino y no haya dos formas de llegar a lo mismo.
     */
    public const REPEAT_ONCE = 'once';

    /** Se repite en los días de la semana marcados, cada N semanas. */
    public const REPEAT_WEEKLY = 'weekly';

    /**
     * Una vez al mes, conservando el día de la semana y su posición: si es el
     * segundo martes, los siguientes son el segundo martes.
     */
    public const REPEAT_MONTHLY = 'monthly';

    /**
     * Los días que el punto de recogida de la tarea reparte de verdad.
     *
     * Es la cadencia que más importa: el trabajo que más se repite es descargar
     * el reparto, y el reparto no cae cada siete días — cae los días que ese
     * punto reparte, que dependen de su cadencia y de las excepciones de
     * calendario. Preguntárselo al calendario de reparto es la diferencia entre
     * unas fechas que ya vienen bien y cincuenta y dos turnos que alguien tiene
     * que repasar a mano.
     */
    public const REPEAT_DELIVERY = 'delivery';

    /** Formas de repetición válidas. */
    public const REPEAT_TYPES = [
        self::REPEAT_ONCE,
        self::REPEAT_WEEKLY,
        self::REPEAT_MONTHLY,
        self::REPEAT_DELIVERY,
    ];

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private ?int $id = null;

    /**
     * @ORM\Column(type="string", length=160)
     */
    #[Assert\NotBlank(message: 'La tarea necesita un título.')]
    #[Assert\Length(max: 160)]
    private string $title = '';

    /**
     * En qué consiste el trabajo, con detalle suficiente para que alguien que no
     * ha estado nunca sepa si puede con ello y qué tiene que llevar.
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
     * Se hace desde casa. Excluye sitio y nodo: {@see setRemote()} los limpia
     * para que no quede una tarea "a distancia en La Cabrera".
     *
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private bool $remote = false;

    /**
     * Dónde se hace, del catálogo de sitios. Null si es a distancia o si ocurre
     * en un punto de recogida.
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\VolunteerPlace")
     * @ORM\JoinColumn(name="place_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    private ?VolunteerPlace $place = null;

    /**
     * Precisión sobre el sitio, en texto libre ("parcela de arriba", "por la
     * puerta de atrás"). Complementa a {@see $place}; no lo sustituye.
     *
     * @ORM\Column(name="place_note", type="string", length=160, nullable=true)
     */
    #[Assert\Length(max: 160)]
    private ?string $placeNote = null;

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
     * Cuánta gente hace falta en cada turno. Null = sin tope (una llamada
     * abierta del tipo "cuanta más gente venga a la plantación, mejor"). Un
     * turno suelto puede pedir otra cantidad
     * ({@see VolunteerShift::getSlots()}).
     *
     * @ORM\Column(type="integer", nullable=true)
     */
    #[Assert\Positive(message: 'Si pones un número de plazas, tiene que ser mayor que cero.')]
    private ?int $slots = null;

    /**
     * Si quien se apunta puede traer acompañantes. En una asociación de unidades
     * familiares el "voy y llevo a los peques" es el caso normal, no la
     * excepción, pero hay trabajos donde no cabe.
     *
     * @ORM\Column(name="companions_allowed", type="boolean", options={"default": false})
     */
    private bool $companionsAllowed = false;

    /**
     * Minutos que computa este trabajo a quien lo hace. Null = no computa (aún)
     * y hay que fijarlo al cerrar cada turno.
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
     * socix. Es el control editorial sobre la portada: hay semanas en las que
     * una cosa importa más que el orden natural de fechas.
     *
     * DESTACAR NO ES FILTRAR, y ésa es la decisión. Una portada que enseñara
     * ÚNICAMENTE lo destacado quedaría muda el día —seguro— en que nadie se
     * acuerde de marcar nada, y quedaría muda justo en la pantalla por la que
     * pasa todo el mundo. Así, cuando hay marcas mandan las marcas, y cuando no
     * las hay sigue funcionando el orden de siempre.
     *
     * @ORM\Column(type="boolean", options={"default": false})
     */
    private bool $featured = false;

    /**
     * @ORM\Column(type="string", length=16, options={"default": "draft"})
     */
    #[Assert\Choice(choices: VolunteerOffer::STATUSES)]
    private string $status = self::STATUS_DRAFT;

    /**
     * Cómo se repite este trabajo. Ver las constantes REPEAT_*.
     *
     * @ORM\Column(name="repeat_type", type="string", length=16, options={"default": "once"})
     */
    #[Assert\Choice(choices: VolunteerOffer::REPEAT_TYPES)]
    private string $repeatType = self::REPEAT_ONCE;

    /**
     * Cada cuántas semanas, en la repetición semanal. 1 = todas, 2 = una de cada
     * dos. Sustituye a la vieja cadencia "cada dos semanas": era el mismo
     * concepto con otro nombre, y como opción separada no dejaba decir "cada
     * tres".
     *
     * @ORM\Column(name="repeat_every", type="integer", options={"default": 1})
     */
    #[Assert\Positive]
    #[Assert\LessThanOrEqual(8)]
    private int $repeatEvery = 1;

    /**
     * Días de la semana en que se hace, en formato ISO-8601 (1 = lunes,
     * 7 = domingo). Varios a la vez, que es el caso que no se podía expresar:
     * "abrir el invernadero, sábados y domingos" era una tarea por día.
     *
     * `simple_array` y no JSON: son media docena de números y así la columna se
     * puede leer y consultar a ojo desde la BBDD ("1,6,7").
     *
     * @ORM\Column(name="repeat_weekdays", type="simple_array", nullable=true)
     *
     * @var list<string>|null
     */
    private ?array $repeatWeekdays = null;

    /**
     * Tramos horarios de cada día, como lista de pares [inicio, fin]. El fin
     * puede ser null en trabajo sin franja ("antes del día 20").
     *
     * VARIOS TRAMOS POR DÍA, que es el otro caso que faltaba: sacar al perro es
     * mañana y tarde del mismo día, o sea dos turnos por fecha. Cada fecha de la
     * receta se cruza con cada tramo, y de ahí salen los turnos.
     *
     * @ORM\Column(name="repeat_times", type="json", nullable=true)
     *
     * @var list<array{0: string, 1: string|null}>|null
     */
    private ?array $repeatTimes = null;

    /**
     * Días de diferencia entre el trabajo y la fecha que dicta la receta: 0 el
     * mismo día, -1 la víspera. Sólo lo usa la cadencia del calendario de
     * reparto, que es la única cuya fecha viene dada de fuera.
     *
     * EXISTE PORQUE EL MONTAJE DE LAS CESTAS SE HACE ANTES DE REPARTIRLAS, a
     * veces la tarde anterior. Sin esto, "los días que haya reparto" sólo sabe
     * convocar el día físico de la entrega, que en la mitad de los puntos es
     * tarde.
     *
     * Va en la receta y no se le pregunta al punto: así la receta se basta sola
     * y sirve para cualquier tarea con cadencia de reparto —recoger las cajas al
     * día siguiente, por ejemplo—, no sólo para el montaje.
     *
     * @ORM\Column(name="repeat_offset_days", type="smallint", options={"default": 0})
     */
    private int $repeatOffsetDays = 0;

    /**
     * Esta tarea es EL MONTAJE DE LAS CESTAS de su punto de recogida, y la ha
     * creado el sistema a partir de lo que ese punto declara
     * ({@see \App\Service\Volunteering\DeliveryPrepOffers}).
     *
     * De aquí sale el bloque de la home que le dice a cada socix quién le está
     * preparando la cesta. Antes lo decía una casilla del TIPO DE TRABAJO, que
     * señalaba una sola cosa en toda la asociación y permitía marcarla cero
     * veces —panel mudo— o dos —panel señalando a quien friega el suelo—. Ahora
     * la marca va en la tarea, que es de quien se afirma algo, y la pone quien la
     * crea: si la ha generado el sistema desde el calendario de reparto, ya sabe
     * lo que es.
     *
     * NO SE MARCA A MANO en ninguna pantalla. Una tarea de montaje aparece
     * porque su punto dice que monta con voluntariado, y desaparece cuando deja
     * de decirlo.
     *
     * @ORM\Column(name="delivery_prep", type="boolean", options={"default": false})
     */
    private bool $deliveryPrep = false;

    /**
     * Desde cuándo se hace. En una tarea de una vez, ES el día.
     *
     * @ORM\Column(name="repeat_from", type="date", nullable=true)
     */
    private ?\DateTimeInterface $repeatFrom = null;

    /**
     * Hasta cuándo se generan turnos, incluido.
     *
     * SE DICE HASTA CUÁNDO, NO CUÁNTAS VECES. "El reparto de los viernes hasta
     * fin de año" es como se piensa; "cada 7 días, 17 veces" obliga a contar
     * semanas a mano y a repetir la cuenta cada vez que se amplía.
     *
     * @ORM\Column(name="repeat_until", type="date", nullable=true)
     */
    private ?\DateTimeInterface $repeatUntil = null;

    /**
     * Los momentos en que este trabajo se hace.
     *
     * @ORM\OneToMany(targetEntity="App\Entity\VolunteerShift", mappedBy="offer", cascade={"persist"}, orphanRemoval=true)
     * @ORM\OrderBy({"startsAt": "ASC"})
     *
     * @var Collection<int, VolunteerShift>
     */
    private Collection $shifts;

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
     * ManyToMany) y además aquéllos son `User` —hay quien coordina sin ser
     * socix— mientras las horas se le computan a un `Partner`, así que no se
     * puede derivar el uno del otro.
     *
     * SE DICE AL CREAR LA TAREA y no al cerrarla. Es una propiedad del trabajo,
     * como el sitio; preguntarlo después, mientras se pasa lista, era pedir una
     * decisión de configuración en medio de otra faena — y como no se preguntaba
     * en ningún sitio obligatorio, lo normal era que no constara nadie y quien
     * más sostiene el voluntariado saliera con el contador a cero.
     *
     * Nullable porque no toda tarea tiene a alguien al mando: una llamada
     * abierta a plantar puede no tenerlo.
     *
     * @ORM\ManyToOne(targetEntity="App\Entity\Partner")
     * @ORM\JoinColumn(name="coordinator_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    private ?Partner $coordinator = null;

    /**
     * @ORM\Column(name="created_at", type="datetime")
     */
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
        $this->shifts = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    /**
     * El próximo turno que está por venir y sigue en pie.
     *
     * Es lo que se enseña en el listado y en el panel del socix: de una tarea
     * continua no interesan sus doscientos turnos, interesa el siguiente.
     *
     * @param \DateTimeInterface|null $now momento de referencia; por defecto, ahora
     *
     * @return VolunteerShift|null el siguiente turno, o null si no queda ninguno
     */
    public function getNextShift(?\DateTimeInterface $now = null): ?VolunteerShift
    {
        $now ??= new \DateTime();
        $next = null;

        foreach ($this->shifts as $shift) {
            if ($shift->isCancelled() || $shift->isPast($now)) {
                continue;
            }

            if (null === $next || $shift->getStartsAt() < $next->getStartsAt()) {
                $next = $shift;
            }
        }

        return $next;
    }

    /**
     * Los turnos por venir que siguen en pie, del más próximo al más lejano.
     *
     * @param \DateTimeInterface|null $now   momento de referencia; por defecto, ahora
     * @param int|null                $limit cuántos como máximo; null para todos
     *
     * @return list<VolunteerShift> turnos futuros ordenados
     */
    public function getUpcomingShifts(?\DateTimeInterface $now = null, ?int $limit = null): array
    {
        $now ??= new \DateTime();

        $upcoming = [];
        foreach ($this->shifts as $shift) {
            if (!$shift->isCancelled() && !$shift->isPast($now)) {
                $upcoming[] = $shift;
            }
        }

        usort($upcoming, static fn (VolunteerShift $a, VolunteerShift $b): int => $a->getStartsAt() <=> $b->getStartsAt());

        return null === $limit ? $upcoming : \array_slice($upcoming, 0, $limit);
    }

    /**
     * Los turnos que ya pasaron y a los que falta pasarles lista. Es el tamaño
     * exacto del trabajo pendiente de esta tarea, y lo que hace que una tarea
     * continua no acumule meses de asistencia sin responder.
     *
     * @param \DateTimeInterface|null $now momento de referencia; por defecto, ahora
     *
     * @return list<VolunteerShift> turnos pasados sin cerrar, del más antiguo al más reciente
     */
    public function getShiftsToClose(?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTime();

        $pending = [];
        foreach ($this->shifts as $shift) {
            if (!$shift->isCancelled() && $shift->isPast($now) && !$shift->isSettled()) {
                $pending[] = $shift;
            }
        }

        usort($pending, static fn (VolunteerShift $a, VolunteerShift $b): int => $a->getStartsAt() <=> $b->getStartsAt());

        return $pending;
    }

    /**
     * Si esta tarea admite gente nueva en algún turno: publicada, y con al menos
     * un turno futuro con sitio.
     *
     * @param \DateTimeInterface|null $now momento de referencia; por defecto, ahora
     *
     * @return bool true si se puede apuntar alguien
     */
    public function hasOpenShifts(?\DateTimeInterface $now = null): bool
    {
        foreach ($this->shifts as $shift) {
            if ($shift->isOpen($now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Los minutos que esta tarea ha imputado en total, sumando todos sus turnos.
     *
     * @return int minutos imputados
     */
    public function getCreditedMinutesTotal(): int
    {
        $total = 0;
        foreach ($this->shifts as $shift) {
            $total += $shift->getCreditedMinutesTotal();
        }

        return $total;
    }

    /**
     * Si la tarea se repite de verdad, o es de una sola vez.
     *
     * @return bool true si tiene receta de repetición
     */
    public function isRepeating(): bool
    {
        return self::REPEAT_ONCE !== $this->repeatType;
    }

    /**
     * @return int|null el identificador, o null si aún no se ha persistido
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string el título de la tarea
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @param string $title el título de la tarea
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
     * @return bool true si se hace desde casa
     */
    public function isRemote(): bool
    {
        return $this->remote;
    }

    /**
     * Marca la tarea como remota o presencial. Al marcarla remota se limpian
     * sitio, precisión y nodo: una tarea "a distancia en La Cabrera" es un
     * estado que no significa nada y que luego el aviso dirigido leería como
     * presencial.
     *
     * @param bool $remote true si se hace desde casa
     */
    public function setRemote(bool $remote): self
    {
        $this->remote = $remote;
        if ($remote) {
            $this->place = null;
            $this->placeNote = null;
            $this->node = null;
        }

        return $this;
    }

    /**
     * @return VolunteerPlace|null el sitio del catálogo, o null
     */
    public function getPlace(): ?VolunteerPlace
    {
        return $this->place;
    }

    /**
     * @param VolunteerPlace|null $place el sitio del catálogo
     */
    public function setPlace(?VolunteerPlace $place): self
    {
        $this->place = $place;

        return $this;
    }

    /**
     * @return string|null la precisión sobre el sitio, o null
     */
    public function getPlaceNote(): ?string
    {
        return $this->placeNote;
    }

    /**
     * @param string|null $placeNote la precisión sobre el sitio
     */
    public function setPlaceNote(?string $placeNote): self
    {
        $this->placeNote = $placeNote;

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
     * @return int|null plazas por turno, o null si no hay tope
     */
    public function getSlots(): ?int
    {
        return $this->slots;
    }

    /**
     * @param int|null $slots plazas por turno; null para no poner tope
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
     * @return string el estado; una de las constantes STATUS_*
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @param string $status el estado; una de las constantes STATUS_*
     */
    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Si la tarea está publicada y pidiendo gente. Una tarea en pausa NO lo está,
     * y de eso depende que pausar sirva para algo.
     *
     * @return bool true si está publicada
     */
    public function isPublished(): bool
    {
        return self::STATUS_PUBLISHED === $this->status;
    }

    /**
     * @return bool true si está en pausa
     */
    public function isPaused(): bool
    {
        return self::STATUS_PAUSED === $this->status;
    }

    /**
     * @return bool true si está anulada
     */
    public function isCancelled(): bool
    {
        return self::STATUS_CANCELLED === $this->status;
    }

    /**
     * @return bool true si sigue en borrador
     */
    public function isDraft(): bool
    {
        return self::STATUS_DRAFT === $this->status;
    }

    /**
     * @return string cómo se repite; una de las constantes REPEAT_*
     */
    public function getRepeatType(): string
    {
        return $this->repeatType;
    }

    /**
     * @param string $repeatType cómo se repite; una de las constantes REPEAT_*
     */
    public function setRepeatType(string $repeatType): self
    {
        $this->repeatType = $repeatType;

        return $this;
    }

    /**
     * @return int cada cuántas semanas, en la repetición semanal
     */
    public function getRepeatEvery(): int
    {
        return $this->repeatEvery;
    }

    /**
     * Acepta null aunque la columna no lo admita: el campo del formulario puede
     * llegar vacío, y "cada cuántas semanas" vacío significa todas.
     *
     * @param int|null $repeatEvery cada cuántas semanas; null es cada una
     */
    public function setRepeatEvery(?int $repeatEvery): self
    {
        $this->repeatEvery = max(1, $repeatEvery ?? 1);

        return $this;
    }

    /**
     * @return int días entre el trabajo y la fecha que dicta la receta: 0 el mismo día, -1 la víspera
     */
    public function getRepeatOffsetDays(): int
    {
        return $this->repeatOffsetDays;
    }

    /**
     * @param int|null $repeatOffsetDays 0 el mismo día, -1 la víspera; null es el mismo día
     */
    public function setRepeatOffsetDays(?int $repeatOffsetDays): self
    {
        $this->repeatOffsetDays = $repeatOffsetDays ?? 0;

        return $this;
    }

    /**
     * @return bool true si esta tarea es el montaje de las cestas de su punto
     */
    public function isDeliveryPrep(): bool
    {
        return $this->deliveryPrep;
    }

    /**
     * @param bool $deliveryPrep true si esta tarea es el montaje de las cestas de su punto
     */
    public function setDeliveryPrep(bool $deliveryPrep): self
    {
        $this->deliveryPrep = $deliveryPrep;

        return $this;
    }

    /**
     * Días de la semana en ISO-8601, como enteros ya normalizados y ordenados.
     * La columna guarda strings —`simple_array` no conserva el tipo—, así que
     * quien consuma esto no tiene que acordarse de castear.
     *
     * @return list<int> días de la semana (1 = lunes … 7 = domingo)
     */
    public function getRepeatWeekdays(): array
    {
        $days = array_map('intval', $this->repeatWeekdays ?? []);
        $days = array_values(array_unique(array_filter($days, static fn (int $d): bool => $d >= 1 && $d <= 7)));
        sort($days);

        return $days;
    }

    /**
     * @param list<int|string>|null $weekdays días de la semana (1 = lunes … 7 = domingo)
     */
    public function setRepeatWeekdays(?array $weekdays): self
    {
        if (null === $weekdays || [] === $weekdays) {
            $this->repeatWeekdays = null;

            return $this;
        }

        $this->repeatWeekdays = array_values(array_map('strval', $weekdays));

        return $this;
    }

    /**
     * Tramos horarios de cada día, como lista de pares [inicio, fin|null] en
     * formato "HH:MM".
     *
     * @return list<array{0: string, 1: string|null}> tramos; lista vacía si no hay ninguno
     */
    public function getRepeatTimes(): array
    {
        return $this->repeatTimes ?? [];
    }

    /**
     * @param list<array{0: string, 1: string|null}>|null $times tramos horarios
     */
    public function setRepeatTimes(?array $times): self
    {
        $this->repeatTimes = ([] === $times) ? null : $times;

        return $this;
    }

    /**
     * @return \DateTimeInterface|null desde cuándo se hace, o null
     */
    public function getRepeatFrom(): ?\DateTimeInterface
    {
        return $this->repeatFrom;
    }

    /**
     * @param \DateTimeInterface|null $repeatFrom desde cuándo se hace
     */
    public function setRepeatFrom(?\DateTimeInterface $repeatFrom): self
    {
        $this->repeatFrom = $repeatFrom;

        return $this;
    }

    /**
     * @return \DateTimeInterface|null hasta cuándo se generan turnos, o null
     */
    public function getRepeatUntil(): ?\DateTimeInterface
    {
        return $this->repeatUntil;
    }

    /**
     * @param \DateTimeInterface|null $repeatUntil hasta cuándo se generan turnos
     */
    public function setRepeatUntil(?\DateTimeInterface $repeatUntil): self
    {
        $this->repeatUntil = $repeatUntil;

        return $this;
    }

    /**
     * @return Collection<int, VolunteerShift> los turnos, anulados incluidos
     */
    public function getShifts(): Collection
    {
        return $this->shifts;
    }

    /**
     * @param VolunteerShift $shift el turno a enganchar
     */
    public function addShift(VolunteerShift $shift): self
    {
        if (!$this->shifts->contains($shift)) {
            $this->shifts->add($shift);
            $shift->setOffer($this);
        }

        return $this;
    }

    /**
     * @param VolunteerShift $shift el turno a soltar
     */
    public function removeShift(VolunteerShift $shift): self
    {
        $this->shifts->removeElement($shift);

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
     * @return User|null quién creó la tarea, o null si se borró esa cuenta
     */
    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    /**
     * @param User|null $createdBy quién crea la tarea
     */
    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /**
     * @return \DateTimeInterface cuándo se creó la tarea
     */
    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
