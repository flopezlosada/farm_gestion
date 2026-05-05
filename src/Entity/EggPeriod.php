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
 * App\Entity\EggPeriod
 *
 * @ORM\Table(name="egg_period")
 * @ORM\Entity(repositoryClass="App\Repository\EggPeriodRepository").
 */
class EggPeriod
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
     * @Assert\NotBlank
     * @Assert\Length(max="255", min="5")
     * @ORM\Column(name="title", type="string", length=255)
     */
    private $name;





    /**
     * Devuelve una lista de cultivos a las que se asocia la tarea
     * @ORM\OneToMany(targetEntity="PartnerBasketShare", mappedBy="egg_period")
     *
     */
    protected $partner_basket_shares;

    public function __construct()
    {
        $this->partner_basket_shares = new ArrayCollection();
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
            $partnerBasketShare->setEggPeriod($this);
        }

        return $this;
    }

    public function removePartnerBasketShare(PartnerBasketShare $partnerBasketShare): self
    {
        if ($this->partner_basket_shares->contains($partnerBasketShare)) {
            $this->partner_basket_shares->removeElement($partnerBasketShare);
            // set the owning side to null (unless already changed)
            if ($partnerBasketShare->getEggPeriod() === $this) {
                $partnerBasketShare->setEggPeriod(null);
            }
        }

        return $this;
    }

    public function __toString()
    {
        return $this->getName();
    }
}
