<?php
namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @ORM\Entity
 * @ORM\Table(name="city")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\CityRepository")
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
     * @param \Gallinas\AppBundle\Entity\State $state
     * @return City
     */
    public function setState(\Gallinas\AppBundle\Entity\State $state = null)
    {
        $this->state = $state;

        return $this;
    }

    /**
     * Get state
     *
     * @return \Gallinas\AppBundle\Entity\State 
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * Add providers
     *
     * @param \Gallinas\AppBundle\Entity\Provider $providers
     * @return City
     */
    public function addProvider(\Gallinas\AppBundle\Entity\Provider $providers)
    {
        $this->providers[] = $providers;

        return $this;
    }

    /**
     * Remove providers
     *
     * @param \Gallinas\AppBundle\Entity\Provider $providers
     */
    public function removeProvider(\Gallinas\AppBundle\Entity\Provider $providers)
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
}
