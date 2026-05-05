<?php
/**
 * Tipos de productos para la compra, venta y regalo: Huevos, pavos, pollos, pienso, etc
 * User: paco
 * Date: 4/11/14
 * Time: 12:07
 */

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * App\Entity\Product
 *
 * @ORM\Table(name="product")
 * @ORM\Entity(repositoryClass="App\Repository\ProductRepository")
 */
class Product
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
     * @var string $name
     * @Assert\NotBlank
     * @ORM\Column(name="name", type="string", length=255)
     */
    private $name;

    /**
     * Este campo se usa para aquellos productos que son alimentos para los animales, y que pueden servir para cualquier
     * tipo de animales, como por ejemplo la avena, el trigo, etc
     * @ORM\Column(name="common_food", type="boolean", length=1, nullable=false, options={"default" = 0})
     * @var boolean common_food
     */
    private $common_food=false;

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
     * @ORM\OneToMany(targetEntity="App\Entity\Collect", mappedBy="product")
     */
    protected $collects;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\Gift", mappedBy="product")
     */
    protected $gifts;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\Batch", mappedBy="product")
     */
    protected $batchs;


    /**
     * @ORM\OneToMany(targetEntity="App\Entity\Sale", mappedBy="product")
     */
    protected $sales;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\Purchase", mappedBy="product")
     */
    protected $purchases;

    /**
     * @ORM\OneToMany(targetEntity="Sack", mappedBy="product")
     */
    protected $sacks;


    public function  __toString()
    {
        return $this->name;
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->collects = new \Doctrine\Common\Collections\ArrayCollection();
        $this->gifts = new \Doctrine\Common\Collections\ArrayCollection();
        $this->sales = new \Doctrine\Common\Collections\ArrayCollection();
        $this->purchases = new \Doctrine\Common\Collections\ArrayCollection();
        $this->batchs = new ArrayCollection();
        $this->sacks = new ArrayCollection();
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
     * Set name
     *
     * @param string $name
     * @return Product
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

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return Product
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
     * @return Product
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
     * Add collects
     *
     * @param \App\Entity\Collect $collects
     * @return Product
     */
    public function addCollect(\App\Entity\Collect $collects)
    {
        $this->collects[] = $collects;

        return $this;
    }

    /**
     * Remove collects
     *
     * @param \App\Entity\Collect $collects
     */
    public function removeCollect(\App\Entity\Collect $collects)
    {
        $this->collects->removeElement($collects);
    }

    /**
     * Get collects
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCollects()
    {
        return $this->collects;
    }

    /**
     * Add gifts
     *
     * @param \App\Entity\Collect $gifts
     * @return Product
     */
    public function addGift(\App\Entity\Collect $gifts)
    {
        $this->gifts[] = $gifts;

        return $this;
    }

    /**
     * Remove gifts
     *
     * @param \App\Entity\Collect $gifts
     */
    public function removeGift(\App\Entity\Collect $gifts)
    {
        $this->gifts->removeElement($gifts);
    }

    /**
     * Get gifts
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getGifts()
    {
        return $this->gifts;
    }

    /**
     * Add sales
     *
     * @param \App\Entity\Sale $sales
     * @return Product
     */
    public function addSale(\App\Entity\Sale $sales)
    {
        $this->sales[] = $sales;

        return $this;
    }

    /**
     * Remove sales
     *
     * @param \App\Entity\Sale $sales
     */
    public function removeSale(\App\Entity\Sale $sales)
    {
        $this->sales->removeElement($sales);
    }

    /**
     * Get sales
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getSales()
    {
        return $this->sales;
    }

    /**
     * Add purchases
     *
     * @param \App\Entity\Purchase $purchases
     * @return Product
     */
    public function addPurchase(\App\Entity\Purchase $purchases)
    {
        $this->purchases[] = $purchases;

        return $this;
    }

    /**
     * Remove purchases
     *
     * @param \App\Entity\Purchase $purchases
     */
    public function removePurchase(\App\Entity\Purchase $purchases)
    {
        $this->purchases->removeElement($purchases);
    }

    /**
     * Get purchases
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getPurchases()
    {
        return $this->purchases;
    }

    /**
     * Add batchs
     *
     * @param \App\Entity\Batch $batchs
     * @return Product
     */
    public function addBatch(\App\Entity\Batch $batchs)
    {
        $this->batchs[] = $batchs;

        return $this;
    }

    /**
     * Remove batchs
     *
     * @param \App\Entity\Batch $batchs
     */
    public function removeBatch(\App\Entity\Batch $batchs)
    {
        $this->batchs->removeElement($batchs);
    }

    /**
     * Get batchs
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getBatchs()
    {
        return $this->batchs;
    }

    /**
     * Add sacks
     *
     * @param \App\Entity\Sack $sacks
     * @return Product
     */
    public function addSack(\App\Entity\Sack $sacks)
    {
        $this->sacks[] = $sacks;

        return $this;
    }

    /**
     * Remove sacks
     *
     * @param \App\Entity\Sack $sacks
     */
    public function removeSack(\App\Entity\Sack $sacks)
    {
        $this->sacks->removeElement($sacks);
    }

    /**
     * Get sacks
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getSacks()
    {
        return $this->sacks;
    }

    /**
     * Set commonFood
     *
     * @param boolean $commonFood
     *
     * @return Product
     */
    public function setCommonFood($commonFood)
    {
        $this->common_food = $commonFood;

        return $this;
    }

    /**
     * Get commonFood
     *
     * @return boolean
     */
    public function getCommonFood()
    {
        return $this->common_food;
    }
}
