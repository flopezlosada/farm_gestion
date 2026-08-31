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

    public function __toString(): string
    {
        return $this->getName() ?? '';
    }
}
