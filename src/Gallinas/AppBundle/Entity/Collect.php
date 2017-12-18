<?php
/**
 * Datos de reparto de huevos entre usuarixs
 * User: paco
 * Date: 4/11/14
 * Time: 11:53
 */

namespace Gallinas\AppBundle\Entity;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Collect
 *
 * @ORM\Table(name="collect")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\CollectRepository")
 */

class Collect {

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
     * @Assert\Date()
     * @var string $collect_date
     * @ORM\Column(name="collect_date", type="date")
     */
    private $collect_date;

    /**
     * Cantidad de huevos repartidos
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
     * @Assert\NotBlank
     * @var smallint $unity
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\Unity", inversedBy="collects")
     */
    private $unity;
    
    /**
     * Semana (dentro de cada año) a la que se refiere este reparto. Se calcula automáticamente, Es para las estadísticas semanales
     * @var smallint $week
     * @ORM\Column(name="week", type="integer")
     */
    private $week;


    /**
     * Quién se ha llevado los huevos
     * @Assert\NotBlank
     * @var smallint $user
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\User", inversedBy="collects")
     */
    private $user;


  /**
     * qué se ha llevado
     * @Assert\NotBlank
     * @var smallint $product
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\Product", inversedBy="collects")
     */
    private $product;

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
     * @ORM\OneToOne(targetEntity="Fowl", mappedBy="collect")
     **/
    private $fowl;

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
     * Set collect_date
     *
     * @param \DateTime $collectDate
     * @return Collect
     */
    public function setCollectDate($collectDate)
    {
        $this->collect_date = $collectDate;

        return $this;
    }

    /**
     * Get collect_date
     *
     * @return \DateTime 
     */
    public function getCollectDate()
    {
        return $this->collect_date;
    }

    /**
     * Set amount
     *
     * @param integer $amount
     * @return Collect
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
     * @return Collect
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
     * @return Collect
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
     * @return Collect
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
     * @param \Gallinas\AppBundle\Entity\User $user
     * @return Collect
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
     * Set product
     *
     * @param \Gallinas\AppBundle\Entity\Product $product
     * @return Collect
     */
    public function setProduct(\Gallinas\AppBundle\Entity\Product $product = null)
    {
        $this->product = $product;

        return $this;
    }

    /**
     * Get product
     *
     * @return \Gallinas\AppBundle\Entity\Product 
     */
    public function getProduct()
    {
        return $this->product;
    }

    /**
     * Set unity
     *
     * @param string $unity
     * @return Collect
     */
    public function setUnity($unity)
    {
        $this->unity = $unity;

        return $this;
    }

    /**
     * Get unity
     *
     * @return string 
     */
    public function getUnity()
    {
        return $this->unity;
    }

    public function __toString()
    {
        return "Recogida de ".$this->getProduct()." para ".$this->getUser()->getUsername().": ".$this->getAmount()." ".$this->getUnity();
    }

    public function  getDate()
    {
        return $this->getCollectDate();
    }

    public function getRealAmount()
    {
        if ($this->getProduct()->getId()==1)
        {
            if ($this->getUnity()->getId()==1)
            {
                return $this->getAmount();
            }
            else if ($this->getUnity()->getId()==3)
            {
                return $this->getAmount()*12;
            }
        }

        return $this->getAmount();

    }

    /**
     * Set fowl
     *
     * @param \Gallinas\AppBundle\Entity\Fowl $fowl
     * @return Collect
     */
    public function setFowl(\Gallinas\AppBundle\Entity\Fowl $fowl = null)
    {
        $this->fowl = $fowl;

        return $this;
    }

    /**
     * Get fowl
     *
     * @return \Gallinas\AppBundle\Entity\Fowl 
     */
    public function getFowl()
    {
        return $this->fowl;
    }
}
