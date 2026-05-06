<?php
/**
 * Created by PhpStorm.
 * User: paco
 * Date: 29/01/20
 * Time: 15:04
 * Es para agrupar a los socios en el listado de cestas del viernes
 * Se relaciona con el socio y con la WeeklyBasket ya que si cambia quiero que se guarde en el histórico
 */

namespace App\Entity;


use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * App\Entity\WeeklyBasketGroup
 *
 * @ORM\Table(name="weekly_basket_group")
 * @ORM\Entity(repositoryClass="App\Repository\WeeklyBasketGroupRepository").
 */

class WeeklyBasketGroup
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * Socios de este grupo
     * @ORM\OneToMany(targetEntity="Partner", mappedBy="weekly_basket_group")
     *
     */
    private $partners;


    /**
     * @ORM\Column(type="string", length=255)
     */
    #[Assert\NotBlank]
    private $name;


    /**
     * Es el color que sale en el listado impreso
     * @ORM\Column(type="string", length=255)
     */
    #[Assert\NotBlank]
    private $color;

    /**
     * Controla el histórico de cestas semanales. Es por si un socio se quiere cambiar de grupo, se queda
     * guardado en el histórico de cestas semanales el grupo anterior.
     * @ORM\OneToMany(targetEntity="WeeklyBasket", mappedBy="weekly_basket_group")
     *
     */
    private $weekly_baskets;

    public function __construct()
    {
        $this->partners = new ArrayCollection();
        $this->weekly_baskets = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(string $color): self
    {
        $this->color = $color;

        return $this;
    }

    /**
     * @return Collection|Partner[]
     */
    public function getPartners(): Collection
    {
        return $this->partners;
    }

    public function addPartner(Partner $partner): self
    {
        if (!$this->partners->contains($partner)) {
            $this->partners[] = $partner;
            $partner->setWeeklyBasketGroup($this);
        }

        return $this;
    }

    public function removePartner(Partner $partner): self
    {
        if ($this->partners->contains($partner)) {
            $this->partners->removeElement($partner);
            // set the owning side to null (unless already changed)
            if ($partner->getWeeklyBasketGroup() === $this) {
                $partner->setWeeklyBasketGroup(null);
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
            $weeklyBasket->setWeeklyBasketGroup($this);
        }

        return $this;
    }

    public function removeWeeklyBasket(WeeklyBasket $weeklyBasket): self
    {
        if ($this->weekly_baskets->contains($weeklyBasket)) {
            $this->weekly_baskets->removeElement($weeklyBasket);
            // set the owning side to null (unless already changed)
            if ($weeklyBasket->getWeeklyBasketGroup() === $this) {
                $weeklyBasket->setWeeklyBasketGroup(null);
            }
        }

        return $this;
    }

public function __toString()
{
  return $this->getName();
}
}