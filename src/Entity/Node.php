<?php
/**
 * Sitio físico donde se entregan las cestas: Torremocha, Cascorro, Midori.
 * Un nodo agrupa N WeeklyBasketGroup y define el día de la semana y la
 * cadencia (semanal o quincenal) con la que reparte. Para nodos quincenales
 * anchor_date marca un viernes-ciclo que SÍ reparte: a partir de ahí
 * alternan semanas operativas vs vacías.
 *
 * Modelado en sub-fase 8.8a (2026-05-26).
 */

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * App\Entity\Node
 *
 * @ORM\Table(name="node", uniqueConstraints={@ORM\UniqueConstraint(name="UNIQ_node_name", columns={"name"})})
 * @ORM\Entity(repositoryClass="App\Repository\NodeRepository")
 */
class Node
{
    public const CADENCE_WEEKLY = 'weekly';
    public const CADENCE_BIWEEKLY = 'biweekly';
    public const CADENCE_MONTHLY = 'monthly';
    public const CADENCES = [self::CADENCE_WEEKLY, self::CADENCE_BIWEEKLY, self::CADENCE_MONTHLY];

    /**
     * Nombre humano de cada cadencia. Punto único: lo consumen el formulario
     * del nodo y todas las pantallas que la muestran. Antes cada plantilla
     * llevaba su propio mapa y al añadir la cadencia mensual se quedaron
     * cortas, enseñando "monthly" en crudo.
     *
     * @var array<string,string>
     */
    public const CADENCE_LABELS = [
        self::CADENCE_WEEKLY   => 'Semanal',
        self::CADENCE_BIWEEKLY => 'Quincenal',
        self::CADENCE_MONTHLY  => 'Mensual',
    ];

    /**
     * "Última semana del mes" como valor de `monthly_week`. Negativo a
     * propósito, igual que {@see PartnerBasketShare::$day_month_order}: la
     * última entrega es la 4ª en un mes de 4 semanas y la 5ª en uno de 5, así
     * que contarla desde el final es la única forma de que signifique siempre
     * lo mismo.
     */
    public const MONTHLY_WEEK_LAST = -1;

    /**
     * Semanas del mes elegibles para un punto de cadencia mensual. No incluye
     * la 4ª a propósito: "la cuarta" y "la última" sólo coinciden en los meses
     * de 4 semanas, y lo que administración quiere decir siempre es la última.
     *
     * @var int[]
     */
    public const MONTHLY_WEEKS = [1, 2, 3, self::MONTHLY_WEEK_LAST];

    /**
     * Día ISO-8601 (el que devuelve DateTime::format('N')) a nombre humano.
     * Punto único: lo consumen el formulario del nodo y las etiquetas de
     * fechas del formulario de cesta.
     *
     * @var array<int,string>
     */
    public const WEEKDAY_NAMES = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
        7 => 'Domingo',
    ];

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=255)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name;

    /**
     * Día de la semana de reparto en formato ISO-8601: 1=Lunes ... 7=Domingo.
     * Coincide con el resultado de DateTime::format('N').
     *
     * @ORM\Column(name="delivery_weekday", type="smallint")
     */
    #[Assert\NotBlank]
    #[Assert\Range(min: 1, max: 7)]
    private int $deliveryWeekday;

    /**
     * Cadencia de reparto: 'weekly' o 'biweekly'.
     *
     * @ORM\Column(type="string", length=16)
     */
    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::CADENCES, message: 'Cadencia inválida (solo weekly o biweekly).')]
    private string $cadence = self::CADENCE_WEEKLY;

    /**
     * Sólo aplica si cadence='biweekly'. Fecha de una entrega REAL del nodo
     * que SÍ reparte; el resto se infieren alternando desde ahí.
     *
     * Debe caer en el mismo día de la semana que `delivery_weekday`: la
     * alineación quincenal se calcula comparando esta fecha con la fecha
     * FÍSICA del nodo ({@see \App\Service\Delivery\NodeDeliveryDate}), así que
     * un ancla en otro día de la semana desplaza la cuenta de semanas e
     * invierte la fase — el nodo repartiría justo las semanas contrarias.
     * Lo garantiza {@see validateCadenceConsistency()}.
     *
     * @ORM\Column(name="anchor_date", type="date", nullable=true)
     */
    private ?\DateTimeInterface $anchorDate = null;

    /**
     * Sólo aplica si cadence='monthly'. Qué semana del mes abre el punto:
     * 1, 2, 3 o {@see MONTHLY_WEEK_LAST} (la última). Se cuenta sobre las
     * ocurrencias de `delivery_weekday` dentro del mes natural — el 2º jueves
     * del mes es el 2º jueves, con independencia de en qué día caiga el 1.
     *
     * Lo garantiza {@see validateCadenceConsistency()}.
     *
     * @ORM\Column(name="monthly_week", type="smallint", nullable=true)
     */
    private ?int $monthlyWeek = null;

    /**
     * Horario público de recogida, texto libre que se muestra tal cual en la
     * web pública (página "Hazte socix"). Ej.: «Miércoles de 18:00 a 20:00».
     * NULL = no se muestra horario para este nodo.
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    #[Assert\Length(max: 255)]
    private ?string $schedule = null;

    /**
     * Grupos de socios (proximidad) que cuelgan de este nodo.
     *
     * @ORM\OneToMany(targetEntity="WeeklyBasketGroup", mappedBy="node")
     */
    private $weeklyBasketGroups;

    /**
     * Quién recibe por correo el listado de reparto de este nodo cuando se
     * cierra su plazo de cambios.
     *
     * SE LLAMA POR LO QUE ES Y NO "COORDINADORES", y esa es la decisión que
     * importa. El listado no lo necesita sólo quien coordina: también quien monta
     * el reparto ese día, y mañana alguien más. Si esto fuera la coordinación del
     * nodo, para que a esa gente le llegara el correo habría que nombrarla
     * coordinadora —que es falso— y en este proyecto de la coordinación se
     * DERIVAN permisos ({@see VolunteerCategory::$coordinators} concede
     * ROLE_GESTION_VOLUNTARIADO): se acabaría dando acceso a gestión a alguien
     * sólo para que reciba un adjunto. Aquí no se deriva ningún rol.
     *
     * CUELGA DE Partner Y NO DE User, y esto se cambió con datos delante: hay 402
     * socixs con correo y sólo 43 cuentas en toda la casa, de las que 12 tienen
     * permisos de gestión. Colgando de User, quien monta el reparto —que casi
     * nunca tiene cuenta— era literalmente inseleccionable, y la funcionalidad
     * sólo servía para las doce personas del equipo. Recibir un correo no exige
     * poder entrar en la web.
     *
     * Vacío = el listado de este nodo cae al ajuste general
     * ({@see \App\Service\AppSettings::EMAIL_DELIVERY_SHEET_TO}), que sigue
     * sirviendo de respaldo mientras los nodos no tengan a nadie asignado.
     *
     * ⚠️ Queda abierto que el listado lleva nombre, localidad y lo que recibe
     * cada persona del punto, así que esto permite mandárselo a cualquier socix.
     * Decisión consciente de la asociación, y provisional: cuando haya un criterio
     * de a quién puede llegar, se acota aquí.
     *
     * @ORM\ManyToMany(targetEntity="App\Entity\Partner")
     * @ORM\JoinTable(name="node_sheet_recipient")
     *
     * @var Collection<int, Partner>
     */
    private Collection $sheetRecipients;

    /**
     * Este punto monta sus cestas con voluntariado: cada semana que abre hace
     * falta gente para prepararlas, y el sistema lo convoca solo.
     *
     * VA AQUÍ Y NO EN EL TIPO DE TRABAJO, y ése es el cambio que lo hace
     * utilizable. Antes lo decía una casilla en {@see VolunteerCategory}, "es el
     * montaje del reparto", que señalaba UNA cosa en toda la asociación:
     * marcando cero, la home del socix se quedaba muda sin decir por qué;
     * marcando dos, señalaba a quien estuviera fregando el suelo. Aquí el
     * booleano es legítimo, porque hay varios puntos y cada uno decide: que no
     * lo marque ninguno, o que lo marquen los siete, son respuestas válidas.
     *
     * ES UN OPT-IN A PROPÓSITO. Hoy sólo Torremocha lo organiza así; generar la
     * convocatoria en todos los puntos llenaría de tareas vacías a los que
     * montan las cestas de otra manera, y una tarea sin nadie apuntado se lee
     * como un reproche, no como una oportunidad.
     *
     * @ORM\Column(name="delivery_prep", type="boolean", options={"default": false})
     */
    private bool $deliveryPrep = false;

    /**
     * Cuánta gente hace falta para montar las cestas de este punto. NULL = sin
     * tope, que es lo que ya significa en {@see VolunteerOffer::$slots}: se
     * apunta quien quiera.
     *
     * Va en el punto y no en cada convocatoria porque es una propiedad del
     * sitio —montar cien cestas necesita las manos que necesita— y así no hay
     * que acordarse cada semana.
     *
     * @ORM\Column(name="delivery_prep_slots", type="integer", nullable=true)
     */
    #[Assert\Positive(message: 'Si dices cuánta gente hace falta, tiene que ser al menos una persona.')]
    private ?int $deliveryPrepSlots = null;

    /**
     * A qué hora empieza el montaje. Sin esto no se puede convocar a nadie:
     * "el jueves" no es una cita.
     *
     * Es la hora, no el día: el día lo dicta el calendario de reparto del punto
     * ({@see \App\Service\Delivery\NodeDeliveryDate}), corrido por
     * {@see $deliveryPrepDayOffset}. Escribirlo aquí sería duplicar el
     * calendario y condenarlo a desincronizarse.
     *
     * @ORM\Column(name="delivery_prep_time", type="time", nullable=true)
     */
    private ?\DateTimeInterface $deliveryPrepTime = null;

    /**
     * Cuánto dura el montaje, en minutos. De aquí sale la hora de fin de la
     * convocatoria.
     *
     * NO ES LO QUE COMPUTA A QUIEN VIENE, y la distinción no es mía: el módulo
     * de voluntariado separa a propósito la duración de las horas reconocidas
     * ({@see VolunteerOffer::$creditedMinutes}), porque hay trabajo que vale más
     * de lo que dura. Lo que se reconoce sigue viviendo en la convocatoria, que
     * es donde se puede subir sin tocar el horario del punto.
     *
     * NULL = convocatoria sin hora de fin. Se puede vivir con ello —dice cuándo
     * empieza y ya— y es mejor que inventarse una duración.
     *
     * @ORM\Column(name="delivery_prep_minutes", type="integer", nullable=true)
     */
    #[Assert\Positive(message: 'Si dices cuánto dura el montaje, tienen que ser más de cero minutos.')]
    private ?int $deliveryPrepMinutes = null;

    /**
     * Días de diferencia entre el montaje y la entrega: 0 el mismo día, -1 la
     * víspera.
     *
     * EXISTE PARA MATAR UNA ADIVINANZA. Como el montaje se hace a veces la
     * tarde anterior, la consulta que buscaba quién prepara tu cesta barría una
     * ventana de dos días y se quedaba con lo que hubiera dentro. Preguntarle al
     * punto cuándo monta es más corto y no falla.
     *
     * No admite positivos: montar las cestas después de repartirlas no es un
     * caso de uso, es un dedazo. Lo garantiza {@see validateDeliveryPrep()}.
     *
     * @ORM\Column(name="delivery_prep_day_offset", type="smallint", options={"default": 0})
     */
    private int $deliveryPrepDayOffset = 0;

    public function __construct()
    {
        $this->weeklyBasketGroups = new ArrayCollection();
        $this->sheetRecipients = new ArrayCollection();
    }

    /**
     * @return Collection<int, Partner> quiénes reciben el listado de este nodo
     */
    public function getSheetRecipients(): Collection
    {
        return $this->sheetRecipients;
    }

    /**
     * @param Partner $partner Quien pasa a recibir el listado de este nodo.
     */
    public function addSheetRecipient(Partner $partner): self
    {
        if (!$this->sheetRecipients->contains($partner)) {
            $this->sheetRecipients->add($partner);
        }

        return $this;
    }

    /**
     * @param Partner $partner Quien deja de recibir el listado de este nodo.
     */
    public function removeSheetRecipient(Partner $partner): self
    {
        $this->sheetRecipients->removeElement($partner);

        return $this;
    }

    /**
     * Direcciones a las que mandar el listado de este nodo.
     *
     * Descarta a quien no tenga correo en su ficha en vez de fallar: es un dato
     * que puede faltar y no debe tumbar el envío a los demás. Devuelve lista
     * vacía si el nodo no tiene a nadie asignado, que es la señal de que hay que
     * caer al ajuste general.
     *
     * @return string[]
     */
    public function sheetRecipientEmails(): array
    {
        $emails = [];
        foreach ($this->sheetRecipients as $partner) {
            $email = $partner->getEmail();
            if ($email) {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    /**
     * @return bool true si este punto monta sus cestas con voluntariado
     */
    public function isDeliveryPrep(): bool
    {
        return $this->deliveryPrep;
    }

    /**
     * @param bool $deliveryPrep true si este punto monta sus cestas con voluntariado
     */
    public function setDeliveryPrep(bool $deliveryPrep): self
    {
        $this->deliveryPrep = $deliveryPrep;

        return $this;
    }

    /**
     * @return int|null cuánta gente hace falta para montar, o null si no hay tope
     */
    public function getDeliveryPrepSlots(): ?int
    {
        return $this->deliveryPrepSlots;
    }

    /**
     * @param int|null $deliveryPrepSlots cuánta gente hace falta; null para no poner tope
     */
    public function setDeliveryPrepSlots(?int $deliveryPrepSlots): self
    {
        $this->deliveryPrepSlots = $deliveryPrepSlots;

        return $this;
    }

    /**
     * @return \DateTimeInterface|null a qué hora empieza el montaje, o null
     */
    public function getDeliveryPrepTime(): ?\DateTimeInterface
    {
        return $this->deliveryPrepTime;
    }

    /**
     * @param \DateTimeInterface|null $deliveryPrepTime a qué hora empieza el montaje
     */
    public function setDeliveryPrepTime(?\DateTimeInterface $deliveryPrepTime): self
    {
        $this->deliveryPrepTime = $deliveryPrepTime;

        return $this;
    }

    /**
     * @return int|null cuánto dura el montaje en minutos, o null si no se dice
     */
    public function getDeliveryPrepMinutes(): ?int
    {
        return $this->deliveryPrepMinutes;
    }

    /**
     * @param int|null $deliveryPrepMinutes cuánto dura el montaje en minutos
     */
    public function setDeliveryPrepMinutes(?int $deliveryPrepMinutes): self
    {
        $this->deliveryPrepMinutes = $deliveryPrepMinutes;

        return $this;
    }

    /**
     * @return int días entre el montaje y la entrega: 0 el mismo día, -1 la víspera
     */
    public function getDeliveryPrepDayOffset(): int
    {
        return $this->deliveryPrepDayOffset;
    }

    /**
     * @param int $deliveryPrepDayOffset 0 el mismo día, -1 la víspera
     */
    public function setDeliveryPrepDayOffset(int $deliveryPrepDayOffset): self
    {
        $this->deliveryPrepDayOffset = $deliveryPrepDayOffset;

        return $this;
    }

    /**
     * El tramo horario del montaje, en "HH:MM", tal como lo espera la receta de
     * una tarea de voluntariado: inicio y fin, o inicio y null si este punto no
     * dice cuánto dura.
     *
     * Existe porque la receta habla de HORAS y no de fechas —las fechas se las
     * pregunta al calendario de reparto—, así que quien crea la convocatoria
     * necesita esto y no un momento concreto. Un tramo que cruza la medianoche
     * sale con el fin menor que el inicio, que es exactamente lo que el
     * generador de turnos ya sabe interpretar.
     *
     * Devuelve null en el mismo caso que {@see deliveryPrepWindowFor()}: sin
     * montaje o sin hora no hay tramo que dar.
     *
     * @return array{0: string, 1: string|null}|null inicio y fin en "HH:MM", o null
     */
    public function deliveryPrepTimeSlot(): ?array
    {
        if (!$this->deliveryPrep || null === $this->deliveryPrepTime) {
            return null;
        }

        $start = \DateTimeImmutable::createFromInterface($this->deliveryPrepTime);

        return [
            $start->format('H:i'),
            null === $this->deliveryPrepMinutes
                ? null
                : $start->modify(sprintf('+%d minutes', $this->deliveryPrepMinutes))->format('H:i'),
        ];
    }

    /**
     * Cuándo se montan las cestas que este punto entrega el día que se le pasa:
     * el par inicio/fin de la convocatoria, ya con el desfase y la hora puestos.
     *
     * Es el único sitio donde se hace esta cuenta. Lo consumen quien crea la
     * convocatoria y quien busca qué convocatoria monta un reparto concreto, y
     * si cada uno la repitiera bastaría con que uno olvidara el desfase para que
     * la home del socix dejara de encontrar a quien le prepara la cesta.
     *
     * Devuelve null cuando este punto no monta con voluntariado o todavía no
     * dice a qué hora: sin eso no hay convocatoria posible, y devolver un
     * momento inventado sería peor que no devolver nada.
     *
     * @param \DateTimeInterface $deliveryDate el día en que este punto entrega
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable|null}|null inicio y fin, o null
     */
    public function deliveryPrepWindowFor(\DateTimeInterface $deliveryDate): ?array
    {
        if (!$this->deliveryPrep || null === $this->deliveryPrepTime) {
            return null;
        }

        $start = \DateTimeImmutable::createFromInterface($deliveryDate)
            ->modify(sprintf('%+d days', $this->deliveryPrepDayOffset))
            ->setTime(
                (int) $this->deliveryPrepTime->format('H'),
                (int) $this->deliveryPrepTime->format('i')
            );

        $end = null === $this->deliveryPrepMinutes
            ? null
            : $start->modify(sprintf('+%d minutes', $this->deliveryPrepMinutes));

        return [$start, $end];
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
        return $this->name ?? null;
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
     * @return int|null Día ISO 1=Lunes..7=Domingo.
     */
    public function getDeliveryWeekday(): ?int
    {
        return $this->deliveryWeekday ?? null;
    }

    /**
     * @param int $deliveryWeekday Día ISO 1=Lunes..7=Domingo.
     * @return self
     */
    public function setDeliveryWeekday(int $deliveryWeekday): self
    {
        $this->deliveryWeekday = $deliveryWeekday;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getCadence(): ?string
    {
        return $this->cadence;
    }

    /**
     * @param string $cadence Uno de Node::CADENCE_*.
     * @return self
     * @throws \InvalidArgumentException
     */
    public function setCadence(string $cadence): self
    {
        if (!in_array($cadence, self::CADENCES, true)) {
            throw new \InvalidArgumentException(sprintf('Cadencia inválida: %s', $cadence));
        }
        $this->cadence = $cadence;
        return $this;
    }

    /**
     * @return \DateTimeInterface|null
     */
    public function getAnchorDate(): ?\DateTimeInterface
    {
        return $this->anchorDate;
    }

    /**
     * @param \DateTimeInterface|null $anchorDate
     * @return self
     */
    public function setAnchorDate(?\DateTimeInterface $anchorDate): self
    {
        $this->anchorDate = $anchorDate;
        return $this;
    }

    /**
     * @return int|null Semana del mes que abre el punto (1, 2, 3 o
     *                  MONTHLY_WEEK_LAST), o null si la cadencia no es mensual.
     */
    public function getMonthlyWeek(): ?int
    {
        return $this->monthlyWeek;
    }

    /**
     * @param int|null $monthlyWeek Una de Node::MONTHLY_WEEKS, o null.
     * @return self
     */
    public function setMonthlyWeek(?int $monthlyWeek): self
    {
        $this->monthlyWeek = $monthlyWeek;
        return $this;
    }

    /**
     * ¿Este punto abre una sola semana al mes? Atajo legible para los
     * consumidores, que preguntan esto mucho más que la cadencia cruda.
     *
     * @return bool
     */
    public function isMonthly(): bool
    {
        return $this->cadence === self::CADENCE_MONTHLY;
    }

    /**
     * Modalidades de cesta que caben en este punto, o null si no restringe
     * ninguna. La cadencia del punto manda: en uno quincenal no cabe una cesta
     * de reparto semanal (sólo abre cada dos semanas) y en uno mensual sólo
     * caben las mensuales, porque abre una única vez al mes.
     *
     * Punto único de la regla: lo consumen el formulario de cesta (para acotar
     * el desplegable) y la validación de {@see PartnerBasketShare}, que es la
     * que de verdad la impone.
     *
     * @return int[]|null IDs de BasketShare admitidos, o null si valen todos.
     */
    public function allowedShareIds(): ?array
    {
        if ($this->isMonthly()) {
            return BasketShare::IDS_MONTHLY;
        }

        if ($this->cadence === self::CADENCE_BIWEEKLY) {
            return array_values(array_diff(BasketShare::IDS_ALL, BasketShare::IDS_WEEKLY));
        }

        return null;
    }

    /**
     * Posiciones de mes (el `day_month_order` de una cesta mensual) que este
     * punto sirve TODOS los meses. Es deliberadamente conservadora: sólo
     * incluye las que existen en el mes más corto, porque una posición que
     * unos meses no existe deja al socio sin cesta ese mes y sin ningún aviso.
     *
     * - Semanal: 4 entregas garantizadas al mes → 1ª, 2ª, 3ª y la última.
     * - Quincenal: 2 garantizadas → 1ª, 2ª y la última (la 3ª sólo aparece en
     *   los meses en que el punto abre tres veces, y por eso queda fuera).
     * - Mensual: la única que abre, la que fija `monthly_week`.
     *
     * @return int[] Posiciones admitidas, de la primera a la última.
     */
    public function offeredMonthOrders(): array
    {
        if ($this->isMonthly()) {
            return $this->monthlyWeek !== null ? [$this->monthlyWeek] : self::MONTHLY_WEEKS;
        }

        if ($this->cadence === self::CADENCE_BIWEEKLY) {
            return [1, 2, self::MONTHLY_WEEK_LAST];
        }

        return [1, 2, 3, self::MONTHLY_WEEK_LAST];
    }

    /**
     * Nombre humano de la cadencia, para pintarla en pantalla sin que cada
     * plantilla tenga que mantener su propio mapa de traducciones.
     *
     * @return string
     */
    public function getCadenceLabel(): string
    {
        return self::CADENCE_LABELS[$this->cadence] ?? $this->cadence;
    }

    /**
     * @return string|null Horario público de recogida, o null si no se publica.
     */
    public function getSchedule(): ?string
    {
        return $this->schedule;
    }

    /**
     * @param string|null $schedule Horario público de recogida (texto libre).
     * @return self
     */
    public function setSchedule(?string $schedule): self
    {
        $this->schedule = $schedule;
        return $this;
    }

    /**
     * @return Collection|WeeklyBasketGroup[]
     */
    public function getWeeklyBasketGroups(): Collection
    {
        return $this->weeklyBasketGroups;
    }

    public function addWeeklyBasketGroup(WeeklyBasketGroup $weeklyBasketGroup): self
    {
        if (!$this->weeklyBasketGroups->contains($weeklyBasketGroup)) {
            $this->weeklyBasketGroups[] = $weeklyBasketGroup;
            $weeklyBasketGroup->setNode($this);
        }
        return $this;
    }

    public function removeWeeklyBasketGroup(WeeklyBasketGroup $weeklyBasketGroup): self
    {
        if ($this->weeklyBasketGroups->contains($weeklyBasketGroup)) {
            $this->weeklyBasketGroups->removeElement($weeklyBasketGroup);
            if ($weeklyBasketGroup->getNode() === $this) {
                $weeklyBasketGroup->setNode(null);
            }
        }
        return $this;
    }

    /**
     * Coherencia entre la cadencia y el dato que la concreta: la fecha ancla en
     * los quincenales, la semana del mes en los mensuales.
     *
     * Sin esto se puede guardar un nodo quincenal sin ancla, y entonces
     * cualquier pantalla que calcule fechas de reparto revienta con un 500 al
     * resolver la alternancia
     * ({@see \App\Service\Delivery\NodeDeliveryDate::physicalDateFor}), que es
     * lo que pasó al dar de alta "El Berrueco" el 25-08-2026.
     *
     * Cada cadencia usa un campo y sólo uno. Los campos que no le tocan deben
     * quedar vacíos: un valor huérfano que sobrevive a un cambio de cadencia
     * reaparece después con una configuración que nadie eligió.
     *
     * @param ExecutionContextInterface $context
     * @return void
     */
    #[Assert\Callback]
    public function validateCadenceConsistency(ExecutionContextInterface $context): void
    {
        if ($this->cadence !== self::CADENCE_BIWEEKLY && $this->anchorDate !== null) {
            $context->buildViolation('La fecha ancla sólo se usa en la cadencia quincenal. Déjala vacía.')
                ->atPath('anchorDate')
                ->addViolation();

            return;
        }

        if ($this->cadence !== self::CADENCE_MONTHLY && $this->monthlyWeek !== null) {
            $context->buildViolation('La semana del mes sólo se usa en la cadencia mensual. Déjala vacía.')
                ->atPath('monthlyWeek')
                ->addViolation();

            return;
        }

        if ($this->cadence === self::CADENCE_BIWEEKLY) {
            $this->validateAnchorDate($context);
        }

        if ($this->cadence === self::CADENCE_MONTHLY) {
            $this->validateMonthlyWeek($context);
        }
    }

    /**
     * Reglas del ancla quincenal: existe y cae en el día de reparto del nodo.
     * Lo segundo no es cosmético — la alineación se calcula comparando el ancla
     * con la fecha física del nodo, así que un ancla en otro día de la semana
     * desplaza la cuenta de semanas e invierte la fase, y el punto repartiría
     * justo las semanas contrarias sin ningún error visible.
     *
     * @param ExecutionContextInterface $context
     * @return void
     */
    private function validateAnchorDate(ExecutionContextInterface $context): void
    {
        if ($this->anchorDate === null) {
            $context->buildViolation('Un punto de reparto quincenal necesita una fecha ancla: una fecha en la que sí reparte, para saber qué semanas le tocan.')
                ->atPath('anchorDate')
                ->addViolation();

            return;
        }

        if (!isset($this->deliveryWeekday)) {
            return;
        }

        $anchorWeekday = (int) $this->anchorDate->format('N');
        if ($anchorWeekday !== $this->deliveryWeekday) {
            $context->buildViolation(sprintf(
                'La fecha ancla debe caer en %s, que es el día de reparto de este punto (has elegido un %s).',
                self::WEEKDAY_NAMES[$this->deliveryWeekday] ?? '(día no válido)',
                mb_strtolower(self::WEEKDAY_NAMES[$anchorWeekday] ?? '(día no válido)'),
            ))
                ->atPath('anchorDate')
                ->addViolation();
        }
    }

    /**
     * Reglas de la semana mensual: existe y es una de las ofrecidas. Sin ella
     * no hay forma de saber qué semana abre el punto, que es justo lo que
     * define su calendario.
     *
     * @param ExecutionContextInterface $context
     * @return void
     */
    private function validateMonthlyWeek(ExecutionContextInterface $context): void
    {
        if ($this->monthlyWeek === null) {
            $context->buildViolation('Un punto de reparto mensual necesita saber qué semana del mes abre.')
                ->atPath('monthlyWeek')
                ->addViolation();

            return;
        }

        if (!in_array($this->monthlyWeek, self::MONTHLY_WEEKS, true)) {
            $context->buildViolation('Semana del mes no válida: elige la 1ª, la 2ª, la 3ª o la última.')
                ->atPath('monthlyWeek')
                ->addViolation();
        }
    }

    /**
     * Reglas del montaje con voluntariado: si el punto lo marca, tiene que decir
     * a qué hora, y el montaje no puede caer después de la entrega.
     *
     * Lo primero no es cosmético: la convocatoria se crea sola a partir de estos
     * campos, y sin hora nacería sin momento al que apuntarse — una tarea
     * publicada y vacía, que es exactamente lo que este rediseño viene a evitar.
     *
     * Lo que NO se valida, a propósito, es que sobren datos con la casilla
     * desmarcada: quien apaga el montaje unos meses no tiene por qué perder la
     * hora ni las plazas que ya había configurado.
     *
     * @param ExecutionContextInterface $context
     * @return void
     */
    #[Assert\Callback]
    public function validateDeliveryPrep(ExecutionContextInterface $context): void
    {
        if ($this->deliveryPrepDayOffset > 0) {
            $context->buildViolation('Las cestas se montan antes de entregarlas, no después: elige el mismo día de la entrega o uno anterior.')
                ->atPath('deliveryPrepDayOffset')
                ->addViolation();
        }

        if (!$this->deliveryPrep) {
            return;
        }

        if ($this->deliveryPrepTime === null) {
            $context->buildViolation('Si este punto monta las cestas con voluntariado, hace falta saber a qué hora: sin hora no hay nada a lo que apuntarse.')
                ->atPath('deliveryPrepTime')
                ->addViolation();
        }
    }

    public function __toString(): string
    {
        return $this->getName() ?? '';
    }
}
