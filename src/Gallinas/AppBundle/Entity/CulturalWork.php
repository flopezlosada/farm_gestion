<?php
/**
 * Created by diphda.net.
 * User: paco
 * Date: 8/11/17
 * Time: 18:51
 */

namespace Gallinas\AppBundle\Entity;

namespace Gallinas\AppBundle\Entity;


use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\CulturalWork
 *
 * @ORM\Table(name="cultural_work")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\CulturalWorkRepository")
 */

class CulturalWork
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
     * @var smallint $cultural_work_type
     * @Assert\NotBlank
     * @ORM\ManyToOne(targetEntity="CulturalWorkType", inversedBy="cultural_works")
     */
    private $cultural_work_type;

    /**
     * Devuelve una lista de cultivos a las que se asocia el trabajo
     * @ORM\ManyToMany(targetEntity="CropWorking", inversedBy="cultural_works")
     * @ORM\JoinTable(name="crop_working_cultural_work")
     */
    private $crop_workings;


    /**
     * Devuelve una lista de cultivos a las que se asocia el trabajo
     * @ORM\ManyToMany(targetEntity="Sector", inversedBy="cultural_works")
     * @ORM\JoinTable(name="sector_cultural_work")
     */
    private $sectors;

    /**
     * Fecha realización,
     * @Assert\Date()
     * @var string $date
     * @ORM\Column(name="date", type="date", nullable=true)
     */
    private $date;

    /**
     * @var string $content
     * @Assert\NotBlank
     * @ORM\Column(name="content", type="text")
     */
    private $content;



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
     * Set date
     *
     * @param \DateTime $date
     * @return CulturalWork
     */
    public function setDate($date)
    {
        $this->date = $date;

        return $this;
    }

    /**
     * Get date
     *
     * @return \DateTime 
     */
    public function getDate()
    {
        return $this->date;
    }

    /**
     * Set content
     *
     * @param string $content
     * @return CulturalWork
     */
    public function setContent($content)
    {
        $this->content = $content;

        return $this;
    }

    /**
     * Get content
     *
     * @return string 
     */
    public function getContent()
    {
        return $this->content;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return CulturalWork
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
     * @return CulturalWork
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
     * Set cultural_work_type
     *
     * @param \Gallinas\AppBundle\Entity\CulturalWorkType $culturalWorkType
     * @return CulturalWork
     */
    public function setCulturalWorkType(\Gallinas\AppBundle\Entity\CulturalWorkType $culturalWorkType = null)
    {
        $this->cultural_work_type = $culturalWorkType;

        return $this;
    }

    /**
     * Get cultural_work_type
     *
     * @return \Gallinas\AppBundle\Entity\CulturalWorkType 
     */
    public function getCulturalWorkType()
    {
        return $this->cultural_work_type;
    }

    /**
     * Add crop_workings
     *
     * @param \Gallinas\AppBundle\Entity\CropWorking $cropWorkings
     * @return CulturalWork
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

    public function __toString()
    {
        return $this->getCulturalWorkType()->getTitle();
    }

    /**
     * Add sectors
     *
     * @param \Gallinas\AppBundle\Entity\Sector $sectors
     * @return CulturalWork
     */
    public function addSector(\Gallinas\AppBundle\Entity\Sector $sectors)
    {
        $this->sectors[] = $sectors;

        return $this;
    }

    /**
     * Remove sectors
     *
     * @param \Gallinas\AppBundle\Entity\Sector $sectors
     */
    public function removeSector(\Gallinas\AppBundle\Entity\Sector $sectors)
    {
        $this->sectors->removeElement($sectors);
    }

    /**
     * Get sectors
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getSectors()
    {
        return $this->sectors;
    }
}
