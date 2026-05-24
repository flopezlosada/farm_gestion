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
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * App\Entity\PartnerBasketShare
 *
 * @ORM\Table(name="partner_basket_share")
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
     * Grupo de reparto A/B. Sirve para equilibrar la carga de cosecha viernes
     * a viernes en quincenales y mensuales: la persona que cosecha quiere que
     * cada semana haya aproximadamente la misma cantidad de cestas.
     *
     * Null para suscripciones semanales (reciben todos los viernes y por
     * tanto no participan del balanceo) y para casos puntuales que aún no
     * tengan grupo asignado.
     *
     * @ORM\Column(name="delivery_group", type="string", length=1, nullable=true)
     */
    #[Assert\Choice(choices: ['A', 'B'], message: 'Grupo de reparto inválido (solo A o B).')]
    private ?string $delivery_group = null;




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
}
