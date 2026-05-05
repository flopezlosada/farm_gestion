<?php
/**
 * Created by PhpStorm.
 * User: paco
 * SEmanalmente se crea un registro con las cestas de esa semana. Esta entidad guarda el listado de cestas de cada
 * semana. Se generará automáticamente y se le podrán hacer modificaciones
 * Date: 8/01/20
 * Time: 12:46
 */

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ORM\Table(name="weekly_basket")
 * @ORM\Entity(repositoryClass="App\Repository\WeeklyBasketRepository")
 */

class WeeklyBasket
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;


    /**
     *
     * @ORM\ManyToOne(targetEntity="Partner", inversedBy="weekly_baskets")
     *
     */
    protected $partner;

    /**
     *
     * @ORM\ManyToOne(targetEntity="WeeklyBasketGroup", inversedBy="weekly_baskets")
     *
     */
    protected $weekly_basket_group;


    /**
     *
     * @ORM\ManyToOne(targetEntity="Basket", inversedBy="weekly_baskets")
     *
     */
    protected $basket;


    /**
     *
     * @ORM\ManyToOne(targetEntity="BasketShare", inversedBy="weekly_baskets")
     *
     */
    protected $basket_share;

    /**
     * @var smallint $weekly_basket_status
     * @ORM\ManyToOne(targetEntity="WeeklyBasketStatus", inversedBy="weekly_baskets")
     * según si ha sido recogida, rechazada, atrasada...
     */
    private $weekly_basket_status;


    /**
     * Cantidad cestas asociadas a este socio. Si hay más de una cesta, se entiende que todas son iguales en
     * periodicidad de verdurdas, y cantidad y periodicidad de huevos
     * @var smallint $amount
     * @ORM\Column(name="amount", type="integer", options={"default" : 1})
     */
    private $amount;


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPartner(): ?Partner
    {
        return $this->partner;
    }

    public function setPartner(?Partner $partner): self
    {
        $this->partner = $partner;

        return $this;
    }

    public function getBasket(): ?Basket
    {
        return $this->basket;
    }

    public function setBasket(?Basket $basket): self
    {
        $this->basket = $basket;

        return $this;
    }

    public function getWeeklyBasketStatus(): ?WeeklyBasketStatus
    {
        return $this->weekly_basket_status;
    }

    public function setWeeklyBasketStatus(?WeeklyBasketStatus $weekly_basket_status): self
    {
        $this->weekly_basket_status = $weekly_basket_status;

        return $this;
    }

    public function getPartnerBasketShare(): ?PartnerBasketShare
    {
        return $this->partner_basket_share;
    }

    public function setPartnerBasketShare(?PartnerBasketShare $partner_basket_share): self
    {
        $this->partner_basket_share = $partner_basket_share;

        return $this;
    }

    public function getBasketShare(): ?BasketShare
    {
        return $this->basket_share;
    }

    public function setBasketShare(?BasketShare $basket_share): self
    {
        $this->basket_share = $basket_share;

        return $this;
    }

    public function getWeeklyBasketGroup(): ?WeeklyBasketGroup
    {
        return $this->weekly_basket_group;
    }

    public function setWeeklyBasketGroup(?WeeklyBasketGroup $weekly_basket_group): self
    {
        $this->weekly_basket_group = $weekly_basket_group;

        return $this;
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




}