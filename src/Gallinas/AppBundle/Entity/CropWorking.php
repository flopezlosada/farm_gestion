<?php
/**
 * Created by diphda.net
 * Trata de representar el hecho de cultivar. Es decir, que hay una determinada superficie que se está cultivando
 * durante un período de tiempo con un cultivo. Se asocia con CropWorking para determinar cuánto se ha recogido y cuándo.
 * Las fechas se determinan con las tareas asociadas (siembra y levantamiento del cultivo marcan inicio y fin)
 * También se asocia con una zona de cultivo
 * User: paco
 * Date: 10/12/15
 * Time: 22:00
 */

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\CropWorking
 *
 * @ORM\Table(name="crop_working")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\CropWorkingRepository")
 */
class CropWorking
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
     * @var smallint $crop
     * @ORM\ManyToOne(targetEntity="Crop", inversedBy="crop_workings")
     */
    private $crop;

    /**
     * @var Lo uso para tener el listado de productos y su producción en crop/index
     */
    protected $total_production;

    /**.
     * @Assert\NotBlank
     * @var smallint $working_year
     * Año del cultivo. Vamos a considerar el año de cultivo el momento de la siembra o plantación.
     * En el caso de ajos que puede que se siembren en diciembre y enero se considera la primera siembra para determinar
     * el año de cultivo. Después, con el listado de producciones asociadas sabremos la producción total de este cultivo en este ciclo o cropworking
     * Es un entero de 4 cifras.
     */
    private $working_year;

    /**
     * @ORM\OneToMany(targetEntity="Production", mappedBy="crop_working")
     */
    protected $productions;


    /**
     * @Assert\NotBlank
     * @var smallint $crop
     * @ORM\ManyToMany(targetEntity="Sector", mappedBy="crop_workings")
     * @ORM\JoinTable(name="crop_working_sector")
     */
    public $sectors;

    /**
     * @var zone
     */
    private $zone;


    /**
     * Superficie cultivada
     * @Assert\NotBlank
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $surface
     * @ORM\Column(name="surface", type="integer")
     */
    private $surface;

    /**
     * Producción estimada
     *
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $estimated_production
     * @ORM\Column(name="estimated_production", type="integer")
     */
    private $estimated_production;


    /**
     * @var string planting_pattern
     * Marco de plantación
     * @ORM\Column(name="planting_pattern", type="string", length=255)
     */
    private $planting_pattern;

    /**
     * Indica si está finalizado el cultivo
     * @ORM\Column(name="finish", type="boolean", length=1, nullable=true)
     * @var boolean finish
     */
    private $finish = false;

    /**
     * @var string $content
     *
     * @ORM\Column(name="content", type="text", nullable=true)
     * observaciones
     */
    private $content;


    /**
     * lista de trabajos culturales realizados
     * @ORM\ManyToMany(targetEntity="CulturalWork", mappedBy="crop_workings")
     * @ORM\JoinTable(name="crop_working_cultural_work")
     * @ORM\OrderBy({"date" = "ASC"})
     */
    private $cultural_works;


    /**
     * @ORM\OneToMany(targetEntity="SeedWork", mappedBy="crop_working")
     * @ORM\OrderBy({"real_date" = "ASC"})
     */
    protected $seed_works;

    /**
     * Fecha realización,
     * @Assert\Date()
     * @var string $date
     * @ORM\Column(name="finish_date", type="date", nullable=true)
     * fecha de finalización. Se añade automáticamente al finalizar el cultivo
     */
    private $finish_date;

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


    public function __toString()
    {
        return $this->getName();
    }
    /**
     * Constructor
     */
    

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
     * @return CropWorking
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
     * Set estimated_production
     *
     * @param integer $estimatedProduction
     * @return CropWorking
     */
    public function setEstimatedProduction($estimatedProduction)
    {
        $this->estimated_production = $estimatedProduction;

        return $this;
    }

    /**
     * Get estimated_production
     *
     * @return integer 
     */
    public function getEstimatedProduction()
    {
        return $this->estimated_production;
    }

    /**
     * Set planting_pattern
     *
     * @param string $plantingPattern
     * @return CropWorking
     */
    public function setPlantingPattern($plantingPattern)
    {
        $this->planting_pattern = $plantingPattern;

        return $this;
    }

    /**
     * Get planting_pattern
     *
     * @return string 
     */
    public function getPlantingPattern()
    {
        return $this->planting_pattern;
    }

    /**
     * Set finish
     *
     * @param boolean $finish
     * @return CropWorking
     */
    public function setFinish($finish)
    {
        $this->finish = $finish;

        return $this;
    }

    /**
     * Get finish
     *
     * @return boolean 
     */
    public function getFinish()
    {
        return $this->finish;
    }

    /**
     * Set content
     *
     * @param string $content
     * @return CropWorking
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
     * @return CropWorking
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
     * @return CropWorking
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
     * @return CropWorking
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
     * Add productions
     *
     * @param \Gallinas\AppBundle\Entity\Production $productions
     * @return CropWorking
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



    /**
     * Add cultural_works
     *
     * @param \Gallinas\AppBundle\Entity\CulturalWork $culturalWorks
     * @return CropWorking
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
     * @return CropWorking
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
     * Set name
     *
     * @param string $name
     * @return CropWorking
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


    public function getStatus()
    {
        if ($this->getFinish())
        {
            return "Finalizado";
        }

        return "En producción";
    }

    



    public function getZone()
    {
        return $this->zone;
    }

    public function setZone($zone)
    {
        $this->zone=$zone;

        return $this;
    }


    /**
     * Add sectors
     *
     * @param \Gallinas\AppBundle\Entity\Sector $sectors
     * @return CropWorking
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

    public function hasSector()
    {
        if (count($this->getSectors())>0)
        {
            return true;
        }

        return false;
    }
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->productions = new \Doctrine\Common\Collections\ArrayCollection();
        $this->sectors = new \Doctrine\Common\Collections\ArrayCollection();
        $this->cultural_works = new \Doctrine\Common\Collections\ArrayCollection();
        $this->seed_works = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set finish_date
     *
     * @param \DateTime $finishDate
     * @return CropWorking
     */
    public function setFinishDate($finishDate)
    {
        $this->finish_date = $finishDate;

        return $this;
    }

    /**
     * Get finish_date
     *
     * @return \DateTime 
     */
    public function getFinishDate()
    {
        return $this->finish_date;
    }
}
