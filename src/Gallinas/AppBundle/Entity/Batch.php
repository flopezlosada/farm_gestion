<?php
/**
 * Lote de animales. Se contempla desde que nace hasta que se sacrifica y vende. Se compone de aves (fowl) en relación 1 a n
 * El estado del lote puede ser 'en producción' (por defecto) o terminado
 * User: paco
 * Date: 11/03/15
 * Time: 11:15
 */

namespace Gallinas\AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Gallinas\AppBundle\Entity\Batch
 *
 * @ORM\Table(name="batch")
 * @ORM\Entity(repositoryClass="Gallinas\AppBundle\Entity\BatchRepository")
 */
class Batch
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
     * Fecha de compra del pedido
     * @Assert\NotBlank
     * @Assert\Date()
     * @var string $purchase_date
     * @ORM\Column(name="purchase_date", type="date")
     */
    private $purchase_date;


    /**
     * Fecha de compra del pedido
     * @Assert\Date()
     * @var string $finalization_date
     * @ORM\Column(name="finalization_date", type="date", nullable=true)
     */
    private $finalization_date;


    /**
     * Fracha de recepción del pedido
     * @Assert\Date()
     * @var string $receipt_date
     * @ORM\Column(name="receipt_date", type="date")
     */
    private $receipt_date;


    /**
     * Días de vida del animal respecto a la fecha de recepción
     * @Assert\NotBlank
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $days_of_life
     * @ORM\Column(name="days_of_life",type="integer")
     */
    private $days_of_life;


    /**
     * Precio de compra del lote, de todos los animales más el transporte
     * @Assert\NotBlank
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $price
     * @ORM\Column(name="price",type="decimal", precision=8, scale=2)
     */
    private $price;

    /**
     * Coste total del lote. Se calcula automáticamente según los insumos, cada vez que se añade una compra al lote y al crear el lote.
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $cost
     * @ORM\Column(name="cost",type="decimal", precision=8, scale=2, nullable=true)
     */
    private $cost;

    /**
     * Beneficio final del lote. Se calcula automáticamente según las ventas, cada vez que se vende algo
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $profit
     * @ORM\Column(name="profit",type="decimal", precision=8, scale=2, nullable=true)
     */
    private $profit;


    /**
     * Cantidad de animales en el lote
     * @Assert\NotBlank
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $amount
     * @ORM\Column(name="amount",type="integer")
     */
    private $amount;


    /**
     * Peso aproximado del animal al recibirlo en kilogramos
     * @Assert\NotBlank
     * @Assert\Type(type="numeric", message="El valor {{value}} no es un {{type}} válido.")
     * @Assert\GreaterThan(
     *     value = 0
     * )
     * @var smallint $weight
     * @ORM\Column(name="weight",type="decimal", precision=8, scale=2)
     */
    private $weight;

    /**
     * qué tipo de animal es
     * @Assert\NotBlank
     * @var smallint $product
     * @ORM\ManyToOne(targetEntity="Gallinas\AppBundle\Entity\Product", inversedBy="batchs")
     */
    private $product;

    /**
     * Notas
     * @var string $note
     * @ORM\Column(name="note", type="text", nullable=true)
     */
    private $note;

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
     * @var smallint $batch_status
     * @ORM\ManyToOne(targetEntity="BatchStatus", inversedBy="batchs")
     */
    private $batch_status;


    /**
     * @ORM\OneToMany(targetEntity="Fowl", mappedBy="batch")
     */
    protected $fowls;

    /**
     * @ORM\OneToMany(targetEntity="Lay", mappedBy="batch")
     */
    protected $lays;

    /**
     * @ORM\OneToMany(targetEntity="Sack", mappedBy="batch")
     * @ORM\OrderBy({"delivery_date" = "ASC"})
     */
    protected $sacks;

    /**
     * Son los gastos asociados a este lote, como compras de  suministro.
     * @ORM\OneToMany(targetEntity="Purchase", mappedBy="batch")
     */
    protected $purchases;


    /**
     * Media del peso en vivo de los animales sacrificados
     */
    private $average_put_down_weight;

    /**
     * Media del peso en canal de los animales sacrificados
     */
    private $average_carcass_weight;


    /**
     * Total del peso en vivo de los animales sacrificados
     */
    private $total_put_down_weight;


    /**
     * Total del peso en canal de los animales sacrificados
     */
    private $total_carcass_weight;




    /**
     * Total de Animales sacrificados
     */
    private $put_down_total;



    /**
     * @return mixed
     */
    public function getTotalPutDownWeight()
    {
        return $this->total_put_down_weight;
    }

    /**
     * @param mixed $total_put_down_weight
     */
    public function setTotalPutDownWeight($total_put_down_weight)
    {
        $this->total_put_down_weight = $total_put_down_weight;
    }

    /**
     * @return mixed
     */
    public function getTotalCarcassWeight()
    {
        return $this->total_carcass_weight;
    }

    /**
     * @param mixed $total_carcass_weight
     */
    public function setTotalCarcassWeight($total_carcass_weight)
    {
        $this->total_carcass_weight = $total_carcass_weight;
    }


    /**
     * @return mixed
     */
    public function getPutDownTotal()
    {
        return $this->put_down_total;
    }

    /**
     * @param mixed $put_down_total
     */
    public function setPutDownTotal($put_down_total)
    {
        $this->put_down_total = $put_down_total;
    }


    /**
     * @return mixed
     */
    public function getAverageCarcassWeight()
    {
        return $this->average_carcass_weight;
    }

    /**
     * @param mixed $average_carcass_weight
     */
    public function setAverageCarcassWeight($average_carcass_weight)
    {
        $this->average_carcass_weight = $average_carcass_weight;
    }

    /**
     * @return mixed
     */
    public function getAveragePutDownWeight()
    {
        return $this->average_put_down_weight;
    }

    /**
     * @param mixed $average_put_down_weight
     *
     */
    public function setAveragePutDownWeight($average_put_down_weight)
    {
        $this->average_put_down_weight = $average_put_down_weight;

    }

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->fowls = new \Doctrine\Common\Collections\ArrayCollection();
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
     * Set purchase_date
     *
     * @param \DateTime $purchaseDate
     * @return Batch
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
     * Set receipt_date
     *
     * @param \DateTime $receiptDate
     * @return Batch
     */
    public function setReceiptDate($receiptDate)
    {
        $this->receipt_date = $receiptDate;

        return $this;
    }

    /**
     * Get receipt_date
     *
     * @return \DateTime
     */
    public function getReceiptDate()
    {
        return $this->receipt_date;
    }

    /**
     * Set days_of_life
     *
     * @param integer $daysOfLife
     * @return Batch
     */
    public function setDaysOfLife($daysOfLife)
    {
        $this->days_of_life = $daysOfLife;

        return $this;
    }

    /**
     * Get days_of_life
     *
     * @return integer
     */
    public function getDaysOfLife()
    {
        return $this->days_of_life;
    }

    /**
     * Set price
     *
     * @param string $price
     * @return Batch
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
     * Set cost
     *
     * @param string $cost
     * @return Batch
     */
    public function setCost($cost)
    {
        $this->cost = $cost;

        return $this;
    }

    /**
     * Get cost
     *
     * @return string
     */
    public function getCost()
    {
        return $this->cost;
    }

    /**
     * Set profit
     *
     * @param string $profit
     * @return Batch
     */
    public function setProfit($profit)
    {
        $this->profit = $profit;

        return $this;
    }

    /**
     * Get profit
     *
     * @return string
     */
    public function getProfit()
    {
        return $this->profit;
    }

    /**
     * Set amount
     *
     * @param integer $amount
     * @return Batch
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
     * Set weight
     *
     * @param string $weight
     * @return Batch
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
     * Set note
     *
     * @param string $note
     * @return Batch
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
     * Set created
     *
     * @param \DateTime $created
     * @return Batch
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
     * @return Batch
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
     * Set product
     *
     * @param \Gallinas\AppBundle\Entity\Product $product
     * @return Batch
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
     * Add fowls
     *
     * @param \Gallinas\AppBundle\Entity\Fowl $fowls
     * @return Batch
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
     * Add purchases
     *
     * @param \Gallinas\AppBundle\Entity\Purchase $purchases
     * @return Batch
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
     * @return Purchase
     */
    public function getPurchases()
    {
        return $this->purchases;
    }

    public function getDate()
    {
        return $this->getReceiptDate();
    }

    public function __toString()
    {
        /*if (is_object($this->getPurchaseDate()))
        {
            echo var_dump($this->getPurchaseDate());
        }

        return "1";*/
        $string = "Lote nº: " . $this->getId() . " en estado " . strtolower($this->getBatchStatus()) . " de " . $this->getAmount() . " " . strtolower($this->getProduct()) . "s";

        if (is_object($this->getPurchaseDate()))
        {
            $string .= " comprado el " . $this->getPurchaseDate()->format('d-m-Y');
        }

        return $string;
    }

    /**
     * Set batch_status
     *
     * @param \Gallinas\AppBundle\Entity\BatchStatus $batchStatus
     * @return Batch
     */
    public function setBatchStatus(\Gallinas\AppBundle\Entity\BatchStatus $batchStatus = null)
    {
        $this->batch_status = $batchStatus;

        return $this;
    }

    /**
     * Get batch_status
     *
     * @return \Gallinas\AppBundle\Entity\BatchStatus
     */
    public function getBatchStatus()
    {
        return $this->batch_status;
    }

    public function getTotalCost()
    {
        $cost = $this->getPrice();
        foreach ($this->getSacks() as $sack)
        {
            $cost += $sack->getTotalPrice();
        }

        return $cost;
    }

    public function getTotalIncome()
    {
        return $this->getIncomeFormDestination(1) + $this->getIncomeFormDestination(2) + $this->getIncomeFormDestination(3);
    }

    public function getIncomeFormDestination($fowl_destination)
    {
        $income = 0;
        foreach ($this->getFowls() as $fowl)
        {
            if ($fowl->getFowlDestination() && $fowl->getFowlDestination()->getId() == $fowl_destination)
            {
                if ($fowl->getFowlDestination()->getId() == 1)
                {
                    $income += $fowl->getCarcassWeight() * $fowl->getSalePrice();
                } else
                {
                    $income += $fowl->getCarcassWeight() * $this->getAveragePrice();
                }
            }
        }

        return $income;
    }

    /**
     * devuelve el precio medio del kilo de carne del lote
     */
    public function getAveragePrice()
    {
        $a = 0;
        $price_sum = 0;
        foreach ($this->getFowls() as $fowl)
        {
            if ($fowl->getFowlDestination() && $fowl->getFowlDestination()->getId() == 1)
            {
                $a++;
                $price_sum += $fowl->getSalePrice();
            }
        }

        if ($a > 0)
        {
            return $price_sum / $a;
        }

        return 0;

    }

    public function getTotalIncomeGifts()
    {
        $income = 0;
        foreach ($this->getFowls() as $fowl)
        {
            if ($fowl->getFowlDestination() && $fowl->getFowlDestination()->getId() == 2)
            {
                $income += $fowl->getCarcassWeight() * $this->getAveragePrice();
            }
        }

        return $income;
    }

    public function getTotalProfit()
    {
        return $this->getTotalIncome() - $this->getTotalCost();
    }

    public function getTotalProfitWithGifts()
    {
        return $this->getTotalIncome() + $this->getTotalIncomeGifts() - $this->getTotalCost();
    }


    /**
     * se trata de determinar cuántos kilos en canal se han vendido
     */
    public function getTotalWeightSale()
    {
        $weight = 0;
        foreach ($this->getFowls() as $fowl)
        {
            if ($fowl->getFowlDestination() && $fowl->getFowlDestination()->getId() == 1)
            {
                $weight += $fowl->getCarcassWeight();
            }
        }

        return $weight;
    }

    /**
     * se trata de determinar cuántos kilos en canal se han regalado
     */
    public function getTotalWeightGifted()
    {
        $weight = 0;
        foreach ($this->getFowls() as $fowl)
        {
            if ($fowl->getFowlDestination() && $fowl->getFowlDestination()->getId() == 2)
            {
                $weight += $fowl->getCarcassWeight();
            }
        }

        return $weight;
    }

    /**
     * total de kilos en canal vendidos + regalados (por lo general el regalo es por una deuda, así que lo sumo para ver los beneficios reales, que incluyen la amortización de la deuda)
     * Total de kilos válidos para beneficio (excluye básicamente lo que nos comemos, ya que el resto se considera un gasto)
     */

    public function getTotalWeightForProfit()
    {
        return $this->getTotalWeightSale() + $this->getTotalWeightGifted();
    }

    /**
     * devuelve el precio al que habría que haber vendido en caso de que los beneficios sean negativos, para conseguir
     * beneficio cero.
     */
    public function getBalancePrice()
    {
        if ($this->getTotalWeightSale() && $this->getTotalProfit() < 0)
        {
            return number_format(abs($this->getTotalCost() / $this->getTotalWeightSale()), 2);
        }

        return null;
    }


    /**
     * devuelve el precio al que habría que haber vendido en caso de que los beneficios sean negativos, para conseguir
     * beneficio cero, incluyendo la amortización de la deuda
     */
    public function getBalancePriceWithGifts()
    {
        if ($this->getTotalWeightForProfit() && $this->getTotalProfit() < 0)
        {
            return number_format(abs($this->getTotalCost() / $this->getTotalWeightForProfit()), 2);
        }

        return null;
    }

    /**
     * @return integer
     * Devuelve el pienso consumido en este lote
     */
    public function  getFeedAmount()
    {
        $amount = null;
        foreach ($this->getSacks() as $sack)
        {
            $amount += $sack->getWeight();
        }

        return $amount;
    }

    /**
     * Add sacks
     *
     * @param \Gallinas\AppBundle\Entity\Sack $sacks
     * @return Batch
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
     * se puede cerrar un lote?
     * solo si todos sus animales tienen puesto su destino (comprobamos la fecha de muerte, si la tienen todos, bien)
     */
    public function canClose()
    {
        foreach ($this->getFowls() as $fowl)
        {
            if ($fowl->getPutDownDate() == null)
            {
                return false;
            }
        }

        return true;
    }

    /**
     * según el tipo de animal del lote, así será el pienso consumido
     */
    public function getSackProductId()
    {
        if ($this->getProduct()->getId() == 2)
        {
            return 5;
        }
        if ($this->getProduct()->getId() == 3)
        {
            return 5;
        }
        if ($this->getProduct()->getId() == 6)
        {
            return 4;
        }
    }

    public function getBatchAge()
    {
        if ($this->getBatchStatus()->getId() == 1)
        {
            $diff = $this->getPurchaseDate()->diff(new \DateTime());
        } else if ($this->getBatchStatus()->getId() == 2)
        {
            $diff = $this->getPurchaseDate()->diff($this->getFinalizationDate());
        }

        return $diff->days + $this->getDaysOfLife();
    }

    public function getProductionTime()
    {
        if ($this->getBatchStatus()->getId() == 1)
        {
            $diff = $this->getPurchaseDate()->diff(new \DateTime());
        } else if ($this->getBatchStatus()->getId() == 2)
        {
            $diff = $this->getPurchaseDate()->diff($this->getFinalizationDate());
        }

        return $diff->days;
    }


    /**
     * Set finalizationDate
     *
     * @param \DateTime $finalizationDate
     *
     * @return Batch
     */
    public function setFinalizationDate($finalizationDate)
    {
        $this->finalization_date = $finalizationDate;

        return $this;
    }

    /**
     * Get finalizationDate
     *
     * @return \DateTime
     */
    public function getFinalizationDate()
    {
        return $this->finalization_date;
    }

    /**
     * devuelve el consumo diario en gramos de comida por animal
     */
    public function getAverageFeedAmount()
    {
        return $this->getFeedAmount() * 1000 / ($this->getAmount() * $this->getProductionTime());
    }

    /**
     * índice de conversión del lote completo.
     */
    public function getFeedConversion()
    {
        $total_carcass_weight = 0;
        foreach ($this->getFowls() as $fowl)
        {
            if ($fowl->getFowlStatus()->getId() == 4)
            {
                $total_carcass_weight += $fowl->getCarcassWeight();
            }
        }
        if ($total_carcass_weight > 0)
        {
            return $this->getFeedAmount() / $total_carcass_weight;
        }

        return 0;

    }


    /**
     * Add lay
     *
     * @param \Gallinas\AppBundle\Entity\Lay $lay
     *
     * @return Batch
     */
    public function addLay(\Gallinas\AppBundle\Entity\Lay $lay)
    {
        $this->lays[] = $lay;

        return $this;
    }

    /**
     * Remove lay
     *
     * @param \Gallinas\AppBundle\Entity\Lay $lay
     */
    public function removeLay(\Gallinas\AppBundle\Entity\Lay $lay)
    {
        $this->lays->removeElement($lay);
    }

    /**
     * Get lays
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getLays()
    {
        return $this->lays;
    }

    public function getNameBatchLay()
    {
        return "Lote nº " . $this->getId();
        //." de " . $this->getAmount() . " de " . $this->getProduct() . " comprado el día " . $this->getPurchaseDate()->format(("d/m/Y"));
    }

    public function getTotalLayEggs()
    {
        if ($this->getProduct()->getId() == 6)
        {
            $amount = 0;
            foreach ($this->getLays() as $lay)
            {
                $amount += $lay->getAmount();
            }

            return $amount;
        }

        return null;
    }

    public function getEggCost()
    {
        if ($this->getProduct()->getId() == 6)
        {
            if ($this->getTotalLayEggs() > 0)
            {
                return $this->getTotalCost() / $this->getTotalLayEggs();
            }

            return 0;
        }

        return null;
    }

    public function getBalaceProduction($price)
    {
        if ($this->getProduct()->getId() == 6)
        {

            return $this->getTotalCost() * 12 / $price;


            return 0;
        }

        return null;
    }

}
