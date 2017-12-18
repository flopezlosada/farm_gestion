<?php
/**
 * Representa las personas a las que regalamos huevos, pollos y demás
 * User: paco
 * Date: 4/11/14
 * Time: 12:01
 */

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Recipient
 *
 * @ORM\Table(name="recipient")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\RecipientRepository")
 */
class Recipient
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
     * @ORM\OneToMany(targetEntity="Gallinas\AppBundle\Entity\Gift", mappedBy="recipient")
     */
    protected $gifts;


    /**
     * @ORM\OneToMany(targetEntity="Fowl", mappedBy="fowl_recipient")
     */
    protected $fowls;


    /**
     * Constructor
     */
    public function __construct()
    {
        $this->gifts = new \Doctrine\Common\Collections\ArrayCollection();
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
     * @return Recipient
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
     * @return Recipient
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
     * @return Recipient
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
     * Add gifts
     *
     * @param \Gallinas\AppBundle\Entity\Gift $gifts
     * @return Recipient
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

    public function __toString()
    {
        return $this->getName();
    }

    /**
     * Set regular
     *
     * @param boolean $regular
     * @return Recipient
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
     * @return Recipient
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
     * @return Recipient
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
    public function getGiftPrice()
    {
        $amount=0;
        foreach ($this->getGifts() as $gift)
        {
            $amount+=$gift->getTotalPrice();
        }

        return $amount;
    }
}
