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
     * Este valor guarda el orden de la semana en que recibe. Sólo para los mensuales. Si la primera semana (que hay cesta) del mes, la
     * segunda, la tercera o la cuarta. Realmente se hace referencia al viernes correspondiente.
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
     * Cohorte A/B de QUINCENALES. Determina en qué viernes alternos recoge un
     * socio quincenal: es una alternancia semanal continua anclada a una fecha
     * global (ver BiweeklyCohortResolver), pensada para equilibrar la carga de
     * cosecha viernes a viernes.
     *
     * Solo aplica a quincenales: el generador (WeeklyBasketGenerator) consulta
     * la cohorte únicamente para SHARE_BIWEEKLY. Los mensuales se resuelven por
     * day_month_order (qué entrega del mes), NO por A/B; los semanales reciben
     * todos los viernes. Null para semanales y para casos puntuales sin grupo
     * asignado. Ojo: como la alternancia es continua, en meses de 5 viernes una
     * cohorte recoge 3 veces y la otra 2, y la fase se invierte al mes siguiente
     * — A/B NO mapea a viernes ordinales fijos (1º/3º vs 2º/4º).
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
