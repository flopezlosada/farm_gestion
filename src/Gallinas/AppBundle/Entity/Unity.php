<?php
/**
 * Unidades para los productos
 * User: paco
 * Date: 14/11/14
 * Time: 13:57
 */

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Unity
 *
 * @ORM\Table(name="unity")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\UnityRepository")
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
     * @Assert\NotBlank
     * @ORM\Column(name="name", type="string", length=255)
     */
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
     * @ORM\OneToMany(targetEntity="Gallinas\AppBundle\Entity\Collect", mappedBy="unity")
     */
    protected $collects;

    /**
     * @ORM\OneToMany(targetEntity="Gallinas\AppBundle\Entity\Gift", mappedBy="unity")
     */
    protected $gifts;

    /**
     * @ORM\OneToMany(targetEntity="Gallinas\AppBundle\Entity\Purchase", mappedBy="unity")
     */
    protected $purchases;

    /**
     * @ORM\OneToMany(targetEntity="Gallinas\AppBundle\Entity\Sale", mappedBy="unity")
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
     * @param \Gallinas\AppBundle\Entity\Collect $collects
     * @return Unity
     */
    public function addCollect(\Gallinas\AppBundle\Entity\Collect $collects)
    {
        $this->collects[] = $collects;

        return $this;
    }

    /**
     * Remove collects
     *
     * @param \Gallinas\AppBundle\Entity\Collect $collects
     */
    public function removeCollect(\Gallinas\AppBundle\Entity\Collect $collects)
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
     * @param \Gallinas\AppBundle\Entity\Gift $gifts
     * @return Unity
     */
    public function addGift(\Gallinas\AppBundle\Entity\Gift $gifts)
    {
        $this->gifts[] = $gifts;

        return $this;
    }

    /**
     * Remove gifts
     *
     * @param \Gallinas\AppBundle\Entity\Gift $gifts
     */
    public function removeGift(\Gallinas\AppBundle\Entity\Gift $gifts)
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
     * @param \Gallinas\AppBundle\Entity\Purchase $purchases
     * @return Unity
     */
    public function addPurchase(\Gallinas\AppBundle\Entity\Purchase $purchases)
    {
        $this->purchases[] = $purchases;

        return $this;
    }

    /**
     * Remove purchases
     *
     * @param \Gallinas\AppBundle\Entity\Purchase $purchases
     */
    public function removePurchase(\Gallinas\AppBundle\Entity\Purchase $purchases)
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
     * @param \Gallinas\AppBundle\Entity\Sale $sales
     * @return Unity
     */
    public function addSale(\Gallinas\AppBundle\Entity\Sale $sales)
    {
        $this->sales[] = $sales;

        return $this;
    }

    /**
     * Remove sales
     *
     * @param \Gallinas\AppBundle\Entity\Sale $sales
     */
    public function removeSale(\Gallinas\AppBundle\Entity\Sale $sales)
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
