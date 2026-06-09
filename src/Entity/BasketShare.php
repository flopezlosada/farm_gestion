<?php
/**
 * Created by diphda.net.
 * User: paco
 * Tipo de cesta semanal o quincenal
 * Date: 30/09/15
 * Time: 21:22
 */

namespace App\Entity;


use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * App\Entity\BasketShare
 *
 * @ORM\Table(name="basket_share")
 * @ORM\Entity(repositoryClass="App\Repository\BasketShareRepository").
 */
class BasketShare
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
     * @var string $name
     * @ORM\Column(name="title", type="string", length=255)
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 255, min: 5)]
    private $name;


    /**
     * Precio de cesta al mes
     * @var smallint $month_price
     * @ORM\Column(name="month_price",type="decimal", precision=8, scale=2)
     */
    private $month_price;


    /**
     * Equivalencia en cestas  completas. Es decir, si es semana, vale 1; si es quincenal, 0,5; si es mensual 0,25; Si solo huevos 0;
     * @var smallint $complete_basket_equivalence
     * @ORM\Column(name="complete_basket_equivalence",type="decimal", precision=8, scale=2)
     */
    private $complete_basket_equivalence;


    /**
     * @var dateTime $created
     *
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created", type="datetime")
     */
    private $created;

    /**
     * @var dateTime $updated
     *
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(name="updated", type="datetime")
     */
    private $updated;



    /**
     * Relaciona el tipo de cesta en la tabla de cesta semanal
     * @ORM\OneToMany(targetEntity="WeeklyBasket", mappedBy="basket_share")
     *
     */
    private $weekly_baskets;


    /**
     * Devuelve una lista de cultivos a las que se asocia la tarea
     * @ORM\OneToMany(targetEntity="PartnerBasketShare", mappedBy="basket_share")
     *
     */
    protected $partner_basket_shares;

    public function __construct()
    {
        $this->partner_basket_shares = new ArrayCollection();
        $this->weekly_baskets = new ArrayCollection();
    }


    public function __toString()
    {
        return $this->getName();
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * @return dateTime
     */
    public function getCreated(): ? \DateTime
    {
        return $this->created;
    }

    /**
     * @param dateTime $created
     */
    public function setCreated(\DateTime $created): void
    {
        $this->created = $created;
    }

    /**
     * @return dateTime
     */
    public function getUpdated(): ? \DateTime
    {
        return $this->updated;
    }

    /**
     * @param dateTime $updated
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

    public function getMonthPrice(): ?string
    {
        return $this->month_price;
    }

    public function setMonthPrice(string $month_price): self
    {
        $this->month_price = $month_price;

        return $this;
    }

    /**
     * @return Collection|PartnerBasketShare[]
     */
    public function getPartnerBasketShares(): Collection
    {
        return $this->partner_basket_shares;
    }

    public function addPartnerBasketShare(PartnerBasketShare $partnerBasketShare): self
    {
        if (!$this->partner_basket_shares->contains($partnerBasketShare)) {
            $this->partner_basket_shares[] = $partnerBasketShare;
            $partnerBasketShare->setBasketShare($this);
        }

        return $this;
    }

    public function removePartnerBasketShare(PartnerBasketShare $partnerBasketShare): self
    {
        if ($this->partner_basket_shares->contains($partnerBasketShare)) {
            $this->partner_basket_shares->removeElement($partnerBasketShare);
            // set the owning side to null (unless already changed)
            if ($partnerBasketShare->getBasketShare() === $this) {
                $partnerBasketShare->setBasketShare(null);
            }
        }

        return $this;
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
            $weeklyBasket->setBasketShare($this);
        }

        return $this;
    }

    public function removeWeeklyBasket(WeeklyBasket $weeklyBasket): self
    {
        if ($this->weekly_baskets->contains($weeklyBasket)) {
            $this->weekly_baskets->removeElement($weeklyBasket);
            // set the owning side to null (unless already changed)
            if ($weeklyBasket->getBasketShare() === $this) {
                $weeklyBasket->setBasketShare(null);
            }
        }

        return $this;
    }

    /** Modalidad "solo huevos": la entrega no reparte cesta de verdura. */
    public const ID_ONLY_EGG = 5;
    /** Modalidades compartidas (dos familias parten una cesta física). */
    public const IDS_SHARED = [4, 6, 7];
    /** Modalidad quincenal: única que usa la cohorte A/B (delivery_group). */
    public const ID_BIWEEKLY = 2;
    /**
     * Modalidades de reparto SEMANAL (cada semana): Semanal (1) y Semanal
     * compartida (4). No caben en un punto de cadencia quincenal, que sólo
     * reparte cada dos semanas.
     */
    public const IDS_WEEKLY = [1, 4];

    /**
     * Peso en cestas FÍSICAS que esta modalidad reparte el día de la entrega:
     * solo-huevos (5) = 0 (no lleva verdura); compartidas (4/6/7) = ½ (dos
     * familias, una cesta); el resto = 1.
     *
     * OJO: NO confundir con complete_basket_equivalence, que amortiza el VALOR
     * de la cesta a lo largo del mes (quincenal 0,5, mensual 0,25). Aquí no
     * amortizamos nada: una quincenal, el día que reparte, es 1 cesta entera.
     * Este peso es lo que cuenta el reparto; aquél, lo que cuenta el cobro.
     *
     * @return float Cestas físicas por unidad de WeeklyBasket::amount.
     */
    public function getDeliveredBasketWeight(): float
    {
        if ($this->id === self::ID_ONLY_EGG) {
            return 0.0;
        }
        return in_array($this->id, self::IDS_SHARED, true) ? 0.5 : 1.0;
    }

    public function getCompleteBasketEquivalence(): ?int
    {
        return $this->complete_basket_equivalence;
    }

    public function setCompleteBasketEquivalence(string $complete_basket_equivalence): self
    {
        $this->complete_basket_equivalence = $complete_basket_equivalence;

        return $this;
    }


}
