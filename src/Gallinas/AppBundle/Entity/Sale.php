<?php
/**
 * Ventas de huevos
 * User: paco
 * Date: 4/11/14
 * Time: 12:02
 */

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Sale
 *
 * @ORM\Table(name="sale")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\SaleRepository")
 */
class Sale
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
     * @var string $sale_date
     * @ORM\Column(name="sale_date", type="date")
     */
    private $sale_date;

    /**
     * Cantidad de huevos repartidos
     * @Assert\NotBlank
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $amount
     * @ORM\Column(name="amount" ,type="decimal", precision=8, scale=2)
     */
    private $amount;

    /**
     * @Assert\NotBlank
     * @var smallint $unity
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\Unity", inversedBy="sales")
     */
    private $unity;


    /**
     * Precio de venta por unidad
     * @var smallint $single_price
     * @ORM\Column(name="single_price",type="decimal", precision=8, scale=2)
     */
    private $single_price;


    /**
     * Precio de venta total
     * @var smallint $total_price
     * @ORM\Column(name="total_price",type="decimal", precision=8, scale=2)
     */
    private $total_price;

    /**
     * Semana (dentro de cada año) a la que se refiere este reparto. Se calcula automáticamente, Es para las estadísticas semanales
     * @var smallint $week
     * @ORM\Column(name="week", type="integer")
     */
    private $week;


    /**
     * Quién se ha llevado los huevos
     * @Assert\NotBlank
     * @var smallint $purchaser
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\Purchaser", inversedBy="purchases")
     */
    private $purchaser;


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
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\Product", inversedBy="sales")
     */
    private $product;

    /**
     * Quién lo ha vendido?
     * @Assert\NotBlank
     * @var smallint $user
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\User", inversedBy="sales")
     */
    private $user;


    /**
     * Notas
     * @var string $note
     * @ORM\Column(name="note", type="text", nullable=true)
     */
    private $note;


    /**
     * @var int paid
     * @ORM\Column(name="paid", type="boolean", length=1, nullable=true)
     * está pagado la venta?
     */
    private $paid = true;


    /**
     * @ORM\OneToOne(targetEntity="Fowl", mappedBy="sale")
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
     * Set sale_date
     *
     * @param \DateTime $saleDate
     * @return Sale
     */
    public function setSaleDate($saleDate)
    {
        $this->sale_date = $saleDate;

        return $this;
    }

    /**
     * Get sale_date
     *
     * @return \DateTime
     */
    public function getSaleDate()
    {
        return $this->sale_date;
    }

    /**
     * Set amount
     *
     * @param integer $amount
     * @return Sale
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
     * Set unity
     *
     * @param string $unity
     * @return Sale
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

    /**
     * Set price
     *
     * @param string $price
     * @return Sale
     */
    public function setPrice($price)
    {
        $this->price = $price;

        return $this;
    }

    /**
     * Get price
     *
     * @return string
     */
    public function getPrice()
    {
        return $this->price;
    }

    /**
     * Set week
     *
     * @param integer $week
     * @return Sale
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
     * @return Sale
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
     * @return Sale
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
     * @return Sale
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
     * Set paid
     *
     * @param boolean $paid
     * @return Sale
     */
    public function setPaid($paid)
    {
        $this->paid = $paid;

        return $this;
    }

    /**
     * Get paid
     *
     * @return boolean
     */
    public function getPaid()
    {
        return $this->paid;
    }

    /**
     * Set purchaser
     *
     * @param \Gallinas\AppBundle\Entity\Purchaser $purchaser
     * @return Sale
     */
    public function setPurchaser(\Gallinas\AppBundle\Entity\Purchaser $purchaser = null)
    {
        $this->purchaser = $purchaser;

        return $this;
    }

    /**
     * Get purchaser
     *
     * @return \Gallinas\AppBundle\Entity\Purchaser
     */
    public function getPurchaser()
    {
        return $this->purchaser;
    }

    /**
     * Set product
     *
     * @param \Gallinas\AppBundle\Entity\Product $product
     * @return Sale
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
     * Set user
     *
     * @param \Gallinas\AppBundle\Entity\User $user
     * @return Sale
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
     * Set single_price
     *
     * @param string $singlePrice
     * @return Sale
     */
    public function setSinglePrice($singlePrice)
    {
        $this->single_price = $singlePrice;

        return $this;
    }

    /**
     * Get single_price
     *
     * @return string
     */
    public function getSinglePrice()
    {
        return $this->single_price;
    }

    /**
     * Set total_price
     *
     * @param string $totalPrice
     * @return Sale
     */
    public function setTotalPrice($totalPrice)
    {
        $this->total_price = $totalPrice;

        return $this;
    }

    /**
     * Get total_price
     *
     * @return string
     */
    public function getTotalPrice()
    {
        return $this->total_price;
    }

    public function __toString()
    {
        return "Venta a " . $this->getPurchaser() . " de " . $this->getAmount() . " " . $this->getUnity() . " de " . $this->getProduct();
    }

    public function getDate()
    {
        return $this->getSaleDate();
    }

    /**
     * Set fowl
     *
     * @param \Gallinas\AppBundle\Entity\Fowl $fowl
     * @return Sale
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
