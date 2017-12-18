<?php
/**
 * relación de huevos con una compra de pienso determinada. Se guarda los huevos puestos en ese período que representa la compra de pienso, los vendidos, etc.
 * Se relaciona con los usuarios a través de EggRegistryUser que guarda la cantidad de huevos que han cogido en el período
 * User: paco
 * Date: 27/11/14
 * Time: 17:24
 */

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Collect
 *
 * @ORM\Table(name="egg_registry")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\EggRegistryRepository")
 */
class EggRegistry
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
     * @ORM\OneToOne(targetEntity="Purchase", mappedBy="egg_registry")
     **/
    private $purchase;

    /**
     * Cantidad de huevos puestos referidos a la compra de pienso correspondiente, es decir entre la fecha de compra de este pienso y la de la siguiente
     * @Assert\NotBlank
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $total_lay_eggs
     * @ORM\Column(name="total_lay_eggs", type="integer", nullable=true)
     */
    private $total_lay_eggs;


    /**
     * Cantidad de huevos vendidos referidos a esta compra de pienso
     * @Assert\NotBlank
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $total_sale_eggs
     * @ORM\Column(name="total_sale_eggs", type="integer", nullable=true)
     */
    private $total_sale_eggs;

    /**
     * total de dinero recaudado con la venta de huevos
     * @var smallint $total_price
     * @ORM\Column(name="total_price",type="decimal", precision=8, scale=2)
     */
    private $total_price;


    /**
     * @Assert\NotBlank
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $total_gift_eggs
     * @ORM\Column(name="total_gift_eggs", type="integer", nullable=true)
     */
    private $total_gift_eggs;

    /**
     * @Assert\NotBlank
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $total_collected_eggs
     * @ORM\Column(name="total_collected_eggs", type="integer", nullable=true)
     */
    private $total_collected_eggs;

    /**
     * La cantidad de huevos que se desconoce dónde han ido a parar. Debería ser cero, pero...
     * @Assert\NotBlank
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $total_unknown_eggs
     * @ORM\Column(name="total_unknown_eggs", type="integer", nullable=true)
     */
    private $total_unknown_eggs;

    /**
     * @ORM\OneToMany(targetEntity="Gallinas\AppBundle\Entity\EggRegistryUser", mappedBy="egg_registry")
     */
    protected $egg_registry_users;
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->egg_registry_users = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set total_lay_eggs
     *
     * @param integer $totalLayEggs
     * @return EggRegistry
     */
    public function setTotalLayEggs($totalLayEggs)
    {
        $this->total_lay_eggs = $totalLayEggs;

        return $this;
    }

    /**
     * Get total_lay_eggs
     *
     * @return integer 
     */
    public function getTotalLayEggs()
    {
        return $this->total_lay_eggs;
    }

    /**
     * Set total_sale_eggs
     *
     * @param integer $totalSaleEggs
     * @return EggRegistry
     */
    public function setTotalSaleEggs($totalSaleEggs)
    {
        $this->total_sale_eggs = $totalSaleEggs;

        return $this;
    }

    /**
     * Get total_sale_eggs
     *
     * @return integer 
     */
    public function getTotalSaleEggs()
    {
        return $this->total_sale_eggs;
    }

    /**
     * Set total_price
     *
     * @param string $totalPrice
     * @return EggRegistry
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
     * Set total_gift_eggs
     *
     * @param integer $totalGiftEggs
     * @return EggRegistry
     */
    public function setTotalGiftEggs($totalGiftEggs)
    {
        $this->total_gift_eggs = $totalGiftEggs;

        return $this;
    }

    /**
     * Get total_gift_eggs
     *
     * @return integer 
     */
    public function getTotalGiftEggs()
    {
        return $this->total_gift_eggs;
    }

    /**
     * Set total_collected_eggs
     *
     * @param integer $totalCollectedEggs
     * @return EggRegistry
     */
    public function setTotalCollectedEggs($totalCollectedEggs)
    {
        $this->total_collected_eggs = $totalCollectedEggs;

        return $this;
    }

    /**
     * Get total_collected_eggs
     *
     * @return integer 
     */
    public function getTotalCollectedEggs()
    {
        return $this->total_collected_eggs;
    }

    /**
     * Set total_unknown_eggs
     *
     * @param integer $totalUnknownEggs
     * @return EggRegistry
     */
    public function setTotalUnknownEggs($totalUnknownEggs)
    {
        $this->total_unknown_eggs = $totalUnknownEggs;

        return $this;
    }

    /**
     * Get total_unknown_eggs
     *
     * @return integer 
     */
    public function getTotalUnknownEggs()
    {
        return $this->total_unknown_eggs;
    }

    /**
     * Set purchase
     *
     * @param \Gallinas\AppBundle\Entity\Purchase $purchase
     * @return EggRegistry
     */
    public function setPurchase(\Gallinas\AppBundle\Entity\Purchase $purchase = null)
    {
        $this->purchase = $purchase;

        return $this;
    }

    /**
     * Get purchase
     *
     * @return \Gallinas\AppBundle\Entity\Purchase 
     */
    public function getPurchase()
    {
        return $this->purchase;
    }

    /**
     * Add egg_registry_users
     *
     * @param \Gallinas\AppBundle\Entity\EggRegistryUser $eggRegistryUsers
     * @return EggRegistry
     */
    public function addEggRegistryUser(\Gallinas\AppBundle\Entity\EggRegistryUser $eggRegistryUsers)
    {
        $this->egg_registry_users[] = $eggRegistryUsers;

        return $this;
    }

    /**
     * Remove egg_registry_users
     *
     * @param \Gallinas\AppBundle\Entity\EggRegistryUser $eggRegistryUsers
     */
    public function removeEggRegistryUser(\Gallinas\AppBundle\Entity\EggRegistryUser $eggRegistryUsers)
    {
        $this->egg_registry_users->removeElement($eggRegistryUsers);
    }

    /**
     * Get egg_registry_users
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getEggRegistryUsers()
    {
        return $this->egg_registry_users;
    }
}
