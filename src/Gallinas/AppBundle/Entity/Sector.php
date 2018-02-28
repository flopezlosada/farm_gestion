<?php
/**
 * Created by diphda.net.
 * User: paco
 * Date: 13/11/17
 * Time: 16:39
 */

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Sector
 *
 * @ORM\Table(name="sector")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\SectorRepository")
 */
class Sector
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
     * @Assert\NotBlank
     * @var string name
     * @ORM\Column(name="name", type="string", length=255)
     */
    private $name;

    /**
     * @Assert\NotBlank
     * @var smallint $zone
     * @ORM\ManyToOne(targetEntity="Zone", inversedBy="sectors")
     */
    private $zone;


    /**
     * lista de trabajos culturales realizados
     * @ORM\ManyToMany(targetEntity="CulturalWork", mappedBy="sectors")
     * @ORM\JoinTable(name="sector_cultural_work")
     */
    private $cultural_works;

    /**
     * @ORM\OneToMany(targetEntity="SeedWork", mappedBy="sector")
     */
    protected $seed_works;

    /**
     * @ORM\OneToMany(targetEntity="Movement", mappedBy="sector")
     */
    protected $movements;


    public function __toString()
    {
        return $this->getZone()->getName()."-".$this->getName();
    }

    /**
     * Devuelve una lista de cultivos a las que se asocia la tarea
     * @ORM\ManyToMany(targetEntity="CropWorking", inversedBy="sectors")
     * @ORM\JoinTable(name="crop_working_sector")
     */
    private $crop_workings;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->crop_workings = new \Doctrine\Common\Collections\ArrayCollection();
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
     * @return Sector
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
     * Set zone
     *
     * @param \Gallinas\AppBundle\Entity\Zone $zone
     * @return Sector
     */
    public function setZone(\Gallinas\AppBundle\Entity\Zone $zone = null)
    {
        $this->zone = $zone;

        return $this;
    }

    /**
     * Get zone
     *
     * @return \Gallinas\AppBundle\Entity\Zone 
     */
    public function getZone()
    {
        return $this->zone;
    }

    /**
     * Add crop_workings
     *
     * @param \Gallinas\AppBundle\Entity\CropWorking $cropWorkings
     * @return Sector
     */
    public function addCropWorking(\Gallinas\AppBundle\Entity\CropWorking $cropWorkings)
    {
        $this->crop_workings[] = $cropWorkings;

        return $this;
    }

    /**
     * Remove crop_workings
     *
     * @param \Gallinas\AppBundle\Entity\CropWorking $cropWorkings
     */
    public function removeCropWorking(\Gallinas\AppBundle\Entity\CropWorking $cropWorkings)
    {
        $this->crop_workings->removeElement($cropWorkings);
    }

    /**
     * Get crop_workings
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getCropWorkings()
    {
        return $this->crop_workings;
    }

    /**
     * Add cultural_works
     *
     * @param \Gallinas\AppBundle\Entity\CulturalWork $culturalWorks
     * @return Sector
     */
    public function addCulturalWork(\Gallinas\AppBundle\Entity\CulturalWork $culturalWorks)
    {
        $this->cultural_works[] = $culturalWorks;

        return $this;
    }

    /**
     * Remove cultural_works
     *
     * @param \Gallinas\AppBundle\Entity\CulturalWork $culturalWorks
     */
    public function removeCulturalWork(\Gallinas\AppBundle\Entity\CulturalWork $culturalWorks)
    {
        $this->cultural_works->removeElement($culturalWorks);
    }

    /**
     * Get cultural_works
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getCulturalWorks()
    {
        return $this->cultural_works;
    }

    /**
     * Add seed_works
     *
     * @param \Gallinas\AppBundle\Entity\SeedWork $seedWorks
     * @return Sector
     */
    public function addSeedWork(\Gallinas\AppBundle\Entity\SeedWork $seedWorks)
    {
        $this->seed_works[] = $seedWorks;

        return $this;
    }

    /**
     * Remove seed_works
     *
     * @param \Gallinas\AppBundle\Entity\SeedWork $seedWorks
     */
    public function removeSeedWork(\Gallinas\AppBundle\Entity\SeedWork $seedWorks)
    {
        $this->seed_works->removeElement($seedWorks);
    }

    /**
     * Get seed_works
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getSeedWorks()
    {
        return $this->seed_works;
    }

    /**
     * Add movements
     *
     * @param \Gallinas\AppBundle\Entity\Movement $movements
     * @return Sector
     */
    public function addMovement(\Gallinas\AppBundle\Entity\Movement $movements)
    {
        $this->movements[] = $movements;

        return $this;
    }

    /**
     * Remove movements
     *
     * @param \Gallinas\AppBundle\Entity\Movement $movements
     */
    public function removeMovement(\Gallinas\AppBundle\Entity\Movement $movements)
    {
        $this->movements->removeElement($movements);
    }

    /**
     * Get movements
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getMovements()
    {
        return $this->movements;
    }
}
