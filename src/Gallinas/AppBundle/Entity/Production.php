<?php
/**
 * Created by diphda.net.
 * User: paco
 * Date: 1/12/15
 * Time: 21:47
 * Cantidad de producción asociada a cada cultivo
 *
 *
 */

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Production
 * @ORM\Table(name="production")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\ProductionRepository")
 */
class Production
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
     * @var smallint $crop
     * @ORM\ManyToOne(targetEntity="Crop", inversedBy="productions")
     */
    private $crop;

    /**
     * Notas
     * @var string $note
     * @ORM\Column(name="note", type="text", nullable=true)
     */
    private $note;
    
    /**
     * @var smallint $crop_working
     * @ORM\ManyToOne(targetEntity="CropWorking", inversedBy="productions")
     * Esto es un cambio. En principio se relacionaba la cosecha con el cultivo, pero lo voy a cambiar
     * para relacionarlo con CropWorking, para tener en cuenta los años de cultivo, es decir, para diferenciar
     * cosechas por ciclo.
     */
    private $crop_working;


    /**
     * @Assert\Date()
     * @var string production_date
     * @ORM\Column(name="production_date", type="date", nullable=true)
     */
    private $production_date;

    /**
     * Cantidad de producto cosechado
     * @Assert\NotBlank
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $amount
     * @ORM\Column(name="amount",type="decimal", precision=8, scale=2)
     */
    private $amount;

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
     * Semana (dentro de cada año) a la que se refiere esta producción. Se calcula automáticamente, Es para las estadísticas semanales
     * @var smallint $week
     * @ORM\Column(name="week", type="integer")
     */
    private $week;

    /**
     *
     * @var smallint $batch
     * @ORM\ManyToOne(targetEntity="Basket", inversedBy="productions")
     */
    private $basket;


    public function __toString()
    {
        return $this->getCrop()->getName().": ".$this->getAmount()." kilos el día: ".$this->getProductionDate()->format("d/m/Y");
    }


    public function getDate()
    {
        return $this->production_date;
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
     * @return Production
     */
    public function setDate($date)
    {
        $this->date = $date;

        return $this;
    }

    /**
     * Set amount
     *
     * @param string $amount
     * @return Production
     */
    public function setAmount($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * Get amount
     *
     * @return string
     */
    public function getAmount()
    {
        return $this->amount;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return Production
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
     * @return Production
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
     * @return Production
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
     * Set production_date
     *
     * @param \DateTime $productionDate
     * @return Production
     */
    public function setProductionDate($productionDate)
    {
        $this->production_date = $productionDate;

        return $this;
    }

    /**
     * Get production_date
     *
     * @return \DateTime 
     */
    public function getProductionDate()
    {
        return $this->production_date;
    }

    /**
     * Set week
     *
     * @param integer $week
     * @return Production
     */
    public function setWeek($week)
    {
        $this->week = $week;

        return $this;
    }

    /**
     * Get week
     *
     * @return integer 
     */
    public function getWeek()
    {
        return $this->week;
    }

    /**
     * Set crop_working
     *
     * @param \Gallinas\AppBundle\Entity\CropWorking $cropWorking
     * @return Production
     */
    public function setCropWorking(\Gallinas\AppBundle\Entity\CropWorking $cropWorking = null)
    {
        $this->crop_working = $cropWorking;

        return $this;
    }

    /**
     * Get crop_working
     *
     * @return \Gallinas\AppBundle\Entity\CropWorking 
     */
    public function getCropWorking()
    {
        return $this->crop_working;
    }

    /**
     * Set basket
     *
     * @param \Gallinas\AppBundle\Entity\Basket $basket
     * @return Production
     */
    public function setBasket(\Gallinas\AppBundle\Entity\Basket $basket = null)
    {
        $this->basket = $basket;

        return $this;
    }

    /**
     * Get basket
     *
     * @return \Gallinas\AppBundle\Entity\Basket 
     */
    public function getBasket()
    {
        return $this->basket;
    }

    /**
     * Set note
     *
     * @param string $note
     * @return Production
     */
    public function setNote($note)
    {
        $this->note = $note;

        return $this;
    }

    /**
     * Get note
     *
     * @return string 
     */
    public function getNote()
    {
        return $this->note;
    }
}
