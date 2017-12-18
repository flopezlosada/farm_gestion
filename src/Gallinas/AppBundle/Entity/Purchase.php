<?php
/**
 * Representa las compras de productos que hacemos
 * User: paco
 * Date: 4/11/14
 * Time: 12:31
 */

namespace Gallinas\AppBundle\Entity;


use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Purchase
 *
 * @ORM\Table(name="purchase")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\PurchaseRepository")
 */
class Purchase
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
     * @var string $purchase_date
     * @ORM\Column(name="purchase_date", type="date")
     */
    private $purchase_date;

    /**
     * Cantidad de producto comprado
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
     * @Assert\NotBlank
     * @var smallint $unity
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\Unity", inversedBy="purchases")
     */
    private $unity;

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
     * proveedor
     * @Assert\NotBlank
     * @var smallint $purchaser
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\Provider", inversedBy="purchases")
     */
    private $provider;


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
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\Product", inversedBy="purchases")
     */
    private $product;

    /**
     * Quién lo ha vendido?
     * @Assert\NotBlank
     * @var smallint $user
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\User", inversedBy="purchases")
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
     * está pagado el pedido?
     */
    private $paid = false;

    /**
     * @var int assigned
     * @ORM\Column(name="assigned", type="boolean", length=1, nullable=false, options={"default" = 0})
     * Cuando un pedido de pienso está completamente asignado a los lotes de animales, se cambia a true. Asignado quiere decir que se lo han comido.
     */
    private $assigned = false;

    /**
     * @var int counted
     * @ORM\Column(name="counted", type="boolean", length=1, nullable=true)
     * Esta variable nos sirve para saber si esta compra (de pienso) ha sido contabilizada. Es decir,cada vez que compramos pienso, debemos ver cuánto hay que pagar
     * Se mira el dinero que ha entrado en ventas de huevos y se reparte la diferencia según el consumo de huevos. (En el caso de pavos o pollos lo mismo)
     * Para ver las ventas, se usa las fechas de compra de pienso, las ventas que hay entre la última compra no contabilizada y la anterior
     */
    private $counted = false;


    /**
     * @ORM\OneToOne(targetEntity="EggRegistry", inversedBy="purchase")
     * @ORM\JoinColumn(name="egg_registry_id", referencedColumnName="id", nullable=true)
     **/
    private $egg_registry;


    /**
     * Determina si esta compra se asocia a un lote de animales como un coste de ese lote.
     * @var smallint $batch
     * @ORM\ManyToOne(targetEntity="Batch", inversedBy="purchases")
     */
    private $batch;

    /**
     * @ORM\OneToMany(targetEntity="Sack", mappedBy="purchase")
     */
    protected $sacks;


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
     * Set purchase_date
     *
     * @param \DateTime $purchaseDate
     * @return Purchase
     */
    public function setPurchaseDate($purchaseDate)
    {
        $this->purchase_date = $purchaseDate;

        return $this;
    }

    /**
     * Get purchase_date
     *
     * @return \DateTime
     */
    public function getPurchaseDate()
    {
        return $this->purchase_date;
    }

    /**
     * Set amount
     *
     * @param integer $amount
     * @return Purchase
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
     * @return Purchase
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
     * Set single_price
     *
     * @param string $singlePrice
     * @return Purchase
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
     * @return Purchase
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
     * Set created
     *
     * @param \DateTime $created
     * @return Purchase
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
     * @return Purchase
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
     * @return Purchase
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
     * @return Purchase
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
     * Set provider
     *
     * @param \Gallinas\AppBundle\Entity\Provider $provider
     * @return Purchase
     */
    public function setProvider(\Gallinas\AppBundle\Entity\Provider $provider = null)
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * Get provider
     *
     * @return \Gallinas\AppBundle\Entity\Provider
     */
    public function getProvider()
    {
        return $this->provider;
    }

    /**
     * Set product
     *
     * @param \Gallinas\AppBundle\Entity\Product $product
     * @return Purchase
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
     * @return Purchase
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

    public function getDate()
    {
        return $this->getPurchaseDate();
    }

    public function __toString()
    {
        if ($this->getProduct()=="Genérico"||$this->getProvider()=="Genérico")
        {
            $string = "Compra de " . $this->getNote();
        }
        else{
            $string = "Compra de " . $this->getAmount() . " " . $this->getUnity() . " de " . $this->getProduct() . " a " . $this->getProvider(). " " . $this->getNote();
        }

        if ($this->getAssigned())
        {
            $string .= ' (Completada)';
        }

        return $string;
    }

    /**
     * Set counted
     *
     * @param boolean $counted
     * @return Purchase
     */
    public function setCounted($counted)
    {
        $this->counted = $counted;

        return $this;
    }

    /**
     * Get counted
     *
     * @return boolean
     */
    public function getCounted()
    {
        return $this->counted;
    }

    /**
     * Set egg_registry
     *
     * @param \Gallinas\AppBundle\Entity\EggRegistry $eggRegistry
     * @return Purchase
     */
    public function setEggRegistry(\Gallinas\AppBundle\Entity\EggRegistry $eggRegistry = null)
    {
        $this->egg_registry = $eggRegistry;

        return $this;
    }

    /**
     * Get egg_registry
     *
     * @return \Gallinas\AppBundle\Entity\EggRegistry
     */
    public function getEggRegistry()
    {
        return $this->egg_registry;
    }

    /**
     * Set batch
     *
     * @param \Gallinas\AppBundle\Entity\Batch $batch
     * @return Purchase
     */
    public function setBatch(\Gallinas\AppBundle\Entity\Batch $batch = null)
    {
        $this->batch = $batch;

        return $this;
    }

    /**
     * Get batch
     *
     * @return \Gallinas\AppBundle\Entity\Batch
     */
    public function getBatch()
    {
        return $this->batch;
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->sacks = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * Add sacks
     *
     * @param \Gallinas\AppBundle\Entity\Sack $sacks
     * @return Purchase
     */
    public function addSack(\Gallinas\AppBundle\Entity\Sack $sacks)
    {
        $this->sacks[] = $sacks;

        return $this;
    }

    /**
     * Remove sacks
     *
     * @param \Gallinas\AppBundle\Entity\Sack $sacks
     */
    public function removeSack(\Gallinas\AppBundle\Entity\Sack $sacks)
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
     * Set assigned
     *
     * @param boolean $assigned
     * @return Purchase
     */
    public function setAssigned($assigned)
    {
        $this->assigned = $assigned;

        return $this;
    }

    /**
     * Get assigned
     *
     * @return boolean
     */
    public function getAssigned()
    {
        return $this->assigned;
    }

    /*
     *
     * devuelve la cantidad de kilos de pienso que tiene sin asignar a lotes
     */
    public function getAvailableAmount()
    {
        $amount_spent = 0; //cantidad gastada
        foreach ($this->getSacks() as $sack)
        {
            $amount_spent += $sack->getWeight();
        }

        return $this->getAmount() - $amount_spent;
    }

    /**
     * Set fowl
     *
     * @param \Gallinas\AppBundle\Entity\Fowl $fowl
     * @return Purchase
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
     * devuelve un string con el pedido y la cantidad que queda
     */
    public function getNameBatchSack()
    {
        return $this->getProduct()->__toString() . " de " . $this->getProvider() . " " . $this->getPurchaseDate()->format("d/m/Y")
        . " (" . $this->getAvailableAmount() . " " . $this->getUnity() . " disponibles)";

    }
}
