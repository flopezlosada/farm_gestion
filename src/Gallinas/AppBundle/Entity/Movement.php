<?php
/**
 * User: paco
 * Date: 4/11/14
 * Time: 16:07
 * Controla el movimiento de cada lote de gallinas
 */

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ORM\Entity
 * @ORM\Table(name="movement")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\MovementRepository")
 */
class Movement
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @Assert\NotBlank
     * @Assert\Date()
     * @var string $date
     * @ORM\Column(name="date", type="date")
     */
    private $date;

    /**
     *
     * @var smallint $batch
     * @ORM\ManyToOne(targetEntity="Batch", inversedBy="movements")
     */
    private $batch;

    /**
     * @var smallint $sector
     *
     * @ORM\ManyToOne(targetEntity="Sector", inversedBy="movements")
     */
    private $sector;

    /**
     * Días durante los cuales el lote permanece en el sector. Se rellena a posteriori, cuando se genera un cambio, se
     * indica este valor en el anterior
     * @var smallint $amount
     * @ORM\Column(name="amount", type="integer", nullable=true)
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
     * @return Movement
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
     * Set created
     *
     * @param \DateTime $created
     * @return Movement
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
     * @return Movement
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
     * Set sector
     *
     * @param \Gallinas\AppBundle\Entity\Sector $sector
     * @return Movement
     */
    public function setSector(\Gallinas\AppBundle\Entity\Sector $sector = null)
    {
        $this->sector = $sector;

        return $this;
    }

    /**
     * Get sector
     *
     * @return \Gallinas\AppBundle\Entity\Sector 
     */
    public function getSector()
    {
        return $this->sector;
    }

    /**
     * Set batch
     *
     * @param \Gallinas\AppBundle\Entity\Batch $batch
     * @return Movement
     */
    public function setBatch(\Gallinas\AppBundle\Entity\Batch $batch = null)
    {
        $this->batch = $batch;

        return $this;
    }

    /**
     * Get batch
     *
     * @return \Gallinas\AppBundle\Entity\Batch 
     */
    public function getBatch()
    {
        return $this->batch;
    }

    /**
     * Set amount
     *
     * @param integer $amount
     * @return Movement
     */
    public function setAmount($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * Get amount
     *
     * @return integer 
     */
    public function getAmount()
    {
        return $this->amount;
    }

    public function __toString()
    {
        return "Cambio del lote ".$this->getBatch()->getId()." al sector ".$this->getSector();
    }
}
