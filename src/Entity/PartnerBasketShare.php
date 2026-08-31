<?php
/**
 * Created by diphda.net.
 * User: paco
 * relación histórica de cestas de un socio
 * Date: 30/09/15
 * Time: 21:22
 */

namespace App\Entity;


use App\Form\PartnerBasketShareType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * App\Entity\PartnerBasketShare
 *
 * @ORM\Table(name="partner_basket_share", indexes={
 *     @ORM\Index(name="IDX_pbs_payer_partner", columns={"payer_partner_id"})
 * })
 * @ORM\Entity(repositoryClass="App\Repository\PartnerBasketShareRepository").
 */
class PartnerBasketShare
{
    /**
     * @var integer $id
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;


    /**
     * @var \DateTime $created
     *
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created", type="datetime")
     */
    private $created;

    /**
     * @var \DateTime $updated
     *
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(name="updated", type="datetime")
     */
    private $updated;


    /**
     * Devuelve una lista de cultivos a las que se asocia la tarea
     * @ORM\ManyToOne(targetEntity="Partner", inversedBy="partner_basket_shares")
     *
     */
    protected $partner;

    /**
     * Semanal, quincenal o mensual
     * @ORM\ManyToOne(targetEntity="BasketShare", inversedBy="partner_basket_shares")
     *
     */
    protected $basket_share;

    /**
     * Orden de la entrega del mes en que recibe. Sólo para los mensuales.
     * Positivo cuenta desde el principio (1 = primera) y -1 = la última, que
     * sigue al último reparto del mes tenga éste 4 o 5 semanas.
     *
     * SOBRE QUÉ se cuenta depende de {@see $delivery_group}:
     *  - Sin turno: sobre las entregas del NODO en el mes (en Torremocha, los
     *    viernes; en Cascorro/Midori, las semanas de su ciclo quincenal).
     *  - Con turno: sobre las entregas de ESE TURNO en el mes, para que el
     *    mensual coincida siempre con su grupo (caso Alcobendas).
     *
     * Resuelto en runtime por {@see \App\Service\Delivery\MonthlyOperativeOrderResolver}.
     *
     * @var smallint $day_month_order
     * @ORM\Column(type="smallint",nullable=true)
     */
    protected $day_month_order;

    /**
     * Análogo a day_month_order pero para huevos. Sólo aplica cuando egg_period
     * es Mensual y la frecuencia de huevos difiere de la frecuencia de la cesta
     * (caso testigo: cesta Quincenal + huevos Mensuales). Indica en qué viernes
     * operativo del mes (1..4) toca entregar huevos.
     *
     * Null para Semanal/Quincenal (la cohorte se resuelve por delivery_group) y
     * para mensuales con patrones no derivables del CSV. Resuelto en runtime por
     * EggDeliveryResolver.
     *
     * @ORM\Column(type="smallint", nullable=true)
     */
    protected ?int $egg_day_month_order = null;


    /**
     *Este valor es por si alguien alguna vez pide dos cestas para el mismo socio.
     *En principio no lo voy a usar para nada, pero lo pongo a 1 en todas
     * @var smallint $vegetables_basket_amount
     * @ORM\Column(type="smallint",nullable=false, options={"default" = 1})
     */
    protected $vegetables_basket_amount=1;

    /**
     * Fecha de inicio de cesta
     * @var string $start_date
     * @ORM\Column(name="start_date", type="date", nullable=true)
     */
    private $start_date;

    /**
     * Fecha de fin o de cambio de cesta
     * @var string $end_date
     * @ORM\Column(name="end_date", type="date", nullable=true)
     */
    private $end_date;

    /**
     * Precio de cesta de verduras al mes
     * @var smallint $month_price
     * @ORM\Column(name="month_price",type="decimal", precision=8, scale=2)
     */
    private $month_price;


    /**
     * Devuelve una lista de cestas a las que se asocia
     * @ORM\ManyToOne(targetEntity="EggAmount", inversedBy="partner_basket_shares")
     */
    private $egg_amount;


    /**
     * Precio de cesta de huevos al mes. Se calcula
     * @var smallint $egg_month_price
     * @ORM\Column(name="egg_month_price",type="decimal", precision=8, scale=2)
     */
    private $egg_month_price;

    /**
     * Aporte de transporte al mes. Algunos grupos cobran transporte aparte
     * (importe_transp_eur en el CSV de COBROS). Nullable: la mayoría de
     * socios no lo paga.
     * @ORM\Column(name="transport_price", type="decimal", precision=8, scale=2, nullable=true)
     */
    private ?string $transport_price = null;

    /**
     * Período de recogida de huevos. Es para los que sólo tienen huevos
     * @ORM\ManyToOne(targetEntity="EggPeriod", inversedBy="partner_basket_shares")
     */
    private $egg_period;

    /**
     * Es para saber qué cesta es la que está usando ahora
     * @ORM\Column(type="boolean", nullable=true)
     */
    private $is_active;

    /**
     * parientes
     * One Category has Many Categories.
     * @Orm\OneToMany(targetEntity="WeeklyBasket", mappedBy="basket_share")
     * @Orm\OrderBy({"id"="DESC"})
     */
    private $weekly_baskets;

    /**
     * @var esto es un control para las cestas gratuitas. Si está en 1 pone el precio egg_month_price a 0 €
     * No pasa a la bbdd
     */
    private $isFreeBasket=false;

    /**
     * Cantidad cestas asociadas a este socio. Si hay más de una cesta, se entiende que todas son iguales en
     * periodicidad de verdurdas, y cantidad y periodicidad de huevos
     * @var smallint $amount
     * @ORM\Column(name="amount", type="smallint", options={"default" : 1})
     */
    #[Assert\NotBlank]
    #[Assert\Type(type: 'numeric', message: 'El valor {{value}} no es un {{type}} válido.')]
    #[Assert\GreaterThan(value: 0)]
    private $amount;

    public const DELIVERY_GROUP_A = 'A';
    public const DELIVERY_GROUP_B = 'B';
    public const DELIVERY_GROUPS = [self::DELIVERY_GROUP_A, self::DELIVERY_GROUP_B];

    /**
     * "Última entrega del mes" como valor de `day_month_order`. Contado desde
     * el final a propósito: la última es la 4ª en un mes de 4 semanas y la 5ª
     * en uno de 5, así que un 4 fijo significaría cosas distintas según el mes.
     *
     * Mismo criterio y mismo valor que {@see Node::MONTHLY_WEEK_LAST}, que es
     * la semana que abre un punto de cadencia mensual; la coherencia entre
     * ambos la blinda un test.
     */
    public const DAY_MONTH_ORDER_LAST = -1;

    /**
     * Cohorte A/B de QUINCENALES. Determina en qué viernes alternos recoge un
     * socio quincenal: es una alternancia semanal continua anclada a una fecha
     * global (ver BiweeklyCohortResolver), pensada para equilibrar la carga de
     * cosecha viernes a viernes.
     *
     * Para un QUINCENAL decide en qué viernes recoge, y es obligatorio en nodos
     * de cadencia semanal (sin turno cae de los listados). Los semanales reciben
     * todos los viernes y no lo usan.
     *
     * Para un MENSUAL es OPCIONAL y no decide si recoge, sino sobre qué
     * calendario se cuenta su {@see $day_month_order}: con turno, sobre las
     * entregas de ese turno en el mes; sin turno, sobre los viernes del mes.
     * Es lo que permite a un mensual coincidir siempre con el reparto de su
     * grupo (caso Alcobendas, 2026-07-30) sin retocarlo a mano cada mes de 5
     * viernes. Ver {@see \App\Entity\BasketShare::usesDeliveryGroup}.
     *
     * Null en semanales, en nodos de cadencia quincenal (donde el turno lo fija
     * el propio punto) y en mensuales no anclados. Ojo: como la alternancia es
     * continua, en meses de 5 viernes una cohorte recoge 3 veces y la otra 2, y
     * la fase se invierte al mes siguiente — A/B NO mapea a viernes ordinales
     * fijos (1º/3º vs 2º/4º). Ése es justamente el motivo del anclaje.
     *
     * @ORM\Column(name="delivery_group", type="string", length=1, nullable=true)
     */
    #[Assert\Choice(choices: ['A', 'B'], message: 'Grupo de reparto inválido (solo A o B).')]
    private ?string $delivery_group = null;

    /**
     * Pagador externo de esta cesta (sub-fase 8.8d, 2026-05-26). Null = el
     * receptor (this->partner) paga su propia cesta, caso por defecto. Si
     * apunta a otro Partner, ese partner es quien aparece en cobros como
     * titular del IBAN de esta PBS.
     *
     * Modela la opción que la asociación ofrece de "pagar la cesta de
     * otra persona" — donaciones nominadas (María Puebla → Nayua), o
     * relaciones de soporte (Pablo Angulo → Nuria del Río).
     *
     * @ORM\ManyToOne(targetEntity="Partner")
     * @ORM\JoinColumn(name="payer_partner_id", referencedColumnName="id", nullable=true)
     */
    private ?Partner $payer_partner = null;




    public function __construct()
    {
        $this->weekly_baskets = new ArrayCollection();
    }

    /**
     * @return \DateTime
     */
    public function getCreated(): ?\DateTime
    {
        return $this->created;
    }

    /**
     * @param \DateTime $created
     */
    public function setCreated(\DateTime $created): void
    {
        $this->created = $created;
    }

    /**
     * @return \DateTime
     */
    public function getUpdated(): ?\DateTime
    {
        return $this->updated;
    }

    /**
     * @param \DateTime $updated
     */
    public function setUpdated(\DateTime $updated): void
    {
        $this->updated = $updated;
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @param int $id
     */
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * @return mixed
     */
    public function getPartner()
    {
        return $this->partner;
    }

    /**
     * @param mixed $partner
     */
    public function setPartner($partner): void
    {
        $this->partner = $partner;
    }

    /**
     * @return mixed
     */
    public function getBasketShare()
    {
        return $this->basket_share;
    }

    /**
     * @param mixed $basket_share
     */
    public function setBasketShare($basket_share): void
    {
        $this->basket_share = $basket_share;
    }

    /**
     * @return \DateTime
     */
    public function getStartDate()
    {
        return $this->start_date;
    }

    /**
     * @param \DateTime $start_date
     */
    public function setStartDate($start_date): void
    {
        $this->start_date = $start_date;
    }


    /**
     * Set end_date
     *
     * @param \DateTime $endDate
     * @return PartnerBasketShare
     */
    public function setEndDate($endDate)
    {
        $this->end_date = $endDate;

        return $this;
    }

    /**
     * @return  \DateTime
     */
    public function getEndDate()
    {
        return $this->end_date;
    }


    /**
     * @return smallint
     */
    public function getMonthPrice()
    {
        return $this->month_price;
    }

    /**
     * @param smallint $month_price
     */
    public function setMonthPrice($month_price): void
    {
        $this->month_price = $month_price;
    }

    public function getIsActive(): ?bool
    {
        return $this->is_active;
    }

    public function setIsActive(?bool $is_active): self
    {
        $this->is_active = $is_active;

        return $this;
    }

    public function getDate()
    {
        return $this->getStartDate();
    }

    /**
     * @return Collection|WeeklyBasket[]
     */
    public function getWeeklyBaskets(): Collection
    {
        return $this->weekly_baskets;
    }

    public function addWeeklyBasket(WeeklyBasket $weeklyBasket): self
    {
        if (!$this->weekly_baskets->contains($weeklyBasket)) {
            $this->weekly_baskets[] = $weeklyBasket;
            $weeklyBasket->setPartnerBasketShare($this);
        }

        return $this;
    }

    public function removeWeeklyBasket(WeeklyBasket $weeklyBasket): self
    {
        if ($this->weekly_baskets->contains($weeklyBasket)) {
            $this->weekly_baskets->removeElement($weeklyBasket);
            // set the owning side to null (unless already changed)
            if ($weeklyBasket->getPartnerBasketShare() === $this) {
                $weeklyBasket->setPartnerBasketShare(null);
            }
        }

        return $this;
    }

    public function getDayMonthOrder(): ?int
    {
        return $this->day_month_order;
    }

    public function setDayMonthOrder(?int $day_month_order): self
    {
        $this->day_month_order = $day_month_order;

        return $this;
    }

    public function getEggDayMonthOrder(): ?int
    {
        return $this->egg_day_month_order;
    }

    public function setEggDayMonthOrder(?int $egg_day_month_order): self
    {
        $this->egg_day_month_order = $egg_day_month_order;

        return $this;
    }

    public function getVegetablesBasketAmount(): ?int
    {
        return $this->vegetables_basket_amount;
    }

    public function setVegetablesBasketAmount(int $vegetables_basket_amount): self
    {
        $this->vegetables_basket_amount = $vegetables_basket_amount;

        return $this;
    }

    public function getEggMonthPrice(): ?string
    {
        return $this->egg_month_price;
    }

    public function setEggMonthPrice(string $egg_month_price): self
    {
        $this->egg_month_price = $egg_month_price;

        return $this;
    }

    public function getTransportPrice(): ?string
    {
        return $this->transport_price;
    }

    public function setTransportPrice(?string $transport_price): self
    {
        $this->transport_price = $transport_price;

        return $this;
    }

    public function getEggAmount(): ?EggAmount
    {
        return $this->egg_amount;
    }

    public function setEggAmount(?EggAmount $egg_amount): self
    {
        $this->egg_amount = $egg_amount;

        return $this;
    }

    public function getEggPeriod(): ?EggPeriod
    {
        return $this->egg_period;
    }

    public function setEggPeriod(?EggPeriod $egg_period): self
    {
        $this->egg_period = $egg_period;

        return $this;
    }

    /**
     * @return $this
     */
    public function getisFreeBasket()
    {
        return $this->isFreeBasket;
    }

    /**
     * @param $this $isFreeBasket
     */
    public function setIsFreeBasket( $isFreeBasket)
    {
        $this->isFreeBasket = $isFreeBasket;
    }

    public function getAmount(): ?int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * Una cesta COMPARTIDA (semanal/quincenal/mensual compartida: 4/6/7) es
     * media cesta repartida entre dos familias; su cantidad es siempre 1. Pedir
     * 2+ aquí no modela "dos compartidas" sino que dobla la entrega — es el error
     * que dejó a Ana Villa con "2 cestas" (2026-06-30). Para una entrega puntual
     * de más, el camino correcto es «Añadir cesta extra» (PartnerBasketExtra), no
     * subir el amount de la suscripción. Cubre alta, edición, cambio de modalidad
     * y el comando CLI por igual.
     */
    #[Assert\Callback]
    public function validateSharedBasketAmount(ExecutionContextInterface $context): void
    {
        if (in_array($this->basket_share?->getId(), BasketShare::IDS_SHARED, true) && (int) $this->amount > 1) {
            $context->buildViolation('Una cesta compartida lleva siempre 1. Si necesitas una entrega puntual de más, usa «Añadir cesta extra».')
                ->atPath('amount')
                ->addViolation();
        }
    }

    /**
     * Una cesta sólo puede pedir lo que su punto de recogida ofrece. Sin esto,
     * el formulario deja guardar combinaciones que el motor de reparto no puede
     * servir y el socio DESAPARECE del listado sin ningún aviso — que es
     * exactamente lo que pasó con los dos socios de El Berrueco (2026-08-26):
     * cesta mensual con la posición del mes en blanco, invisible desde el alta.
     *
     * Tres reglas, todas contra el {@see Node} del socio:
     *  1. La modalidad tiene que caber en el punto ({@see Node::allowedShareIds}):
     *     una cesta semanal no cabe en un punto que abre cada quince días.
     *  2. Una cesta mensual tiene que decir QUÉ entrega del mes recoge, y tiene
     *     que ser una de las que el punto sirve todos los meses
     *     ({@see Node::offeredMonthOrders}).
     *  3. Una quincenal en un punto semanal necesita turno de viernes: sin él no
     *     entra en ninguna cohorte y cae de los listados igual que la anterior.
     *
     * Vive en la entidad, no en el formulario, para que valga igual al alta, a
     * la corrección de errata y al cambio de modalidad. El socio sin grupo de
     * recogida asignado (dato legacy) no tiene punto contra el que contrastar:
     * ahí sólo se exige la regla 2 en su parte de "no puede quedar en blanco".
     */
    #[Assert\Callback]
    public function validateAgainstNodeOffer(ExecutionContextInterface $context): void
    {
        $share = $this->basket_share;
        if ($share === null) {
            return; // sin modalidad no hay nada que contrastar; lo cubre el propio form.
        }

        $node = $this->partner?->getWeeklyBasketGroup()?->getNode();

        $allowed = $node?->allowedShareIds();
        if ($allowed !== null && !in_array($share->getId(), $allowed, true)) {
            $context->buildViolation(sprintf(
                'El punto de recogida %s no admite cestas de tipo "%s" (reparte con cadencia %s).',
                $node->getName(),
                $share->getName(),
                strtolower($node->getCadenceLabel()),
            ))->atPath('basket_share')->addViolation();
        }

        if ($share->isMonthly()) {
            $this->validateMonthOrder($context, $node);
        }

        $needsTurn = $share->usesDeliveryGroup()
            && !$share->isMonthly()
            && ($node === null || $node->getCadence() === Node::CADENCE_WEEKLY);
        if ($needsTurn && $this->delivery_group === null) {
            $context->buildViolation('Indica el turno de viernes: una cesta quincenal sin turno no entra en ningún reparto.')
                ->atPath('deliveryGroup')
                ->addViolation();
        }
    }

    /**
     * Regla 2 de {@see validateAgainstNodeOffer}: la posición del mes de una
     * cesta mensual. En blanco nunca vale; y si el socio tiene punto, tiene que
     * ser una de las que ese punto abre todos los meses.
     *
     * @param ExecutionContextInterface $context
     * @param Node|null                 $node    Punto del socio, o null si aún no tiene grupo.
     */
    private function validateMonthOrder(ExecutionContextInterface $context, ?Node $node): void
    {
        if ($this->day_month_order === null) {
            $context->buildViolation('Indica qué entrega del mes recoge la cesta: una cesta mensual sin ese dato no aparece en ningún reparto.')
                ->atPath('dayMonthOrder')
                ->addViolation();

            return;
        }

        $offered = $node?->offeredMonthOrders();
        if ($offered !== null && !in_array((int) $this->day_month_order, $offered, true)) {
            $context->buildViolation(sprintf(
                'El punto de recogida %s no sirve esa entrega todos los meses. Elige una de las que sí abre siempre.',
                $node->getName(),
            ))->atPath('dayMonthOrder')->addViolation();
        }
    }

    public function getDeliveryGroup(): ?string
    {
        return $this->delivery_group;
    }

    /**
     * @param string|null $delivery_group Uno de PartnerBasketShare::DELIVERY_GROUP_* o null.
     * @throws \InvalidArgumentException Si el valor no es A, B o null.
     */
    public function setDeliveryGroup(?string $delivery_group): self
    {
        if ($delivery_group !== null && !in_array($delivery_group, self::DELIVERY_GROUPS, true)) {
            throw new \InvalidArgumentException(sprintf('Grupo de reparto inválido: %s', $delivery_group));
        }
        $this->delivery_group = $delivery_group;

        return $this;
    }

    /**
     * @return Partner|null Pagador externo, o null si el receptor paga su propia cesta.
     */
    public function getPayerPartner(): ?Partner
    {
        return $this->payer_partner;
    }

    /**
     * @param Partner|null $payer_partner Pagador distinto al receptor, o null para revertir al caso normal.
     * @return self
     */
    public function setPayerPartner(?Partner $payer_partner): self
    {
        $this->payer_partner = $payer_partner;
        return $this;
    }

    /**
     * @return Partner Pagador efectivo: el externo si está set, si no el propio receptor.
     */
    public function getEffectivePayer(): Partner
    {
        return $this->payer_partner ?? $this->partner;
    }
}
