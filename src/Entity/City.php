<?php
namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity
 * @ORM\Table(name="city")
 * @ORM\Entity(repositoryClass="App\Repository\CityRepository")
 */
class City
{
  /**
   * @ORM\Id
   * @ORM\Column(type="integer")
   * @ORM\GeneratedValue(strategy="AUTO")
   */
  private $id;

  /**
   * @Assert\NotBlank
   * @var smallint $state
   * @ORM\ManyToOne(targetEntity="State", inversedBy="cities")
   */
  private $state;

  /**
   * @Assert\NotBlank
   * @var string name
   * @ORM\Column(name="name", type="string", length=255)
   */
  private $name;

    /**
     * @ORM\OneToMany(targetEntity="Provider", mappedBy="city")
     */
    protected $providers;


    /**
     * @ORM\OneToMany(targetEntity="Partner", mappedBy="city")
     */
    protected $partners;




  public function __toString()
  {
    return $this->name;
  }
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->providers = new \Doctrine\Common\Collections\ArrayCollection();
        $this->partners = new ArrayCollection();
    }

    /**
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name
     *
     * @param string $name
     * @return City
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name
     *
     * @return string 
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set state
     *
     * @param \App\Entity\State $state
     * @return City
     */
    public function setState(\App\Entity\State $state = null)
    {
        $this->state = $state;

        return $this;
    }

    /**
     * Get state
     *
     * @return \App\Entity\State 
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * Add providers
     *
     * @param \App\Entity\Provider $providers
     * @return City
     */
    public function addProvider(\App\Entity\Provider $providers)
    {
        $this->providers[] = $providers;

        return $this;
    }

    /**
     * Remove providers
     *
     * @param \App\Entity\Provider $providers
     */
    public function removeProvider(\App\Entity\Provider $providers)
    {
        $this->providers->removeElement($providers);
    }

    /**
     * Get providers
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getProviders()
    {
        return $this->providers;
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
            $partner->setCity($this);
        }

        return $this;
    }

    public function removePartner(Partner $partner): self
    {
        if ($this->partners->contains($partner)) {
            $this->partners->removeElement($partner);
            // set the owning side to null (unless already changed)
            if ($partner->getCity() === $this) {
                $partner->setCity(null);
            }
        }

        return $this;
    }
}
