<?php
/**
 * Unidades para los productos
 * User: paco
 * Date: 14/11/14
 * Time: 13:57
 */

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * App\Entity\Unity
 *
 * @ORM\Table(name="unity")
 * @ORM\Entity(repositoryClass="App\Repository\UnityRepository")
 */
class Unity
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
     * @ORM\Column(name="name", type="string", length=255)
     */
    #[Assert\NotBlank]
    private $name;

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
     * @ORM\OneToMany(targetEntity="App\Entity\Collect", mappedBy="unity")
     */
    protected $collects;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\Gift", mappedBy="unity")
     */
    protected $gifts;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\Purchase", mappedBy="unity")
     */
    protected $purchases;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\Sale", mappedBy="unity")
     */
    protected $sales;

    public function __toString()
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
        $this->purchases = new \Doctrine\Common\Collections\ArrayCollection();
        $this->sales = new \Doctrine\Common\Collections\ArrayCollection();
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
     * @return Unity
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
     * @return Unity
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
     * @return Unity
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
     * @return Unity
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
     * @param \App\Entity\Gift $gifts
     * @return Unity
     */
    public function addGift(\App\Entity\Gift $gifts)
    {
        $this->gifts[] = $gifts;

        return $this;
    }

    /**
     * Remove gifts
     *
     * @param \App\Entity\Gift $gifts
     */
    public function removeGift(\App\Entity\Gift $gifts)
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
     * Add purchases
     *
     * @param \App\Entity\Purchase $purchases
     * @return Unity
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
     * Add sales
     *
     * @param \App\Entity\Sale $sales
     * @return Unity
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
}
