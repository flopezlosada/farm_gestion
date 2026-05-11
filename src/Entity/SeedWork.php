<?php
/**
 * Created by diphda.net.
 * User: paco
 * Date: 13/11/17
 * Time: 11:48
 * Representa el trabajo de plantación/siembra de un cultivo. Se puede sembrar en invernadero o directo. Y plantar directamente en terreno.
 */

namespace App\Entity;



use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * App\Entity\SeedWork
 *
 * @ORM\Table(name="seed_work")
 * @ORM\Entity(repositoryClass="App\Repository\SeedWorkRepository")
 */
class SeedWork
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
     * @var smallint $crop_working
     * @ORM\ManyToOne(targetEntity="CropWorking", inversedBy="seed_works")
     *
     */
    #[Assert\NotBlank]
    private $crop_working;

    /**
     * @var smallint $seed_work_type
     * @ORM\ManyToOne(targetEntity="SeedWorkType", inversedBy="seed_works")
     */
    #[Assert\NotBlank]
    private $seed_work_type;

    /**
     * @var smallint $sector
     *
     * @ORM\ManyToOne(targetEntity="Sector", inversedBy="seed_works")
     */
    private $sector;

    /**
     * Superficie cultivada
     *
     * @var smallint $surface
     * @ORM\Column(name="surface", type="integer", nullable=true)
     */
    #[Assert\Type(type: 'numeric', message: 'El valor {{value}} no es un {{type}} válido.')]
    #[Assert\GreaterThan(value: 0)]
    private $surface;


    /**
     * número de plantas
     *
     * @var smallint $plant
     * @ORM\Column(name="plant", type="integer", nullable=true)
     */
    #[Assert\Type(type: 'numeric', message: 'El valor {{value}} no es un {{type}} válido.')]
    #[Assert\GreaterThan(value: 0)]
    private $plant;


    /**
     * bandejas de semillero
     * @var smallint $plants
     * @ORM\Column(name="tray", type="integer", nullable=true)
     */
    #[Assert\Type(type: 'numeric', message: 'El valor {{value}} no es un {{type}} válido.')]
    private $tray;

    /**
     * Fecha realización,
     *
     * @var string $estimated_date
     * @ORM\Column(name="estimated_date", type="date", nullable=true)
     */
    private $estimated_date;

    /**
     * Fecha realización,
     * @var string $real_date
     * @ORM\Column(name="real_date", type="date", nullable=true)
     */
    #[Assert\NotBlank]
    private $real_date;

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
     * @var string $content
     *
     * @ORM\Column(name="content", type="text", nullable=true)
     */
    private $content;


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
     * Set surface
     *
     * @param integer $surface
     * @return SeedWork
     */
    public function setSurface($surface)
    {
        $this->surface = $surface;

        return $this;
    }

    /**
     * Get surface
     *
     * @return integer 
     */
    public function getSurface()
    {
        return $this->surface;
    }

    /**
     * Set plant
     *
     * @param integer $plant
     * @return SeedWork
     */
    public function setPlant($plant)
    {
        $this->plant = $plant;

        return $this;
    }

    /**
     * Get plant
     *
     * @return integer 
     */
    public function getPlant()
    {
        return $this->plant;
    }

    /**
     * Set tray
     *
     * @param integer $tray
     * @return SeedWork
     */
    public function setTray($tray)
    {
        $this->tray = $tray;

        return $this;
    }

    /**
     * Get tray
     *
     * @return integer 
     */
    public function getTray()
    {
        return $this->tray;
    }

    /**
     * Set estimated_date
     *
     * @param \DateTime $estimatedDate
     * @return SeedWork
     */
    public function setEstimatedDate($estimatedDate)
    {
        $this->estimated_date = $estimatedDate;

        return $this;
    }

    /**
     * Get estimated_date
     *
     * @return \DateTime 
     */
    public function getEstimatedDate()
    {
        return $this->estimated_date;
    }

    /**
     * Set real_date
     *
     * @param \DateTime $realDate
     * @return SeedWork
     */
    public function setRealDate($realDate)
    {
        $this->real_date = $realDate;

        return $this;
    }

    /**
     * Get real_date
     *
     * @return \DateTime 
     */
    public function getRealDate()
    {
        return $this->real_date;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return SeedWork
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
     * @return SeedWork
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
     * Set crop_working
     *
     * @param \App\Entity\CropWorking $cropWorking
     * @return SeedWork
     */
    public function setCropWorking(\App\Entity\CropWorking $cropWorking = null)
    {
        $this->crop_working = $cropWorking;

        return $this;
    }

    /**
     * Get crop_working
     *
     * @return \App\Entity\CropWorking 
     */
    public function getCropWorking()
    {
        return $this->crop_working;
    }

    public function getDate()
    {
        return $this->getRealDate();
    }

    /**
     * Set seed_work_type
     *
     * @param \App\Entity\SeedWorkType $seedWorkType
     * @return SeedWork
     */
    public function setSeedWorkType(\App\Entity\SeedWorkType $seedWorkType = null)
    {
        $this->seed_work_type = $seedWorkType;

        return $this;
    }

    /**
     * Get seed_work_type
     *
     * @return \App\Entity\SeedWorkType 
     */
    public function getSeedWorkType()
    {
        return $this->seed_work_type;
    }

    /**
     * Set content
     *
     * @param string $content
     * @return SeedWork
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

    public function __toString()
    {
        return $this->getSeedWorkType().": ".$this->getCropWorking()->getName();
    }

    /**
     * Set sector
     *
     * @param \App\Entity\Sector $sector
     * @return SeedWork
     */
    public function setSector(\App\Entity\Sector $sector = null)
    {
        $this->sector = $sector;

        return $this;
    }

    /**
     * Get sector
     *
     * @return \App\Entity\Sector 
     */
    public function getSector()
    {
        return $this->sector;
    }
}
