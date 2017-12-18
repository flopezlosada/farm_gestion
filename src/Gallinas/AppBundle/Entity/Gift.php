<?php
/**
 * Regalos de huevos
 * User: paco
 * Date: 4/11/14
 * Time: 12:02
 */

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Gift
 *
 * @ORM\Table(name="gift")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\GiftRepository")
 */
class Gift
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
     * @Assert\Date()
     * @var string $gift_date
     * @ORM\Column(name="gift_date", type="date")
     */
    private $gift_date;

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
     * Precio de compra por unidad
     * @var smallint $single_price
     * @ORM\Column(name="single_price",type="decimal", precision=8, scale=2)
     */
    private $single_price;


    /**
     * Precio de compra total
     * @var smallint $total_price
     * @ORM\Column(name="total_price",type="decimal", precision=8, scale=2)
     */
    private $total_price;

    /**
     * @Assert\NotBlank
     * @var smallint $unity
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\Unity", inversedBy="gifts")
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
     * @var smallint $recipient
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\Recipient", inversedBy="gifts")
     */
    private $recipient;


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
     * qué se ha llevado
     * @Assert\NotBlank
     * @var smallint $product
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\Product", inversedBy="gifts")
     */
    private $product;


    /**
     * tipo de regalo
     * @Assert\NotBlank
     * @var smallint $gift_type
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\GiftType", inversedBy="gifts")
     */
    private $gift_type;


    /**
     * Notas
     * @var string $note
     * @ORM\Column(name="note", type="text", nullable=true)
     */
    private $note;



    /**
     * Quién ha anotado el regalo
     * @Assert\NotBlank
     * @var smallint $user
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\User", inversedBy="gifts")
     */
    private $user;


    /**
     * @ORM\OneToOne(targetEntity="Fowl", mappedBy="gift")
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
     * Set amount
     *
     * @param integer $amount
     * @return Gift
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
     * @return Gift
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
     * @return Gift
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
     * @return Gift
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
     * Set note
     *
     * @param string $note
     * @return Gift
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
     * Set unity
     *
     * @param \Gallinas\AppBundle\Entity\Unity $unity
     * @return Gift
     */
    public function setUnity(\Gallinas\AppBundle\Entity\Unity $unity = null)
    {
        $this->unity = $unity;

        return $this;
    }

    /**
     * Get unity
     *
     * @return \Gallinas\AppBundle\Entity\Unity
     */
    public function getUnity()
    {
        return $this->unity;
    }

    /**
     * Set recipient
     *
     * @param \Gallinas\AppBundle\Entity\Recipient $recipient
     * @return Gift
     */
    public function setRecipient(\Gallinas\AppBundle\Entity\Recipient $recipient = null)
    {
        $this->recipient = $recipient;

        return $this;
    }

    /**
     * Get recipient
     *
     * @return \Gallinas\AppBundle\Entity\Recipient
     */
    public function getRecipient()
    {
        return $this->recipient;
    }

    /**
     * Set product
     *
     * @param \Gallinas\AppBundle\Entity\Product $product
     * @return Gift
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
     * Set gift_type
     *
     * @param \Gallinas\AppBundle\Entity\GiftType $giftType
     * @return Gift
     */
    public function setGiftType(\Gallinas\AppBundle\Entity\GiftType $giftType = null)
    {
        $this->gift_type = $giftType;

        return $this;
    }

    /**
     * Get gift_type
     *
     * @return \Gallinas\AppBundle\Entity\GiftType
     */
    public function getGiftType()
    {
        return $this->gift_type;
    }

    /**
     * Set gift_date
     *
     * @param \DateTime $giftDate
     * @return Gift
     */
    public function setGiftDate($giftDate)
    {
        $this->gift_date = $giftDate;

        return $this;
    }

    /**
     * Get gift_date
     *
     * @return \DateTime
     */
    public function getGiftDate()
    {
        return $this->gift_date;
    }

    public function getDate()
    {
        return $this->getGiftDate();
    }

    /**
     * Set user
     *
     * @param \Gallinas\AppBundle\Entity\User $user
     * @return Gift
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

    public function __toString()
    {
        return "Regalo de ".$this->getAmount()." ".$this->getUnity()." de ".$this->getProduct()." a ".$this->getRecipient();
    }

    /**
     * Set fowl
     *
     * @param \Gallinas\AppBundle\Entity\Fowl $fowl
     * @return Gift
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

    /**
     * Set singlePrice
     *
     * @param string $singlePrice
     *
     * @return Gift
     */
    public function setSinglePrice($singlePrice)
    {
        $this->single_price = $singlePrice;

        return $this;
    }

    /**
     * Get singlePrice
     *
     * @return string
     */
    public function getSinglePrice()
    {
        return $this->single_price;
    }

    /**
     * Set totalPrice
     *
     * @param string $totalPrice
     *
     * @return Gift
     */
    public function setTotalPrice($totalPrice)
    {
        $this->total_price = $totalPrice;

        return $this;
    }

    /**
     * Get totalPrice
     *
     * @return string
     */
    public function getTotalPrice()
    {
        return $this->total_price;
    }
}
