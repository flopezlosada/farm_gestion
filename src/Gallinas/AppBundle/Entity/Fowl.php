<?php
/**
 * Ave de corral. Componen los lotes n - 1
 * User: paco
 * Date: 11/03/15
 * Time: 11:18
 */

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Fowl
 *
 * @ORM\Table(name="fowl")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\FowlRepository")
 */
class Fowl
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
     * @var smallint $batch
     * @ORM\ManyToOne(targetEntity="Batch", inversedBy="fowls")
     */
    private $batch;

    /**
     * Fecha de sacrificio
     * @Assert\Date()
     * @var string $put_down_date
     * @ORM\Column(name="put_down_date", type="date", nullable=true)
     */
    private $put_down_date;

    /**
     * Peso del animal al sacrificio, en kilos
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $put_down_weight
     * @ORM\Column(name="put_down_weight",type="decimal", precision=8, scale=2, nullable=true)
     */
    private $put_down_weight;

    /**
     * Peso en canal, en kilos
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $carcass_weight
     * @ORM\Column(name="carcass_weight",type="decimal", precision=8, scale=2, nullable=true)
     */
    private $carcass_weight;

    /**
     * Precio de venta en €/kg
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $sale_price
     * @ORM\Column(name="sale_price",type="decimal", precision=8, scale=2, nullable=true)
     */
    private $sale_price;

    /**
     * @var smallint $fowl_status
     * @ORM\ManyToOne(targetEntity="FowlStatus", inversedBy="fowls")
     */
    private $fowl_status;


    /**
     * @var smallint $fowl_destination
     * @ORM\ManyToOne(targetEntity="FowlDestination", inversedBy="fowls")
     */
    private $fowl_destination;


    /**
     * @var smallint $fowl_purchaser
     * @ORM\ManyToOne(targetEntity="Purchaser", inversedBy="fowls")
     */
    private $fowl_purchaser;


    /**
     * @var smallint $fowl_recipient
     * @ORM\ManyToOne(targetEntity="Recipient", inversedBy="fowls")
     */
    private $fowl_recipient;

    /**
     * Cuando es consumido por nosotros, se usa este
     * @var smallint $fowl_user
     * @ORM\ManyToOne(targetEntity="User", inversedBy="fowls")
     */
    private $fowl_user;

    /**
     * @ORM\OneToOne(targetEntity="Sale", inversedBy="fowl")
     * @ORM\JoinColumn(name="sale_id", referencedColumnName="id", nullable=true)
     **/
    private $sale;

    /**
     * @ORM\OneToOne(targetEntity="Gift", inversedBy="fowl")
     * @ORM\JoinColumn(name="gift_id", referencedColumnName="id", nullable=true)
     **/
    private $gift;

    /**
     * @ORM\OneToOne(targetEntity="Collect", inversedBy="fowl")
     * @ORM\JoinColumn(name="collect_id", referencedColumnName="id", nullable=true)
     **/
    private $collect;


    /**
     * Notas
     * @var string $note
     * @ORM\Column(name="note", type="text", nullable=true)
     */
    private $note;

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
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set put_down_date
     *
     * @param \DateTime $putDownDate
     * @return Fowl
     */
    public function setPutDownDate($putDownDate)
    {
        $this->put_down_date = $putDownDate;

        return $this;
    }

    /**
     * Get put_down_date
     *
     * @return \DateTime
     */
    public function getPutDownDate()
    {
        return $this->put_down_date;
    }

    /**
     * Set put_down_weight
     *
     * @param string $putDownWeight
     * @return Fowl
     */
    public function setPutDownWeight($putDownWeight)
    {
        $this->put_down_weight = $putDownWeight;

        return $this;
    }

    /**
     * Get put_down_weight
     *
     * @return string
     */
    public function getPutDownWeight()
    {
        return $this->put_down_weight;
    }

    /**
     * Set carcass_weight
     *
     * @param string $carcassWeight
     * @return Fowl
     */
    public function setCarcassWeight($carcassWeight)
    {
        $this->carcass_weight = $carcassWeight;

        return $this;
    }

    /**
     * Get carcass_weight
     *
     * @return string
     */
    public function getCarcassWeight()
    {
        return $this->carcass_weight;
    }

    /**
     * Set sale_price
     *
     * @param string $salePrice
     * @return Fowl
     */
    public function setSalePrice($salePrice)
    {
        $this->sale_price = $salePrice;

        return $this;
    }

    /**
     * Get sale_price
     *
     * @return string
     */
    public function getSalePrice()
    {
        return $this->sale_price;
    }

    /**
     * Set note
     *
     * @param string $note
     * @return Fowl
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

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return Fowl
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
     * @return Fowl
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
     * Set batch
     *
     * @param \Gallinas\AppBundle\Entity\Batch $batch
     * @return Fowl
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
     * Set fowl_status
     *
     * @param \Gallinas\AppBundle\Entity\FowlStatus $fowlStatus
     * @return Fowl
     */
    public function setFowlStatus(\Gallinas\AppBundle\Entity\FowlStatus $fowlStatus = null)
    {
        $this->fowl_status = $fowlStatus;

        return $this;
    }

    /**
     * Get fowl_status
     *
     * @return \Gallinas\AppBundle\Entity\FowlStatus
     */
    public function getFowlStatus()
    {
        return $this->fowl_status;
    }

    /**
     * Set fowl_destination
     *
     * @param \Gallinas\AppBundle\Entity\FowlDestination $fowlDestination
     * @return Fowl
     */
    public function setFowlDestination(\Gallinas\AppBundle\Entity\FowlDestination $fowlDestination = null)
    {
        $this->fowl_destination = $fowlDestination;

        return $this;
    }

    /**
     * Get fowl_destination
     *
     * @return \Gallinas\AppBundle\Entity\FowlDestination
     */
    public function getFowlDestination()
    {
        return $this->fowl_destination;
    }

    public function getDate()
    {
        return $this->getPutDownDate();
    }

    /**
     * Set fowl_purchaser
     *
     * @param \Gallinas\AppBundle\Entity\Purchaser $fowlPurchaser
     * @return Fowl
     */
    public function setFowlPurchaser(\Gallinas\AppBundle\Entity\Purchaser $fowlPurchaser = null)
    {
        $this->fowl_purchaser = $fowlPurchaser;

        return $this;
    }

    /**
     * Get fowl_purchaser
     *
     * @return \Gallinas\AppBundle\Entity\Purchaser
     */
    public function getFowlPurchaser()
    {
        return $this->fowl_purchaser;
    }

    /**
     * Set fowl_recipient
     *
     * @param \Gallinas\AppBundle\Entity\Recipient $fowlRecipient
     * @return Fowl
     */
    public function setFowlRecipient(\Gallinas\AppBundle\Entity\Recipient $fowlRecipient = null)
    {
        $this->fowl_recipient = $fowlRecipient;

        return $this;
    }

    /**
     * Get fowl_recipient
     *
     * @return \Gallinas\AppBundle\Entity\Recipient
     */
    public function getFowlRecipient()
    {
        return $this->fowl_recipient;
    }

    /**
     * Set fowl_user
     *
     * @param \Gallinas\AppBundle\Entity\User $fowlUser
     * @return Fowl
     */
    public function setFowlUser(\Gallinas\AppBundle\Entity\User $fowlUser = null)
    {
        $this->fowl_user = $fowlUser;

        return $this;
    }

    /**
     * Get fowl_user
     *
     * @return \Gallinas\AppBundle\Entity\User
     */
    public function getFowlUser()
    {
        return $this->fowl_user;
    }

    /**
     * comprueba si este animal ha sido relacionado con una venta, regalo o recogida
     */
    public function isRelated()
    {
        if ($this->getSale())
        {
            return true;
        }
        if ($this->getGift())
        {
            return true;
        }
        if ($this->getCollect())
        {
            return true;
        }

        return false;
    }



    /**
     * Set gift
     *
     * @param \Gallinas\AppBundle\Entity\Gift $gift
     * @return Fowl
     */
    public function setGift(\Gallinas\AppBundle\Entity\Gift $gift = null)
    {
        $this->gift = $gift;

        return $this;
    }

    /**
     * Get gift
     *
     * @return \Gallinas\AppBundle\Entity\Gift 
     */
    public function getGift()
    {
        return $this->gift;
    }

    /**
     * Set collect
     *
     * @param \Gallinas\AppBundle\Entity\Collect $collect
     * @return Fowl
     */
    public function setCollect(\Gallinas\AppBundle\Entity\Collect $collect = null)
    {
        $this->collect = $collect;

        return $this;
    }

    /**
     * Get collect
     *
     * @return \Gallinas\AppBundle\Entity\Collect 
     */
    public function getCollect()
    {
        return $this->collect;
    }

    /**
     * Set sale
     *
     * @param \Gallinas\AppBundle\Entity\Sale $sale
     * @return Fowl
     */
    public function setSale(\Gallinas\AppBundle\Entity\Sale $sale = null)
    {
        $this->sale = $sale;

        return $this;
    }

    /**
     * Get sale
     *
     * @return \Gallinas\AppBundle\Entity\Sale 
     */
    public function getSale()
    {
        return $this->sale;
    }
}
