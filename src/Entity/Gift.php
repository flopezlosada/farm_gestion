<?php
/**
 * Regalos de huevos
 * User: paco
 * Date: 4/11/14
 * Time: 12:02
 */

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * App\Entity\Gift
 *
 * @ORM\Table(name="gift")
 * @ORM\Entity(repositoryClass="App\Repository\GiftRepository")
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
     * @var string $gift_date
     * @ORM\Column(name="gift_date", type="date")
     */
    #[Assert\NotBlank]
    private $gift_date;

    /**
     * Cantidad de huevos repartidos
     * @var smallint $amount
     * @ORM\Column(name="amount",type="decimal", precision=8, scale=2)
     */
    #[Assert\NotBlank]
    #[Assert\Type(type: 'numeric', message: 'El valor {{value}} no es un {{type}} válido.')]
    #[Assert\GreaterThan(value: 0)]
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
     * @var smallint $unity
     * @ORM\ManyToOne(targetEntity="App\Entity\Unity", inversedBy="gifts")
     */
    #[Assert\NotBlank]
    private $unity;

    /**
     * Semana (dentro de cada año) a la que se refiere este reparto. Se calcula automáticamente, Es para las estadísticas semanales
     * @var smallint $week
     * @ORM\Column(name="week", type="integer")
     */
    private $week;


    /**
     * Quién se ha llevado los huevos
     * @var smallint $recipient
     * @ORM\ManyToOne(targetEntity="App\Entity\Recipient", inversedBy="gifts")
     */
    #[Assert\NotBlank]
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
     * @var smallint $product
     * @ORM\ManyToOne(targetEntity="App\Entity\Product", inversedBy="gifts")
     */
    #[Assert\NotBlank]
    private $product;


    /**
     * tipo de regalo
     * @var smallint $gift_type
     * @ORM\ManyToOne(targetEntity="App\Entity\GiftType", inversedBy="gifts")
     */
    #[Assert\NotBlank]
    private $gift_type;


    /**
     * Notas
     * @var string $note
     * @ORM\Column(name="note", type="text", nullable=true)
     */
    private $note;



    /**
     * Quién ha anotado el regalo
     * @var smallint $user
     * @ORM\ManyToOne(targetEntity="App\Entity\User", inversedBy="gifts")
     */
    #[Assert\NotBlank]
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
     * @param \App\Entity\Unity $unity
     * @return Gift
     */
    public function setUnity(\App\Entity\Unity $unity = null)
    {
        $this->unity = $unity;

        return $this;
    }

    /**
     * Get unity
     *
     * @return \App\Entity\Unity
     */
    public function getUnity()
    {
        return $this->unity;
    }

    /**
     * Set recipient
     *
     * @param \App\Entity\Recipient $recipient
     * @return Gift
     */
    public function setRecipient(\App\Entity\Recipient $recipient = null)
    {
        $this->recipient = $recipient;

        return $this;
    }

    /**
     * Get recipient
     *
     * @return \App\Entity\Recipient
     */
    public function getRecipient()
    {
        return $this->recipient;
    }

    /**
     * Set product
     *
     * @param \App\Entity\Product $product
     * @return Gift
     */
    public function setProduct(\App\Entity\Product $product = null)
    {
        $this->product = $product;

        return $this;
    }

    /**
     * Get product
     *
     * @return \App\Entity\Product
     */
    public function getProduct()
    {
        return $this->product;
    }

    /**
     * Set gift_type
     *
     * @param \App\Entity\GiftType $giftType
     * @return Gift
     */
    public function setGiftType(\App\Entity\GiftType $giftType = null)
    {
        $this->gift_type = $giftType;

        return $this;
    }

    /**
     * Get gift_type
     *
     * @return \App\Entity\GiftType
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
     * @param \App\Entity\User $user
     * @return Gift
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
        return "Regalo de ".$this->getAmount()." ".$this->getUnity()." de ".$this->getProduct()." a ".$this->getRecipient();
    }

    /**
     * Set fowl
     *
     * @param \App\Entity\Fowl $fowl
     * @return Gift
     */
    public function setFowl(\App\Entity\Fowl $fowl = null)
    {
        $this->fowl = $fowl;

        return $this;
    }

    /**
     * Get fowl
     *
     * @return \App\Entity\Fowl 
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
