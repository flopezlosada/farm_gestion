<?php
/**
 *
 * User: paco
 * Date: 13/05/15
 * Time: 11:52
 */


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * App\Entity\Sack
 *
 * @ORM\Table(name="sack")
 * @ORM\Entity(repositoryClass="App\Repository\SackRepository")
 */
class Sack
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
     * @var smallint $purchase
     * @ORM\ManyToOne(targetEntity="Purchase", inversedBy="sacks")
     */
    private $purchase;

    /**
     * @var smallint $batch
     * @ORM\ManyToOne(targetEntity="Batch", inversedBy="sacks")
     */
    private $batch;


    /**
     * @var smallint $product
     * @ORM\ManyToOne(targetEntity="Product", inversedBy="sacks")
     */
    private $product;

    /**
     * Fecha en que se usa el saco
     * @var string $delivery_date
     * @ORM\Column(name="delivery_date", type="date")
     */
    #[Assert\NotBlank]
    #[Assert\Date]
    private $delivery_date;

    /**
     * Peso del saco en Kg
     * @var smallint $weight
     * @ORM\Column(name="weight",type="decimal", precision=8, scale=2)
     */
    #[Assert\NotBlank]
    #[Assert\Type(type: 'numeric', message: 'El valor {{value}} no es un {{type}} válido.')]
    #[Assert\GreaterThan(value: 0)]
    private $weight;


    /**
     * Precio de compra total. Se calcula automáticamente. El precio por kilo lo saco de la compra.
     * @var smallint $total_price
     * @ORM\Column(name="total_price",type="decimal", precision=8, scale=2)
     */
    private $total_price;


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
     * Set delivery_date
     *
     * @param \DateTime $deliveryDate
     * @return Sack
     */
    public function setDeliveryDate($deliveryDate)
    {
        $this->delivery_date = $deliveryDate;

        return $this;
    }

    /**
     * Get delivery_date
     *
     * @return \DateTime
     */
    public function getDeliveryDate()
    {
        return $this->delivery_date;
    }

    /**
     * Set weight
     *
     * @param string $weight
     * @return Sack
     */
    public function setWeight($weight)
    {
        $this->weight = $weight;

        return $this;
    }

    /**
     * Get weight
     *
     * @return string
     */
    public function getWeight()
    {
        return $this->weight;
    }

    /**
     * Set total_price
     *
     * @param string $totalPrice
     * @return Sack
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

    /**
     * Set purchase
     *
     * @param \App\Entity\Purchase $purchase
     * @return Sack
     */
    public function setPurchase(\App\Entity\Purchase $purchase = null)
    {
        $this->purchase = $purchase;

        return $this;
    }

    /**
     * Get purchase
     *
     * @return \App\Entity\Purchase
     */
    public function getPurchase()
    {
        return $this->purchase;
    }

    /**
     * Set batch
     *
     * @param \App\Entity\Batch $batch
     * @return Sack
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

    /**
     * Set product
     *
     * @param \App\Entity\Product $product
     * @return Sack
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

    public function getDate()
    {
        return $this->getDeliveryDate();
    }
}
