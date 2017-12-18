<?php
/**
 * Guarda los huevos recogidos por cada usuario en el período determinado.
 * User: paco
 * Date: 27/11/14
 * Time: 17:39
 */

namespace Gallinas\AppBundle\Entity;


use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Collect
 *
 * @ORM\Table(name="egg_registry_user")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\EggRegistryUserRepository")
 */
class EggRegistryUser
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
     * usuario
     * @Assert\NotBlank
     * @var smallint $user
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\User", inversedBy="egg_registry_users")
     */
    private $user;

    /**
     * usuario
     * @Assert\NotBlank
     * @var smallint $egg_registry
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\EggRegistry", inversedBy="egg_registry_users")
     */
    private $egg_registry;

     /**
      * Huevos repartidos para el usuario
     * @Assert\NotBlank
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $total_collected_eggs
     * @ORM\Column(name="total_collected_eggs", type="integer", nullable=true)
     */
    private $total_collected_eggs;


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
     * Set total_collected_eggs
     *
     * @param integer $totalCollectedEggs
     * @return EggRegistryUser
     */
    public function setTotalCollectedEggs($totalCollectedEggs)
    {
        $this->total_collected_eggs = $totalCollectedEggs;

        return $this;
    }

    /**
     * Get total_collected_eggs
     *
     * @return integer 
     */
    public function getTotalCollectedEggs()
    {
        return $this->total_collected_eggs;
    }

    /**
     * Set user
     *
     * @param \Gallinas\AppBundle\Entity\User $user
     * @return EggRegistryUser
     */
    public function setUser(\Gallinas\AppBundle\Entity\User $user = null)
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get user
     *
     * @return \Gallinas\AppBundle\Entity\User 
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set egg_registry
     *
     * @param \Gallinas\AppBundle\Entity\EggRegistry $eggRegistry
     * @return EggRegistryUser
     */
    public function setEggRegistry(\Gallinas\AppBundle\Entity\EggRegistry $eggRegistry = null)
    {
        $this->egg_registry = $eggRegistry;

        return $this;
    }

    /**
     * Get egg_registry
     *
     * @return \Gallinas\AppBundle\Entity\EggRegistry 
     */
    public function getEggRegistry()
    {
        return $this->egg_registry;
    }
}
