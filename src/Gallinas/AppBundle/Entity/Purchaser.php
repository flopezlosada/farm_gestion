<?php
/**
 * Representa las personas a las que regalamos huevos
 * User: paco
 * Date: 4/11/14
 * Time: 12:01
 */

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Purchaser
 *
 * @ORM\Table(name="purchaser")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\PurchaserRepository")
 */
class Purchaser
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
     * @var int regular
     * @ORM\Column(name="regular", type="boolean", length=1, nullable=true)
     * Indica si la compra es ocasional (false) o continua (true)
     */
    private $regular = true;

    /**
     * Frecuencia en días de la compra de huevos
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $often_buying_eggs
     * @ORM\Column(name="often_buying_eggs", type="integer", nullable=true)
     */
    private $often_buying_eggs;


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
     * @ORM\OneToMany(targetEntity="Gallinas\AppBundle\Entity\Sale", mappedBy="purchaser")
     */
    protected $purchases;


    /**
     * @ORM\OneToMany(targetEntity="Fowl", mappedBy="fowl_purchaser")
     */
    protected $fowls;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->purchases = new \Doctrine\Common\Collections\ArrayCollection();
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
     * @return Purchaser
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
     * @return Purchaser
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
     * @return Purchaser
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
     * Add purchases
     *
     * @param \Gallinas\AppBundle\Entity\Sale $purchases
     * @return Purchaser
     */
    public function addPurchase(\Gallinas\AppBundle\Entity\Sale $purchases)
    {
        $this->purchases[] = $purchases;

        return $this;
    }

    /**
     * Remove purchases
     *
     * @param \Gallinas\AppBundle\Entity\Sale $purchases
     */
    public function removePurchase(\Gallinas\AppBundle\Entity\Sale $purchases)
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

    public function __toString()
    {
        return $this->name;
    }

    /**
     * Set regular
     *
     * @param boolean $regular
     * @return Purchaser
     */
    public function setRegular($regular)
    {
        $this->regular = $regular;

        return $this;
    }

    /**
     * Get regular
     *
     * @return boolean
     */
    public function getRegular()
    {
        return $this->regular;
    }

    /**
     * Set often_buying_eggs
     *
     * @param integer $oftenBuyingEggs
     * @return Purchaser
     */
    public function setOftenBuyingEggs($oftenBuyingEggs)
    {
        $this->often_buying_eggs = $oftenBuyingEggs;

        return $this;
    }

    /**
     * Get often_buying_eggs
     *
     * @return integer
     */
    public function getOftenBuyingEggs()
    {
        return $this->often_buying_eggs;
    }

    /**
     * Add fowls
     *
     * @param \Gallinas\AppBundle\Entity\Fowl $fowls
     * @return Purchaser
     */
    public function addFowl(\Gallinas\AppBundle\Entity\Fowl $fowls)
    {
        $this->fowls[] = $fowls;

        return $this;
    }

    /**
     * Remove fowls
     *
     * @param \Gallinas\AppBundle\Entity\Fowl $fowls
     */
    public function removeFowl(\Gallinas\AppBundle\Entity\Fowl $fowls)
    {
        $this->fowls->removeElement($fowls);
    }

    /**
     * Get fowls
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getFowls()
    {
        return $this->fowls;
    }


    /**
     * devuelve el total de dinero que se ha gastado.
     */
    public function getPurchasePrice()
    {
        $amount=0;
        foreach ($this->getPurchases() as $purchase)
        {
            $amount+=$purchase->getTotalPrice();
        }

        return $amount;
    }
}
