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
 * Gallinas\AppBundle\Entity\Variety
 *
 * @ORM\Table(name="variety")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\VarietyRepository")
 */
class Variety
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
     * @var smallint $crop
     * @ORM\ManyToOne(targetEntity="Crop", inversedBy="varieties")
     */
    private $crop;
    
    /**
     * @Assert\NotBlank
     * @var string name
     * @ORM\Column(name="name", type="string", length=255)
     */
    private $name;

    /**
     * @ORM\OneToMany(targetEntity="CropWorking", mappedBy="variety")
     */
    protected $crop_workings;

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
     *
     * @return Variety
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
     * Set created
     *
     * @param \DateTime $created
     *
     * @return Variety
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
     * @return Variety
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
     * Set crop
     *
     * @param \Gallinas\AppBundle\Entity\Crop $crop
     *
     * @return Variety
     */
    public function setCrop(\Gallinas\AppBundle\Entity\Crop $crop = null)
    {
        $this->crop = $crop;

        return $this;
    }

    /**
     * Get crop
     *
     * @return \Gallinas\AppBundle\Entity\Crop
     */
    public function getCrop()
    {
        return $this->crop;
    }

    /**
     * Add cropWorking
     *
     * @param \Gallinas\AppBundle\Entity\CropWorking $cropWorking
     *
     * @return Variety
     */
    public function addCropWorking(\Gallinas\AppBundle\Entity\CropWorking $cropWorking)
    {
        $this->crop_workings[] = $cropWorking;

        return $this;
    }

    /**
     * Remove cropWorking
     *
     * @param \Gallinas\AppBundle\Entity\CropWorking $cropWorking
     */
    public function removeCropWorking(\Gallinas\AppBundle\Entity\CropWorking $cropWorking)
    {
        $this->crop_workings->removeElement($cropWorking);
    }

    /**
     * Get cropWorkings
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCropWorkings()
    {
        return $this->crop_workings;
    }

    public function __toString()
    {
        return $this->getCrop()->getName()." (".$this->getName().")";
    }
}
