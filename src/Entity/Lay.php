<?php
/**
 * Datos de puesta de gallinas
 * User: paco
 * Date: 4/11/14
 * Time: 11:45
 */

namespace App\Entity;


use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * App\Entity\Lay
 *
 * @ORM\Table(name="lay",uniqueConstraints={@ORM\UniqueConstraint(name="lay_date_idx", columns={"lay_date","batch_id"})})
 * @ORM\Entity(repositoryClass="App\Repository\LayRepository")
 */
class Lay
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
     * @var smallint $batch
     * @ORM\ManyToOne(targetEntity="Batch", inversedBy="lays")
     */
    #[Assert\NotBlank]
    private $batch;

    /**
     * @var string $lay_date
     * @ORM\Column(name="lay_date", type="date")
     */
    #[Assert\NotBlank]
    #[Assert\Date]
    private $lay_date;

    /**
     * Cantidad de huevos puestos
     * @var smallint $amount
     * @ORM\Column(name="amount", type="integer")
     */
    #[Assert\NotBlank]
    #[Assert\Type(type: 'numeric', message: 'El valor {{value}} no es un {{type}} válido.')]
    #[Assert\GreaterThan(value: 0)]
    private $amount;

    /**
     * Semana (dentro de cada año) a la que se refiere esta puesta. Se calcula automáticamente, Es para las estadísticas semanales
     * @var smallint $week
     * @ORM\Column(name="week", type="integer")
     */
    private $week;

    /**
     * @var \DateTime $created
     *
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(name="created", type="datetime")
     */
    private $created;

    /**
     * @var \DateTime $updated
     *
     * @Gedmo\Timestampable(on="update")
     * @ORM\Column(name="updated", type="datetime")
     */
    private $updated;


    /**
     * Quién lo ha subido?
     * @var smallint $user
     * @ORM\ManyToOne(targetEntity="App\Entity\User", inversedBy="lays")
     */
    #[Assert\NotBlank]
    private $user;

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
     * Set lay_date
     *
     * @param \DateTime $layDate
     * @return Lay
     */
    public function setLayDate($layDate)
    {
        $this->lay_date = $layDate;

        return $this;
    }

    /**
     * Get lay_date
     *
     * @return \DateTime 
     */
    public function getLayDate()
    {
        return $this->lay_date;
    }

    /**
     * Set amount
     *
     * @param integer $amount
     * @return Lay
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

    /**
     * Set week
     *
     * @param integer $week
     * @return Lay
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
     * Set created
     *
     * @param \DateTime $created
     * @return Lay
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
     * @return Lay
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
     * Set user
     *
     * @param \App\Entity\User $user
     * @return Lay
     */
    public function setUser(\App\Entity\User $user = null)
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get user
     *
     * @return \App\Entity\User
     */
    public function getUser()
    {
        return $this->user;
    }

    public function __toString()
    {
        return 'Puesta: '.$this->amount." huevos (Lote: ".$this->getBatch()->getId().")";
    }

    public function  getDate()
    {
        return $this->getLayDate();
    }

    public function getYear()
    {
        return $this->getDate()->format('Y');
    }


    /**
     * Set batch
     *
     * @param \App\Entity\Batch $batch
     *
     * @return Lay
     */
    public function setBatch(\App\Entity\Batch $batch = null)
    {
        $this->batch = $batch;

        return $this;
    }

    /**
     * Get batch
     *
     * @return \App\Entity\Batch
     */
    public function getBatch()
    {
        return $this->batch;
    }
}
