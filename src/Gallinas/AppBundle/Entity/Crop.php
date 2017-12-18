<?php
/**
 * Created by diphda.net.
 * User: paco
 * Date: 9/12/15
 * Time: 12:38
 */

namespace Gallinas\AppBundle\Entity;



use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Crop
 *
 * @ORM\Table(name="crop")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\CropRepository")
 */
class Crop
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
     * @ORM\OneToMany(targetEntity="CropWorking", mappedBy="crop")
     */
    protected $crop_workings;

    /**
     * @Assert\NotBlank
     * @var string name
     * @ORM\Column(name="name", type="string", length=255)
     */
    private $name;

    /**
     * Indica si está en producción.
     * @ORM\Column(name="is_in_production", type="boolean", length=1, nullable=false, options={"default" : 0})
     * @var boolean is_in_production
     */
    private $is_in_production;


    /**
     * @var string latin_name
     * @ORM\Column(name="latin_name", type="string", length=255)
     */
    private $latin_name;

    /**
     * @ORM\OneToMany(targetEntity="Variety", mappedBy="crop")
     */
    protected $varieties;

    /**
     * @ORM\OneToMany(targetEntity="Production", mappedBy="crop")
     */
    protected $productions;

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
     * @var Lo uso para tener el listado de productos y su producción en crop/index
     */
    protected $total_production;


    /**
     * Constructor
     */
    public function __construct()
    {
        $this->varieties = new \Doctrine\Common\Collections\ArrayCollection();
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
     *
     * @return Crop
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
     * Set latinName
     *
     * @param string $latinName
     *
     * @return Crop
     */
    public function setLatinName($latinName)
    {
        $this->latin_name = $latinName;

        return $this;
    }

    /**
     * Get latinName
     *
     * @return string
     */
    public function getLatinName()
    {
        return $this->latin_name;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     *
     * @return Crop
     */
    public function setCreated($created)
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Get created
     *
     * @return \DateTime
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Set updated
     *
     * @param \DateTime $updated
     *
     * @return Crop
     */
    public function setUpdated($updated)
    {
        $this->updated = $updated;

        return $this;
    }

    /**
     * Get updated
     *
     * @return \DateTime
     */
    public function getUpdated()
    {
        return $this->updated;
    }

    /**
     * Add variety
     *
     * @param \Gallinas\AppBundle\Entity\Variety $variety
     *
     * @return Crop
     */
    public function addVariety(\Gallinas\AppBundle\Entity\Variety $variety)
    {
        $this->varieties[] = $variety;

        return $this;
    }

    /**
     * Remove variety
     *
     * @param \Gallinas\AppBundle\Entity\Variety $variety
     */
    public function removeVariety(\Gallinas\AppBundle\Entity\Variety $variety)
    {
        $this->varieties->removeElement($variety);
    }

    /**
     * Get varieties
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getVarieties()
    {
        return $this->varieties;
    }

    public function __toString()
    {
        return $this->getFormatedCompleteName();
    }

    public function getFormatedCompleteName()
    {
        return $this->getName()." (".$this->getLatinName().")";
    }



    /**
     * Add productions
     *
     * @param \Gallinas\AppBundle\Entity\Production $productions
     * @return Crop
     */
    public function addProduction(\Gallinas\AppBundle\Entity\Production $productions)
    {
        $this->productions[] = $productions;

        return $this;
    }

    /**
     * Remove productions
     *
     * @param \Gallinas\AppBundle\Entity\Production $productions
     */
    public function removeProduction(\Gallinas\AppBundle\Entity\Production $productions)
    {
        $this->productions->removeElement($productions);
    }

    /**
     * Get productions
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getProductions()
    {
        return $this->productions;
    }

    public function setTotalProduction($production)
    {
        $this->total_production=$production;
    }

    public function getTotalProduction()
    {
        return $this->total_production;
    }

    /**
     * Add crop_workings
     *
     * @param \Gallinas\AppBundle\Entity\CropWorking $cropWorkings
     * @return Crop
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
     * Set is_in_production
     *
     * @param boolean $isInProduction
     * @return Crop
     */
    public function setIsInProduction($isInProduction)
    {
        $this->is_in_production = $isInProduction;

        return $this;
    }

    /**
     * Get is_in_production
     *
     * @return boolean 
     */
    public function getIsInProduction()
    {
        return $this->is_in_production;
    }
}
